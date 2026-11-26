<?php
session_start();
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/auditar.php";
proteger();

$conn->set_charset("utf8mb4");

header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["ok" => false, "msg" => "Método no permitido."]);
    exit;
}

if (!permiso('registrar') && !permiso('eliminar')) {
    echo json_encode(["ok" => false, "msg" => "No tenés permiso para eliminar registros."]);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(["ok" => false, "msg" => "ID inválido."]);
    exit;
}

// Obtener datos del registro para auditoría
$st = $conn->prepare("SELECT apellidos, nombres, dni FROM asistencias WHERE id = ? AND oculto = 0 LIMIT 1");
$st->bind_param("i", $id);
$st->execute();
$reg = $st->get_result()->fetch_assoc();
$st->close();

if (!$reg) {
    echo json_encode(["ok" => false, "msg" => "El registro no existe o ya fue eliminado."]);
    exit;
}

// Ocultar (soft delete)
$stDel = $conn->prepare("UPDATE asistencias SET oculto = 1 WHERE id = ? LIMIT 1");
$stDel->bind_param("i", $id);
if ($stDel->execute()) {
    $stDel->close();
    $detalle = "Registro eliminado: ID $id - " . ($reg['apellidos'] ?? '') . " " . ($reg['nombres'] ?? '') . " (DNI: " . ($reg['dni'] ?? '') . ")";
    auditar($conn, "ELIMINAR", "asistencias", $detalle);
    echo json_encode(["ok" => true, "msg" => "Registro eliminado correctamente."]);
} else {
    echo json_encode(["ok" => false, "msg" => "Error al eliminar: " . $conn->error]);
}

$conn->close();
