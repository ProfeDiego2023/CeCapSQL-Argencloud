<?php
session_start();
date_default_timezone_set("America/Argentina/Buenos_Aires");
require_once __DIR__ . "/conexion.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: formulario_qr.php");
    exit;
}

/* ==============================
   DATOS DEL ASISTENTE (POST)
================================ */
$apellidos = mb_strtoupper(trim($_POST['apellidos'] ?? ''), 'UTF-8');
$nombres   = mb_strtoupper(trim($_POST['nombres']   ?? ''), 'UTF-8');
$dni       = preg_replace('/\D/', '', $_POST['dni'] ?? '');
$genero    = mb_strtoupper(trim($_POST['genero']    ?? ''), 'UTF-8');
$edad      = (int) ($_POST['edad'] ?? 0);
$email     = strtolower(trim($_POST['email'] ?? ''));

/* ==============================
   LUGAR / TURNO / LICENCIA
   Se releem del motor — NO del POST
   para evitar manipulación
================================ */
$auto  = require __DIR__ . "/motor_eventos.php";
$lugar = $auto['lugar'];
$turno = $auto['turno'];
$categ = $auto['categoria'];

$hoy  = date("Y-m-d");
$hora = date("H:i:s");

$stmt = $conn->prepare("
    SELECT lugar, turno, licencia, hora_desde, hora_hasta
    FROM config_form_eventos
    WHERE activo = 1
      AND fecha_desde <= ?
      AND fecha_hasta >= ?
    ORDER BY id DESC
");
$stmt->bind_param("ss", $hoy, $hoy);
$stmt->execute();
$res = $stmt->get_result();

while ($e = $res->fetch_assoc()) {
    if ($e['hora_desde'] && ($hora < $e['hora_desde'] || $hora > $e['hora_hasta'])) {
        continue;
    }
    if ($e['lugar'])   $lugar = $e['lugar'];
    if ($e['turno'])   $turno = $e['turno'];
    if ($e['licencia'])$categ = $e['licencia'];
}
$stmt->close();

/* ==============================
   VALIDACIÓN
================================ */
if ($apellidos === '' || $nombres === '' || $dni === '' || $genero === '' || $email === '') {
    $_SESSION['form_error'] = "Todos los campos son obligatorios.";
    header("Location: formulario_qr.php");
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['form_error'] = "Ingresá un email válido.";
    header("Location: formulario_qr.php");
    exit;
}

if (strlen($dni) < 6 || strlen($dni) > 10) {
    $_SESSION['form_error'] = "DNI inválido.";
    header("Location: formulario_qr.php");
    exit;
}

if ($edad < 16 || $edad > 99) {
    $_SESSION['form_error'] = "La edad debe estar entre 16 y 99 años.";
    header("Location: formulario_qr.php");
    exit;
}

/* ==============================
   CONTROL DE DUPLICADO
================================ */
$check = $conn->prepare("
    SELECT id FROM asistencias
    WHERE dni = ? AND fecha_dia = ?
    LIMIT 1
");
$check->bind_param("ss", $dni, $hoy);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    $_SESSION['form_error'] = "Este DNI ya fue registrado en el turno de hoy.";
    header("Location: formulario_qr.php");
    exit;
}
$check->close();

/* ==============================
   CÓDIGO ÚNICO 12 CARACTERES
================================ */
function generarCodigo(mysqli $conn): string {
    do {
        $codigo = strtoupper(bin2hex(random_bytes(6))); // 12 chars
        $stmt   = $conn->prepare("SELECT id FROM asistencias WHERE codigo_seguridad = ? LIMIT 1");
        $stmt->bind_param("s", $codigo);
        $stmt->execute();
        $stmt->store_result();
        $existe = $stmt->num_rows > 0;
        $stmt->close();
    } while ($existe);
    return $codigo;
}

$codigo = generarCodigo($conn);

/* ==============================
   FECHA COMPLETA
================================ */
$fecha_dia = $hoy;
$fecha_sql = $hoy . " " . $hora;

/* ==============================
   INSERT
================================ */
$stmt = $conn->prepare("
    INSERT INTO asistencias
        (fecha, fecha_dia, apellidos, nombres, dni,
         genero, edad, lugar, email, turno, licencia, codigo_seguridad)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param(
    "ssssssisssss",
    $fecha_sql,
    $fecha_dia,
    $apellidos,
    $nombres,
    $dni,
    $genero,
    $edad,
    $lugar,
    $email,
    $turno,
    $categ,
    $codigo
);

if ($stmt->execute()) {
    $_SESSION['form_ok'] = "¡Registro exitoso! Bienvenido/a, $apellidos.";
} else {
    $_SESSION['form_error'] = "Error al registrar. Intentá de nuevo.";
}
$stmt->close();

header("Location: formulario_qr.php");
exit;