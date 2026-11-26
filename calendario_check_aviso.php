<?php
session_start();
if (!isset($_SESSION['id_instru'])) {
    http_response_code(403);
    exit('No autorizado');
}

require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/calendario_notificaciones_fn.php";

calNotif_asegurarTablas($conn);

// Procesar avisos pendientes
calNotif_procesarPendientes($conn);

// Verificar si hay evento próximo para mostrar modal
$evento = calNotif_eventoModalActivo($conn);

if ($evento) {
    $fechaEvento = date('d/m/Y', strtotime($evento['fecha_desde']));
    if ($evento['fecha_desde'] !== $evento['fecha_hasta']) {
        $fechaEvento .= ' al ' . date('d/m/Y', strtotime($evento['fecha_hasta']));
    }
    $hora = '';
    if (!empty($evento['hora_desde'])) {
        $hora = ' de ' . substr($evento['hora_desde'], 0, 5) . ' a ' . substr($evento['hora_hasta'], 0, 5) . ' hs';
    }

    header('Content-Type: application/json');
    echo json_encode([
        'evento' => [
            'id'          => (int)$evento['id'],
            'titulo'      => $evento['titulo'],
            'descripcion' => $evento['descripcion'] ?? '',
            'fecha'       => $fechaEvento,
            'hora'        => $hora,
            'lugar'       => $evento['lugar'],
        ]
    ]);
} else {
    header('Content-Type: application/json');
    echo json_encode(['evento' => null]);
}
