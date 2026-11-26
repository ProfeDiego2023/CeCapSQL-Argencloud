<?php
session_start();

require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/auditar.php";

proteger();

if (!permiso("eliminar")) {
    die("Acceso denegado");
}

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    die("ID inválido");
}

/* Verificar que exista y no esté ya oculto */
$stmt = $conn->prepare("SELECT id FROM asistencias WHERE id = ? AND oculto = 0 LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    $stmt->close();
    die("Registro inexistente o ya oculto");
}
$stmt->close();

/* Ocultar */
$stmt = $conn->prepare("UPDATE asistencias SET oculto = 1 WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->close();

/* Auditoría — 4 parámetros: conn, accion, tabla, detalle */
auditar($conn, "OCULTAR", "asistencias", "ID ocultado: $id");

header("Location: buscar_asistentes.php");
exit;