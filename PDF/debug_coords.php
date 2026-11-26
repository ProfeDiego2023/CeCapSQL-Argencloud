<?php

/* ================= ERRORES ================= */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/../vendor/autoload.php';

use setasign\Fpdi\Fpdi;

$pdf = new Fpdi();
$pdf->AddPage();

$pdf->setSourceFile(__DIR__ . '/plantillas/licencia_frente_fpdi.pdf');
$tpl = $pdf->importPage(1);
$pdf->useTemplate($tpl);

// Grilla
$pdf->SetFont('Arial', '', 6);
for ($x = 0; $x <= 210; $x += 10) {
    for ($y = 0; $y <= 297; $y += 10) {
        $pdf->Text($x, $y, "$x,$y");
    }
}

$pdf->Output('I', 'debug.pdf');
