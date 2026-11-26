<?php
$s = "AUTOM\u{00D3}VIL";
echo bin2hex($s) . "\n";        // debe dar: 4155544f4dc3935 36494c
echo mb_detect_encoding($s) . "\n";

$bd = "AUTOM\xC3\x93VIL";      // simulamos lo que viene de BD
echo bin2hex($bd) . "\n";
echo ($s === $bd ? "IGUALES" : "DISTINTOS") . "\n";
?>