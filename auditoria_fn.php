<?php
function auditar($conn, $accion, $modulo = null, $detalle = null) {

    if (empty($_SESSION['id_instru'])) {
        return;
    }

    $sql = "INSERT INTO auditoria
            (id_usuario, accion, modulo, detalle, ip, fecha)
            VALUES (?, ?, ?, ?, ?, NOW())";

    $st = $conn->prepare($sql);
    if (!$st) {
        return;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $id = (int)$_SESSION['id_instru'];

    $st->bind_param(
        "issss",
        $id,
        $accion,
        $modulo,
        $detalle,
        $ip
    );
    $st->execute();
}
