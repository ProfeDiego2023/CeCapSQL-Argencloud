<?php
session_start();
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/auditoria_fn.php";

proteger();

if (!permiso("certificados")) {
    http_response_code(403);
    echo json_encode(["ok" => false, "msg" => "No autorizado"]);
    exit;
}

$id     = (int)($_POST['id'] ?? 0);
$estado = $_POST['estado'] ?? '';

$permitidos = ['ORIGINAL', 'DUPLICADO', 'TRIPLICADO'];

if ($id <= 0 || !in_array($estado, $permitidos, true)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "msg" => "Datos inválidos"]);
    exit;
}

$sql = "UPDATE asistencias
        SET estado_certificado = ?
        WHERE id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("si", $estado, $id);
$stmt->execute();

if ($stmt->affected_rows > 0) {

    // 🧾 Auditoría
    auditar(
        $conn,
        "CERTIFICADO_" . $estado,
        "asistencias_id=" . $id
    );

    echo json_encode(["ok" => true]);
} else {
    echo json_encode(["ok" => false, "msg" => "Sin cambios"]);
}
