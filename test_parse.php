<?php
$valor = "25/07/2024 07:11:48";

$formatos = [
    "j/n/Y H:i:s",
    "j/m/Y H:i:s", 
    "d/m/Y H:i:s",
];

foreach ($formatos as $fmt) {
    $dt = DateTime::createFromFormat($fmt, $valor);
    $errors = DateTime::getLastErrors();
    echo "$fmt → " . ($dt ? $dt->format("Y-m-d") : "FALSE");
    echo " | warnings: " . $errors['warning_count'];
    echo " | errors: " . $errors['error_count'] . "<br>";
}

echo "<br>PHP version: " . PHP_VERSION;
?>