<?php
$pruebas = [
    "4/02/2026 19:53:00",
    "5/02/2026 19:59:00",
    "9/02/2026 0:00:00",
    "18/02/2026 20:26:00",
    "2/03/2026 13:52:00",
    "23/10/2025 13:29:24",
];

$formatos = [
    "j/n/Y H:i:s",
    "j/m/Y H:i:s",
    "d/n/Y H:i:s",
    "d/m/Y H:i:s",
    "j/n/Y G:i:s",
    "j/m/Y G:i:s",
    "d/n/Y G:i:s",
    "d/m/Y G:i:s",
];

foreach ($pruebas as $valor) {
    $encontrado = false;
    foreach ($formatos as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $valor);
        if ($dt !== false) {
            echo "✅ '$valor' → formato '$fmt' → " . $dt->format("Y-m-d") . "<br>";
            $encontrado = true;
            break;
        }
    }
    if (!$encontrado) {
        echo "❌ '$valor' → NINGÚN FORMATO LO PARSEÓ<br>";
    }
}
?>
<?php
// Simulá exactamente lo que hace el importador
$archivo = "C:\Users\Diego\Downloads\asistencias.csv"; // ← cambiá por la ruta real del CSV
$handle = fopen($archivo, "r");

// Detectar delimitador
$linea = fgets($handle);
$delimitador = (substr_count($linea, ";") > substr_count($linea, ",")) ? ";" : ",";
rewind($handle);

// Leer cabeceras
$cabeceras = fgetcsv($handle, 0, $delimitador);
echo "Cabeceras detectadas:<br>";
foreach ($cabeceras as $i => $col) {
    echo "$i: '$col'<br>";
}

// Leer primeras 3 filas con fechas de febrero 2026
$count = 0;
while (($fila = fgetcsv($handle, 0, $delimitador)) !== false && $count < 5) {
    $fecha = isset($fila[0]) ? trim($fila[0]) : "VACÍO";
    if (str_contains($fecha, "/02/2026") || str_contains($fecha, "/03/2026")) {
        echo "Fecha cruda: '" . htmlspecialchars($fecha) . "' — largo: " . strlen($fecha) . "<br>";
        $count++;
    }
}
fclose($handle);
?>