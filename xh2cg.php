<?php


xh2cg::main();


class xh2cg {
    const COMPRESSION = false;


    public static function main() {
        global $argv;

        if (realpath(getcwd().'/'.$argv[0]) == realpath(__FILE__)) {
            // Invokes as main program, perform conversion.
            error_log('convert');
            if (empty($argv[1]) || !file_exists($argv[1])) {
                error_log('Unable to locate xhprof data file');
                exit(1);
            }
            if (!$file = file_get_contents($argv[1])) {
                error_log('Unable to load data file');
                exit(1);
            }
            if (!$xhprof = json_decode($file)) {
                error_log('Unable to decode data file');
                exit(1);
            }
            if (file_exists($argv[1].'.map')) {
                @$map = json_decode(file_get_contents($argv[1].'.map'), true);
            }

            self::convert($xhprof, $map);
            return;
        }

        // Included somewhere, run profiling.
        self::profile(true);
    }


    protected static function convert(stdClass $xhprof, $map = null) {
        if (!$fh = fopen('/tmp/callgrind.out.'.time().'.'.getmypid(), 'w')) {
            throw new Exception('Failed to open output file!');
        }

        $types = array('wt'=>'Time', 'cpu'=>'CPU', 'mu'=>'Memory', 'pmu'=>'Peak_memory');
        $totals = array();
        $funcs = array();

        foreach ($xhprof as $edge => $data) {
            list($fn, $cfn) = strpos($edge, '==>') !== false ? explode('==>', $edge, 2) : array($edge, null);

            if (!isset($funcs[$fn])) {
                $funcs[$fn] = new stdClass;
                $funcs[$fn]->children = array();
            }

            if (!isset($cfn)) {
                $funcs[$fn]->data = clone $data;
                continue;
            }

            $funcs[$fn]->children[$cfn] = clone $data;

            if (!isset($funcs[$cfn]->data)) {
                if (!isset($funcs[$cfn])) {
                    $funcs[$cfn] = new stdClass;
                    $funcs[$cfn]->children = array();
                }
                $funcs[$cfn]->data = clone $data;
                continue;
            }

            foreach ($data as $k => $v) {
                $funcs[$cfn]->data->$k += $v;
            }
        }

        foreach ($types as $k => $unused) {
            if (!isset($funcs['main()']->data->$k)) {
                unset($types[$k]);
                continue;
            }
            $totals[$k] = $funcs['main()']->data->$k;
        }

        // Calculate exclusive costs for each function.
        foreach ($funcs as $fn => $f) {
            foreach ($f->children as $cfn => $d) {
                foreach ($d as $k => $v) {
                    if ($k !== 'ct') {
                        $f->data->$k -= $v;
                    }
                }
            }
        }

        fwrite($fh, "version: 1\n");
        fwrite($fh, "creator: xh2cg for xhprof\n");
        fwrite($fh, "cmd: Unknown PHP script\n");
        fwrite($fh, "part: 1\n");
        fwrite($fh, "positions: line\n");
        fwrite($fh, "events: ".implode(" ", array_values($types))." \n");
        fwrite($fh, "summary: ".implode(" ", $totals)."\n");
        fwrite($fh, "\n\n");

        function comp($t, $v) {
            static $seen = array();
            if (!defined('COMPRESSION') || !COMPRESSION) {
                return $v;
            }
            if (!isset($seen[$t])) {
                $seen[$t] = array();
            }
            if (!isset($seen[$t][$v])) {
                $seen[$t][$v] = !empty($seen[$t]) ? reset($seen[$t]) + 1 : 1;
                return "({$seen[$t][$v]}) $v";
            }
            return "({$seen[$t][$v]})";
        }

        function map($map, $f) {
            $f = preg_replace('#@\d+$#', '', $f);
            $v = array('file' => '<unknown>', 'line' => 0);
            if (!empty($map[$f])) {
                $v = array_merge($v, $map[$f]);
            }
            return array_values($v);
        }

        function cline($d) {
            $cline = '';
            foreach ($d as $k => $v) {
                $cline .= $k !== 'ct' ? " {$v}" : '';
            }
            return $cline;
        }

        foreach ($funcs as $fn => $f) {
            list($fl, $fline) = map($map, $fn);
            if (!empty($fl)) {
                fwrite($fh, "fl=".comp('fl', $fl)."\n"); // file name
            }
            fwrite($fh, "fn=".comp('fn', $fn)."\n"); // function name
            fwrite($fh, "$fline ".cline($f->data)."\n"); // exclusive time
            foreach ($f->children as $cfn => $d) {
                list($cfl, $cfline) = map($map, $cfn);
                if ($cfl != $fl && !empty($cfl)) {
                    fwrite($fh, "cfl=".comp('cfl', $cfl)."\n"); // child function file name
                }
                fwrite($fh, "cfn=".comp('cfn', $cfn)."\n"); // child function name
                fwrite($fh, "calls={$d->ct} $cfline\n"); // called N times
                fwrite($fh, "$fline ".cline($d)."\n"); // lineno and cost
            }
            fwrite($fh, "\n");
        }

        fwrite($fh, "totals: ".cline($totals)."\n");
    }


    protected static function profile($convert) {
        if (!function_exists('xhprof_enable')) {
            error_log('missing xhprof');
            return false;
        }

        error_log('enabling xhprof');
        xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);

        global $argv;
        $main = $argv[0];

        register_shutdown_function(function () use ($convert, $main) {
            error_log('disabling xhprof');
            $xhprof = xhprof_disable();
            $funcs = array();
            foreach ($xhprof as $edge => $unused) {
                list($fn, $cfn) = strpos($edge, '==>') !== false ? explode('==>', $edge, 2) : array($edge, false);
                $funcs[$fn] = null;
                $funcs[$cfn] = null;
            }
            foreach ($funcs as $fn => $unused) {
                if (preg_match('#@\d+$#', $fn)) {
                    unset($funcs[$fn]);
                    continue;
                }
                try {
                    if (strpos($fn, '::') !== false) {
                        list($c, $f) = explode('::', $fn, 2);
                        $o = new ReflectionMethod($c, $f);
                    } else {
                        $o = new ReflectionFunction($fn);
                    }
                } catch (ReflectionException $e) {
                    unset($funcs[$fn]);
                    continue;
                }
                $file = $o->getFileName();
                $line = $o->getStartLine();
                if (!empty($CFG->dirroot)) {
                    $file = str_replace($CFG->dirroot, '', $file);
                }
                if (!empty($file) && !empty($line)) {
                    $funcs[$fn] = array('line' => $line, 'file' => $file);
                }
            }
            $funcs['main()'] = array('line' => 0, 'file' => $main);
            $funcs = array_filter($funcs);

            if ($convert) {
                self::convert(json_decode(json_encode($xhprof), false), $funcs);
                return;
            }

            file_put_contents('/tmp/xhprof.out.'.time().'.'.getmypid(), json_encode($xhprof, JSON_PRETTY_PRINT));
            file_put_contents('/tmp/xhprof.out.'.time().'.'.getmypid().'.map', json_encode($funcs, JSON_PRETTY_PRINT));

//            return array($xhprof, $funcs);
        });
    }
}
