<?php
function escribirPorMapa($pdf, $texto, array $mapa) {
    $texto = strtoupper($texto);
    $chars = preg_split('//u', $texto, -1, PREG_SPLIT_NO_EMPTY);

    foreach ($mapa as $i => $pos) {
        if (!isset($chars[$i])) {
            continue;
        }

        $pdf->SetXY($pos['x'], $pos['y']);
        $pdf->Text($pos['x'], $pos['y'], $chars[$i]);
    }
}
