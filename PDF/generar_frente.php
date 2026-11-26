<?php
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/helpers.php';

use setasign\Fpdi\Fpdi;

$asistente = [
  'tipo_dni' => 'X',
  'tipo_lc' => 'X',
  'tipo_le' => 'X',
  'apellido' => 'DIEGORODOLFOCARAVACAPAZOSWQ',
  'nombre'   => 'MARIOAISIDOROACARLOSMALBERT',
  'dni'      => '29123456',
  'cuit'     => '20247224565',
  'fecha_nac' => '15041980',
  'sexo_m'   => 'X',
  'sexo_f'   => 'X',
  'pais'    => 'ARGENTINAZO',
  'nacionalidad' => 'ARGENTINAZA',
  'tel_fijo' => '0349612345',
  'tel_celular' => '0349612345',
  'email'    => 'mariogarciadc@caravaca.com',
  'grupo_sang' => 'O',
  'factor_sang' => '+',
  'donante_si' => 'X',
  'donante_no' => 'X',
  'localidad' => 'COMPLETARVEITISEISLETRASAS',
  'calle'     => 'COMPLETARDIECINUEVE',
  'numero'    => '1234',
  'torre'     => 'ABC',
  'piso'      => '567',  
  'depto'     => 'BCD',
  'calle_esquina' => 'COMPLETARDIECINUEVE',
  'entre_calle' => 'COMPLETARDIECINUEVE',
  'ycalle'      => 'COMPLETARDIECINUEVE',
  ];

$mapa = require __DIR__ . '/maps/frente.php';

$pdf = new Fpdi();
$pdf->AddPage();

// PDF base
$pdf->setSourceFile(__DIR__ . '/plantillas/licencia_frente_fpdi.pdf');
$tpl = $pdf->importPage(1);
$pdf->useTemplate($tpl);

// Fuente (la que mejor te quede)
$pdf->SetFont('Helvetica', 'B', 10);

// Escritura exacta

escribirPorMapa($pdf, $asistente['tipo_dni'], $mapa['tipo_dni']);
escribirPorMapa($pdf, $asistente['tipo_lc'], $mapa['tipo_lc']);
escribirPorMapa($pdf, $asistente['tipo_le'], $mapa['tipo_le']);
escribirPorMapa($pdf, $asistente['dni'], $mapa['dni']);
escribirPorMapa($pdf, $asistente['cuit'], $mapa['cuit']);
escribirPorMapa($pdf, $asistente['apellido'], $mapa['apellido']);
escribirPorMapa($pdf, $asistente['nombre'], $mapa['nombre']);
escribirPorMapa($pdf, $asistente['fecha_nac'], $mapa['fecha_nac']);

escribirPorMapa($pdf, $asistente['sexo_m'], $mapa['sexo_m']);
escribirPorMapa($pdf, $asistente['sexo_f'], $mapa['sexo_f']);
escribirPorMapa($pdf, $asistente['pais'], $mapa['pais']);
escribirPorMapa($pdf, $asistente['nacionalidad'], $mapa['nacionalidad']);
escribirPorMapa($pdf, $asistente['tel_fijo'], $mapa['tel_fijo']);
escribirPorMapa($pdf, $asistente['tel_celular'], $mapa['tel_celular']);
escribirPorMapa($pdf, $asistente['email'], $mapa['email']);

escribirPorMapa($pdf, $asistente['grupo_sang'], $mapa['grupo_sang']);
escribirPorMapa($pdf, $asistente['factor_sang'], $mapa['factor_sang']);
escribirPorMapa($pdf, $asistente['donante_si'], $mapa['donante_si']);
escribirPorMapa($pdf, $asistente['donante_no'], $mapa['donante_no']);
escribirPorMapa($pdf, $asistente['localidad'], $mapa['localidad']);
escribirPorMapa($pdf, $asistente['calle'], $mapa['calle']);
escribirPorMapa($pdf, $asistente['numero'], $mapa['numero']);   
escribirPorMapa($pdf, $asistente['torre'], $mapa['torre']);
escribirPorMapa($pdf, $asistente['piso'], $mapa['piso']);  
escribirPorMapa($pdf, $asistente['depto'], $mapa['depto']);
escribirPorMapa($pdf, $asistente['calle_esquina'], $mapa['calle_esquina']);
escribirPorMapa($pdf, $asistente['entre_calle'], $mapa['entre_calle']);
escribirPorMapa($pdf, $asistente['ycalle'], $mapa['ycalle']);

$pdf->Output('I', 'licencia_frente.pdf');
