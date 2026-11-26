<?php
include(__DIR__."/conexion.php");

function logAuditoria($conn, $usuario_id, $accion, $descripcion = "")
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Desconocida';
    $stmt = $conn->prepare("INSERT INTO auditoria (usuario_id, accion, descripcion, ip) VALUES (?,?,?,?)");
    $stmt->bind_param("isss", $usuario_id, $accion, $descripcion, $ip);
    $stmt->execute();
}
?>
