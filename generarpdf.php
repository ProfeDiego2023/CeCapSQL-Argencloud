<?php
// Seguridad básica
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit('Acceso no permitido');
}

// Sanitizar datos
$datos = [
    'nombre'      => $_POST['nombre'] ?? '',
    'dni'         => $_POST['dni'] ?? '',
    'fecha_nac'   => $_POST['fecha_nac'] ?? '',
    'tramite'     => $_POST['tramite'] ?? [],
    'declaracion' => isset($_POST['declaracion'])
];

// Pasar datos a la plantilla
require 'plantilla_pdf.php';
