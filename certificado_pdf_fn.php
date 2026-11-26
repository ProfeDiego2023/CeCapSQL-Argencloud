<?php
require_once __DIR__ . "/fpdf/fpdf.php";
require_once __DIR__ . "/instructor_fn.php";

function cert_t(?string $str): string
{
    return mb_convert_encoding($str ?? '', 'ISO-8859-1', 'UTF-8');
}

function cert_generarCodigoUnico(mysqli $conn): string
{
    do {
        $codigo = strtoupper(bin2hex(random_bytes(6)));
        $st     = $conn->prepare("SELECT id FROM asistencias WHERE codigo_seguridad = ? LIMIT 1");
        $st->bind_param("s", $codigo);
        $st->execute();
        $st->store_result();
    } while ($st->num_rows > 0);

    return $codigo;
}

function cert_cargarConfig(mysqli $conn): array
{
    $cfg = [
        'activo'  => false,
        'linea1'  => '',
        'linea2'  => '',
        'negrita' => false,
        'tamano'  => 10,
    ];

    $stCfg = $conn->prepare("SELECT activo, linea1, linea2, negrita, tamano FROM config_certificados WHERE id = 1");
    if (!$stCfg) {
        return $cfg;
    }

    $stCfg->execute();
    $row = $stCfg->get_result()->fetch_assoc();
    $stCfg->close();

    if (!$row) {
        return $cfg;
    }

    $cfg['activo']  = !empty($row['activo']) && !empty(trim($row['linea1'] ?? ''));
    $cfg['linea1']  = $row['linea1'] ?? '';
    $cfg['linea2']  = $row['linea2'] ?? '';
    $cfg['negrita'] = !empty($row['negrita']);
    $cfg['tamano']  = max(6, min(20, (int)($row['tamano'] ?? 10)));

    return $cfg;
}

function cert_asegurarCodigo(mysqli $conn, array &$registro): string
{
    if (!empty($registro['codigo_seguridad'])) {
        return $registro['codigo_seguridad'];
    }

    $codigo = cert_generarCodigoUnico($conn);
    $stUpd  = $conn->prepare("UPDATE asistencias SET codigo_seguridad = ? WHERE id = ?");
    $stUpd->bind_param("si", $codigo, $registro['id']);
    $stUpd->execute();
    $stUpd->close();
    $registro['codigo_seguridad'] = $codigo;

    return $codigo;
}

