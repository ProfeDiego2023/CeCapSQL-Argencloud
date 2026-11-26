<?php
if (!function_exists('auditar')) {

function auditar($conn, $accion, $modulo, $detalle=""){

    if(!$conn) return;
    if(!isset($_SESSION['id_instru'])) return;

    $uid = (int)$_SESSION['id_instru'];
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '';

    $stmt = $conn->prepare("
        INSERT INTO auditoria
        (id_usuario,accion,modulo,detalle,ip)
        VALUES (?,?,?,?,?)
    ");

    if(!$stmt) return;

    $stmt->bind_param("issss",$uid,$accion,$modulo,$detalle,$ip);
    $stmt->execute();
}

}
