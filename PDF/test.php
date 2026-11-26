<?php
require __DIR__ . '/../vendor/autoload.php';

/* ================= ERRORES ================= */
error_reporting(E_ALL);
ini_set('display_errors', 1);
use setasign\Fpdi\Fpdi;

$pdf = new Fpdi();
$pdf->AddPage();
$pdf->SetFont('Arial', '', 12);
$pdf->Text(20, 20, 'FPDF + FPDI funcionando correctamente');

$pdf->Output('I', 'test.pdf');