function cert_dibujarCertificado(
    FPDF $pdf,
    mysqli $conn,
    array $registro,
    string $instructor,
    array $msgCfg,
    float $yBase,
    float $margenX,
    float $ancho,
    float $altoCert,
    bool $marcarDuplicado = false,
    string $tipoDuplicado = '',
    string $firmaArchivo = ''
): string {
    $logo       = __DIR__ . "/img/logo_certificado.png";
    $logoExiste = file_exists($logo);

    $pdf->Rect($margenX, $yBase - 10, $ancho, $altoCert - 5);

    if ($logoExiste) {
        $pdf->Image($logo, $margenX + 5, $yBase - 10, 30);
    }

    if ($marcarDuplicado && $tipoDuplicado !== '') {
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->SetTextColor(200, 0, 0);
        $pdf->SetXY($margenX, $yBase - 2);
        $pdf->Cell($ancho, 10, cert_t($tipoDuplicado), 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
    }

    $codigo = cert_asegurarCodigo($conn, $registro);

    $pdf->SetFont('Arial', '', 11);
    $pdf->SetXY($margenX + $ancho - 40, $yBase - 8);
    $pdf->Cell(35, 8, $codigo, 0, 0, 'R');

    $pdf->SetFont('Arial', 'B', 16);
    $pdf->SetXY($margenX, $yBase + 5);
    $pdf->Cell($ancho, 10, cert_t("CERTIFICADO DE ASISTENCIA"), 0, 1, 'C');

    $pdf->SetFont('Arial', '', 11);
    $pdf->SetXY($margenX + 8, $yBase + 30);

    $texto = "Se certifica que el/la Sr/a "
        . strtoupper($registro['apellidos'] . " " . $registro['nombres'])
        . ", DNI " . $registro['dni']
        . ", asistió al curso de "
        . strtoupper($registro['licencia'])
        . " de formación vial para conductores, dictado por la Municipalidad de Esperanza.";

    $pdf->MultiCell($ancho - 16, 7, cert_t($texto), 0, 'C');

    if (!empty($msgCfg['activo'])) {
        $msgPadding = 2.5;
        $msgAncho   = $ancho - 24;
        $msgX       = $margenX + 12;
        $altoLinea  = $msgCfg['tamano'] * 0.55;
        $yMsg       = $pdf->GetY() + 2;
        $lineas     = 0;
        if ($msgCfg['linea1'] !== '') $lineas++;
        if ($msgCfg['linea2'] !== '') $lineas++;
        $altoRect   = $lineas * $altoLinea + $msgPadding * 2;
        $pdf->Rect($msgX, $yMsg - $msgPadding, $msgAncho, $altoRect);
        $pdf->SetFont('Arial', !empty($msgCfg['negrita']) ? 'B' : '', $msgCfg['tamano']);
        if ($msgCfg['linea1'] !== '') {
            $pdf->SetXY($msgX, $yMsg);
            $pdf->Cell($msgAncho, $altoLinea, cert_t($msgCfg['linea1']), 0, 1, 'C');
            $yMsg = $pdf->GetY();
        }
        if ($msgCfg['linea2'] !== '') {
            $pdf->SetXY($msgX, $yMsg);
            $pdf->Cell($msgAncho, $altoLinea, cert_t($msgCfg['linea2']), 0, 1, 'C');
        }
    }

    $yLinea     = $yBase + $altoCert - 25;
    $anchoLinea = 60;
    $posXLinea  = $margenX + 10;

    /* Firma digital del instructor (pegada a la línea de firma) */
    $firmaH = 0;
    if ($firmaArchivo !== '') {
        $rutaFirma = inst_firmaRutaJpeg($firmaArchivo);
        if ($rutaFirma !== '') {
            $info = @getimagesize($rutaFirma);
            if ($info && ($info[0] ?? 0) > 0) {
                $wMax = 42;
                $hMax = 14;
                $ratio = $info[1] / $info[0];
                $w = $wMax;
                $h = $w * $ratio;
                if ($h > $hMax) {
                    $h = $hMax;
                    $w = $h / $ratio;
                }
                $xImg = $posXLinea + ($anchoLinea - $w) / 2;
                $yImg = $yLinea - 1 - $h;
                try {
                    $pdf->Image($rutaFirma, $xImg, $yImg, $w, $h);
                } catch (Throwable $e) {
                }
                $firmaH = $h;
            }
        }
    }

    /* Nombre del instructor superpuesto a la firma, misma línea */
    if ($instructor !== '') {
        $pdf->SetFont('Arial', 'B', 9);
        $yNombre = ($firmaH > 0) ? ($yLinea - 1 - ($firmaH + 5) / 2) : ($yLinea - 10);
        $pdf->SetXY($posXLinea, $yNombre);
        $pdf->Cell($anchoLinea, 5, cert_t($instructor), 0, 0, 'C');
    }

    for ($i = 0; $i < $anchoLinea; $i += 2) {
        $pdf->Line($posXLinea + $i, $yLinea, $posXLinea + $i + 1, $yLinea);
    }

    $pdf->SetFont('Arial', '', 10);
    $pdf->SetXY($posXLinea, $yLinea + 2);
    $pdf->Cell($anchoLinea, 5, cert_t("Instructor"), 0, 0, 'C');

    $pdf->SetXY($margenX + $ancho - 80, $yLinea + 2);
    $pdf->Cell(70, 5, cert_t("Emitido el " . date("d/m/Y")), 0, 0, 'R');

    return $codigo;
}

/**
 * Genera un PDF con uno o más certificados (3 por hoja).
 *
 * @param array<int, array<string, mixed>> $registros
 */
function cert_generarPdfLote(
    mysqli $conn,
    array $registros,
    string $instructor,
    bool $marcarDuplicado = false,
    string $tipoDuplicado = '',
    string $firmaArchivo = ''
): string {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(false);

    $altoCert = 95;
    $margenX  = 15;
    $ancho    = 180;
    $yInicio  = 20;
    $contador = 0;
    $msgCfg   = cert_cargarConfig($conn);
    $codigos  = [];

    foreach ($registros as $registro) {
        if ($contador % 3 === 0) {
            $pdf->AddPage();
            $yBase = $yInicio;
        } else {
            $yBase = $yInicio + $altoCert * ($contador % 3);
        }

        $codigos[] = cert_dibujarCertificado(
            $pdf,
            $conn,
            $registro,
            $instructor,
            $msgCfg,
            $yBase,
            $margenX,
            $ancho,
            $altoCert,
            $marcarDuplicado,
            $tipoDuplicado,
            $firmaArchivo
        );

        $contador++;
    }

    $pdf->SetTitle("Certificados de Asistencia");
    $pdf->SetAuthor("Municipalidad de Esperanza");
    $pdf->SetSubject("Formación Vial - DRC0475");
    $pdf->SetKeywords(implode(", ", $codigos));

    return $pdf->Output('S');
}

/**
 * Genera un PDF con un solo certificado (para envío por email).
 */
function cert_generarPdfIndividual(
    mysqli $conn,
    array $registro,
    string $instructor,
    bool $marcarDuplicado = false,
    string $tipoDuplicado = '',
    string $firmaArchivo = ''
): string {
    return cert_generarPdfLote($conn, [$registro], $instructor, $marcarDuplicado, $tipoDuplicado, $firmaArchivo);
}
