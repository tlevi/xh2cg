<?php


if (!function_exists('xhprof_enable')) {
    error_log('missing xhprof');
    return;
}

error_log('enabling xhprof');
xhprof_enable();
global $argv;
$main = $argv[0];
register_shutdown_function(function () use ($main) {
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
    file_put_contents('/tmp/xhprof.out.'.getmypid(), json_encode($xhprof, JSON_PRETTY_PRINT));
    file_put_contents('/tmp/xhprof.out.map.'.getmypid(), json_encode($funcs, JSON_PRETTY_PRINT));
});

