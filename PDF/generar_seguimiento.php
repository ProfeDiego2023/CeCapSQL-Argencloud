<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../conexion.php';
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/helpers.php';

use setasign\Fpdi\Fpdi;

// 🔒 Validación de parámetro
if (!isset($_GET['id'])) {
    die('ID de asistente no válido');
}

$id = intval($_GET['id']);

// 🔎 Obtener datos del asistente
$sql = "SELECT apellidos, nombres, dni, turno 
        FROM asistentes 
        WHERE id = ?";

$stmt = $conexion->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$resultado = $stmt->get_result();

if ($resultado->num_rows === 0) {
    die('Asistente no encontrado');
}

$datos = $resultado->fetch_assoc();

// 📍 Cargar mapa
$mapa = require __DIR__ . '/maps/seguimiento.php';

// 🧾 PDF
$pdf = new Fpdi();
$pdf->AddPage();

$pdf->setSourceFile(__DIR__ . '/plantillas/seguimiento_tramite_fpdi.pdf');
$tpl = $pdf->importPage(1);
$pdf->useTemplate($tpl);

// Fuente
$pdf->SetFont('Helvetica', 'B', 10);

// Texto simple
$pdf->Text($mapa['apellido']['x'], $mapa['apellido']['y'], strtoupper($datos['apellidos']));
$pdf->Text($mapa['nombre']['x'],   $mapa['nombre']['y'],   strtoupper($datos['nombres']));
$pdf->Text($mapa['fecha']['x'],    $mapa['fecha']['y'],    date('d/m/Y'));

// DNI por casilleros
escribirPorMapa($pdf, $datos['dni'], $mapa['dni']);

// Nº de trámite (turno)
if (!empty($datos['turno'])) {
    $pdf->Text(
        $mapa['numero_tramite']['x'],
        $mapa['numero_tramite']['y'],
        $datos['turno']
    );
}

$pdf->Output('I', 'seguimiento_tramite.pdf');
