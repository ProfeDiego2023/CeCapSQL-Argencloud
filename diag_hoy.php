<?php
/* Diagnóstico temporal del contador "Asistentes Hoy". Borrar después. */
header("Content-Type: text/plain; charset=utf-8");
require_once __DIR__ . "/conexion.php";

function show($titulo, $valor) {
    echo "=== $titulo ===\n";
    echo is_string($valor) ? $valor . "\n\n" : json_encode($valor, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
}

function rows(mysqli $conn, string $sql): array {
    $r = $conn->query($sql);
    if (!$r) return ["ERROR" => $conn->error];
    $out = [];
    while ($f = $r->fetch_assoc()) $out[] = $f;
    return $out;
}

echo "TZ PHP       : " . date_default_timezone_get() . "\n";
echo "Fecha PHP    : " . date('Y-m-d H:i:s') . "\n\n";

show("MySQL (CURDATE / TZ / base)", rows($conn,
    "SELECT CURDATE() AS hoy_mysql, DATABASE() AS base,
            @@global.time_zone AS tz_global, @@session.time_zone AS tz_sesion"));

show("Tipo de columnas", rows($conn,
    "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='asistencias'
       AND COLUMN_NAME IN ('fecha','fecha_dia','oculto')"));

show("Conteos con distintos criterios", [
    "total_general" => rows($conn, "SELECT COUNT(*) c FROM asistencias WHERE oculto=0"),
    "hoy_solo_fecha_dia" => rows($conn, "SELECT COUNT(*) c FROM asistencias WHERE oculto=0 AND fecha_dia=CURDATE()"),
    "hoy_con_fallback" => rows($conn,
        "SELECT COUNT(*) c FROM asistencias
         WHERE COALESCE(NULLIF(DATE(fecha_dia),'0000-00-00'),NULLIF(DATE(fecha),'0000-00-00'))=CURDATE()"),
    "registros_oculto_null" => rows($conn, "SELECT COUNT(*) c FROM asistencias WHERE oculto IS NULL"),
]);

$hoy = date('Y-m-d');
$st = $conn->prepare(
    "SELECT COUNT(*) c FROM asistencias
     WHERE COALESCE(oculto,0)=0
       AND COALESCE(NULLIF(DATE(fecha_dia),'0000-00-00'),NULLIF(DATE(fecha),'0000-00-00'))=?"
);
$st->bind_param('s', $hoy);
$st->execute();
$c = 0;
$st->bind_result($c);
$st->fetch();
$st->close();
show("Hoy con fecha PHP ($hoy) + fallback + oculto NULL como activo", ["c" => $c]);

show("Ultimos 10 registros", rows($conn,
    "SELECT id, fecha, fecha_dia, oculto, apellidos
     FROM asistencias ORDER BY id DESC LIMIT 10"));
