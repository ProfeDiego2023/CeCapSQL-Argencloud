<?php
require_once('tcpdf/tcpdf.php');

$pdf = new TCPDF('P', 'mm', 'A4');
$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

// ===== FONDO ORIGINAL =====
$pdf->Image(
    'plantillas/frente_fondo.jpg',
    0,
    0,
    210,
    297
);

// Fuente
$pdf->SetFont('helvetica', '', 9);

// =========================
// DATOS PERSONALES
// =========================

// APELLIDO Y NOMBRE
$pdf->SetXY(30, 54);
$pdf->Cell(150, 5, $datos['nombre'], 0, 0);

// DNI
$pdf->SetXY(30, 61);
$pdf->Cell(50, 5, $datos['dni'], 0, 0);

// FECHA NACIMIENTO
$pdf->SetXY(130, 61);
$pdf->Cell(40, 5, $datos['fecha_nac'], 0, 0);

// =========================
// TIPO DE TRÁMITE
// =========================

function marcar($pdf, $x, $y, $cond) {
    if ($cond) {
        $pdf->SetXY($x, $y);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(5, 5, 'X', 0, 0, 'C');
        $pdf->SetFont('helvetica', '', 9);
    }
}

// ORIGINAL
marcar($pdf, 28, 88, in_array('Original', $datos['tramite']));

// RENOVACIÓN
marcar($pdf, 58, 88, in_array('Renovacion', $datos['tramite']));

// DUPLICADO
marcar($pdf, 92, 88, in_array('Duplicado', $datos['tramite']));

// =========================
// DECLARACIÓN JURADA
// =========================
marcar($pdf, 28, 132, $datos['declaracion']);

// =========================
// FECHA AUTOMÁTICA
// =========================
$pdf->SetXY(140, 270);
$pdf->Cell(40, 5, date('d/m/Y'), 0, 0);

// SALIDA
$pdf->Output('solicitud_licencia_frente.pdf', 'I');
