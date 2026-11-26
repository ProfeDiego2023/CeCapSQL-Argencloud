<?php
session_start();
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/auth.php";
proteger();

$conn->set_charset("utf8mb4");

header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["ok" => false, "msg" => "Solicitud inválida."]);
    exit;
}

/* ==============================
   HELPERS
================================ */
function limpiar(string $v): string {
    return mb_strtoupper(trim($v), 'UTF-8');
}

/* ==============================
   LEER DATOS
================================ */
$id        = (int)($_POST['id'] ?? 0);
$fecha     = trim($_POST['fecha']     ?? '');
$apellidos = limpiar($_POST['apellidos'] ?? '');
$nombres   = limpiar($_POST['nombres']   ?? '');
$dni       = preg_replace('/\D/', '', $_POST['dni'] ?? '');
$edad      = (int)($_POST['edad']     ?? 0);
$genero    = limpiar($_POST['genero']    ?? '');
$email     = strtolower(trim($_POST['email'] ?? ''));
$licencia  = limpiar($_POST['licencia']  ?? '');

/*
 * Lugar y turno:
 * El modal envía un campo hidden con el valor final (sea del select o del manual).
 * guardar_registro_manual puede enviar lugar_manual / turno_manual si eligió "otro".
 * Resolvemos con prioridad: manual > select > hidden.
 */
$lugar = limpiar(
    (!empty($_POST['lugar_manual']))
        ? $_POST['lugar_manual']
        : ($_POST['lugar'] ?? '')
);

$turnoRaw = (!empty($_POST['turno_manual']))
    ? $_POST['turno_manual']
    : ($_POST['turno'] ?? '');
$turno = limpiar($turnoRaw);

/* ==============================
   VALIDACIÓN
================================ */
if ($id <= 0) {
    echo json_encode(["ok" => false, "msg" => "ID de registro inválido."]);
    exit;
}

if ($fecha === '' || $dni === '') {
    echo json_encode(["ok" => false, "msg" => "Fecha y DNI son obligatorios."]);
    exit;
}

/* ==============================
   FECHA COMPLETA
================================ */
$fechaDt = DateTime::createFromFormat("Y-m-d", $fecha);
if (!$fechaDt) {
    echo json_encode(["ok" => false, "msg" => "Formato de fecha inválido."]);
    exit;
}

$fecha_dia = $fechaDt->format("Y-m-d");
$fecha_sql = $fecha_dia . " 00:00:00";

// turno_num: extraer número de hora del turno si tiene formato "HH:MM"
$turno_num = 0;
if (preg_match('/(\d{1,2}):(\d{2})/', $turno, $m)) {
    $turno_num = (int)$m[1];
}

/* ==============================
   CONTROL DE DUPLICADO
================================ */
$check = $conn->prepare("
    SELECT id FROM asistencias
    WHERE dni = ? AND fecha_dia = ? AND id != ?
    LIMIT 1
");
$check->bind_param("ssi", $dni, $fecha_dia, $id);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    echo json_encode(["ok" => false, "msg" => "Este asistente ya tiene un registro en esa fecha."]);
    exit;
}
$check->close();

/* ==============================
   UPDATE
================================ */
$sql = "
    UPDATE asistencias SET
        fecha      = ?,
        fecha_dia  = ?,
        apellidos  = ?,
        nombres    = ?,
        dni        = ?,
        edad       = ?,
        genero     = ?,
        lugar      = ?,
        email      = ?,
        licencia   = ?,
        turno      = ?,
        turno_num  = ?
    WHERE id = ?
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(["ok" => false, "msg" => "Error interno: " . $conn->error]);
    exit;
}

// Tipos: s s s s s i s s s s s i i
// fecha, fecha_dia, apellidos, nombres, dni = s
// edad = i
// genero, lugar, email, licencia, turno = s
// turno_num = i
// id = i
$stmt->bind_param(
    "sssssisssssii",
    $fecha_sql,
    $fecha_dia,
    $apellidos,
    $nombres,
    $dni,
    $edad,
    $genero,
    $lugar,
    $email,
    $licencia,
    $turno,
    $turno_num,
    $id
);

if ($stmt->execute()) {
    echo json_encode(["ok" => true, "msg" => "Registro actualizado correctamente."]);
} else {
    echo json_encode(["ok" => false, "msg" => "Error al actualizar: " . $stmt->error]);
}

$stmt->close();
$conn->close();