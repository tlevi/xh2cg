<?php

if (!function_exists('xhprof_enable')) {
    return;
}

error_log('enabling xhprof');
xhprof_enable();
register_shutdown_function(function () {
    global $argv;
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
    $funcs['main()'] = array('line' => 0, 'file' => $argv[0]);
    $funcs = array_filter($funcs);
    file_put_contents('/tmp/xhprof.out', json_encode($xhprof, JSON_PRETTY_PRINT));
    file_put_contents('/tmp/xhprof.out.map', json_encode($funcs, JSON_PRETTY_PRINT));
    if (file_exists('/usr/bin/kcachegrind')) {
        exec(__DIR__."/xh2cg /tmp/xhprof.out >/tmp/callgrind.out 2>/dev/null; /usr/bin/kcachegrind /tmp/callgrind.out &>/dev/null &");
    }
});

