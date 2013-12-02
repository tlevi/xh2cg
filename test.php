<?php


require_once __DIR__.'/xh2cg.php';


function slow() {
    $x = 0;
    for ($i=0; $i < 1000000; $i++) {
        $x += preg_match('#^\d+$#', $i);
    }
    return $x;
}


function sleepy() {
    $x = time();
    for ($i=0; $i < 3; $i++) {
        sleep(1);
    }
    return time() - $x;
}


function fatty() {
    static $x = array();
    $c = 0;
    for ($i = 0; $i < 1000; $i++){
        $x[$i] = array();
        for ($k = 0; $k <= $i; $k++) {
            $x[$i][$k] = ($k + 1) / ($i + 1);
        }
        $c += isset($x[$i][10]) ? $x[$i][10] : $x[$i][0];
    }
    return $c;
}


$a = slow();
$a += sleepy();
$a += fatty();


echo "$a\n";
