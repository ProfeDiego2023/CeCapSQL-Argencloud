<?php
session_start();

/* ================= ERRORES ================= */
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ================= INCLUDES ================= */
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/fpdf/fpdf.php";

/* ================= SEGURIDAD ================= */
if (!isset($_SESSION['id_instru'])) {
    die("Acceso no autorizado");
}

/* ================= HELPERS ================= */

/**
 * Convierte string UTF-8 a ISO-8859-1 para FPDF.
 */
function t(?string $str): string {
    return mb_convert_encoding($str ?? '', 'ISO-8859-1', 'UTF-8');
}

/**
 * Genera un código de seguridad único de 8 caracteres verificando en BD.
 * Usa el mismo algoritmo que procesar_importacion.php.
 */
function generarCodigoUnico(mysqli $conn): string {
    do {
        $codigo = strtoupper(bin2hex(random_bytes(6))); // 12 chars hex
        $st     = $conn->prepare(
            "SELECT id FROM asistencias WHERE codigo_seguridad = ? LIMIT 1"
        );
        $st->bind_param("s", $codigo);
        $st->execute();
        $st->store_result();
    } while ($st->num_rows > 0);

    return $codigo;
}

/* ================= LEER REGISTROS SELECCIONADOS ================= */
/*
 * El listado envía name="registros[]" con value="DNI|FECHA"
 * Ej: "46997578|2025-11-03"
 *
 * También soporta:
 *  - $_GET['solo_id']  → certificado individual desde el panel
 */

$pares = []; // array de [dni, fecha_dia]

/* Desde listado (POST con formato dni|fecha) */
if (!empty($_POST['registros']) && is_array($_POST['registros'])) {
    foreach ($_POST['registros'] as $val) {
        $partes = explode('|', $val);
        if (count($partes) === 2) {
            $dni   = preg_replace('/\D/', '', trim($partes[0]));
            $fecha = trim($partes[1]);
            // Validar formato fecha básico
            if ($dni !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
                $pares[] = [$dni, $fecha];
            }
        }
    }
}

/* Individual por ID (desde panel u otro origen) */
$soloId = isset($_GET['solo_id']) ? (int)$_GET['solo_id'] : 0;

if (empty($pares) && $soloId === 0) {
    die("<h3 style='color:red;'>⚠️ No se seleccionaron registros.</h3>");
}

/* ================= DUPLICADO / TRIPLICADO ================= */
$tipoDuplicado   = strtoupper($_GET['tipo_dup'] ?? $_POST['tipo_dup'] ?? '');
$marcarDuplicado = in_array($tipoDuplicado, ['DUPLICADO', 'TRIPLICADO'], true);
if (!$marcarDuplicado) $tipoDuplicado = '';

/* ================= INSTRUCTOR ================= */
require_once __DIR__ . "/instructor_fn.php";
$instructorId = (int)($_POST['instructor_id'] ?? $_GET['instructor_id'] ?? 0);
$nombreSesion = trim((string)($_SESSION['nombre'] ?? ''));
[$instructor, $firmaArchivo] = inst_resolver($conn, $instructorId, $nombreSesion, (int)($_SESSION['id_instru'] ?? 0));

/* ================= CONSULTA ================= */

if ($soloId > 0) {

    /* ── Certificado individual por ID ── */
    $sql  = "
        SELECT apellidos, nombres, dni, licencia, turno, fecha_dia, id,
               codigo_seguridad
        FROM asistencias
        WHERE id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $soloId);
    $stmt->execute();
    $res = $stmt->get_result();

} else {

    /* ── Múltiples registros por (dni, fecha_dia) ──
     *
     * Se construye: WHERE (dni=? AND fecha_dia=?) OR (dni=? AND fecha_dia=?) ...
     * para traer exactamente los registros seleccionados, sin importar
     * cuántas veces asistió esa persona en otras fechas.
     */
    $condiciones = implode(' OR ', array_fill(0, count($pares), '(dni=? AND fecha_dia=?)'));
    $sql = "
        SELECT apellidos, nombres, dni, licencia, turno, fecha_dia, id,
               codigo_seguridad
        FROM asistencias
        WHERE $condiciones
        ORDER BY apellidos, nombres
    ";

    $stmt  = $conn->prepare($sql);
    $tipos = str_repeat('ss', count($pares));
    $flat  = array_merge(...array_map(fn($p) => [$p[0], $p[1]], $pares));
    $stmt->bind_param($tipos, ...$flat);
    $stmt->execute();
    $res = $stmt->get_result();
}

if ($res->num_rows === 0) {
    die("⚠️ No se encontraron coincidencias en la base de datos.");
}

/* ================= PDF ================= */
class PDF extends FPDF {
    public array $codigos = [];
}

$pdf = new PDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(false);

$altoCert = 95;
$margenX  = 15;
$ancho    = 180;
$yInicio  = 20;
$contador = 0;

$logo       = __DIR__ . "/img/logo_certificado.png";
$logoExiste = file_exists($logo);

/* ================= CONFIGURACIÓN MENSAJE ================= */
$msgActivo  = false;
$msgLinea1  = '';
$msgLinea2  = '';
$msgNegrita = false;
$msgTamano  = 10;
$stCfg = $conn->prepare("SELECT activo, linea1, linea2, negrita, tamano FROM config_certificados WHERE id = 1");
if ($stCfg) {
    $stCfg->execute();
    $cfg = $stCfg->get_result()->fetch_assoc();
    $stCfg->close();
    if ($cfg) {
        $msgActivo  = !empty($cfg['activo']) && !empty(trim($cfg['linea1'] ?? ''));
        $msgLinea1  = $cfg['linea1'] ?? '';
        $msgLinea2  = $cfg['linea2'] ?? '';
        $msgNegrita = !empty($cfg['negrita']);
        $msgTamano  = max(6, min(20, (int)($cfg['tamano'] ?? 10)));
    }
}

/* ================= GENERAR CERTIFICADOS ================= */
while ($p = $res->fetch_assoc()) {

    if ($contador % 3 === 0) {
        $pdf->AddPage();
        $yBase = $yInicio;
    } else {
        $yBase = $yInicio + $altoCert * ($contador % 3);
    }

    /* Marco */
    $pdf->Rect($margenX, $yBase - 10, $ancho, $altoCert - 5);

    /* Logo */
    if ($logoExiste) {
        $pdf->Image($logo, $margenX + 5, $yBase - 10, 30);
    }

    /* DUPLICADO / TRIPLICADO */
    if ($marcarDuplicado) {
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->SetTextColor(200, 0, 0);
        $pdf->SetXY($margenX, $yBase - 2);
        $pdf->Cell($ancho, 10, t($tipoDuplicado), 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
    }

    /* ── Código de seguridad ──────────────────────────────────────
     * Si el registro ya tiene código se reutiliza (no se sobreescribe).
     * Solo se genera uno nuevo si no tenía.
     */
    if (!empty($p['codigo_seguridad'])) {
        $codigo = $p['codigo_seguridad'];
    } else {
        $codigo = generarCodigoUnico($conn);
        $stUpd  = $conn->prepare(
            "UPDATE asistencias SET codigo_seguridad = ? WHERE id = ?"
        );
        $stUpd->bind_param("si", $codigo, $p['id']);
        $stUpd->execute();
        $stUpd->close();
    }

    $pdf->codigos[] = $codigo;

    $pdf->SetFont('Arial', '', 11);
    $pdf->SetXY($margenX + $ancho - 40, $yBase - 8);
    $pdf->Cell(35, 8, $codigo, 0, 0, 'R');

    /* Título */
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->SetXY($margenX, $yBase + 5);
    $pdf->Cell($ancho, 10, t("CERTIFICADO DE ASISTENCIA"), 0, 1, 'C');

    /* Cuerpo */
    $pdf->SetFont('Arial', '', 11);
    $pdf->SetXY($margenX + 8, $yBase + 30);

    $texto = "Se certifica que el/la Sr/a "
        . strtoupper($p['apellidos'] . " " . $p['nombres'])
        . ", DNI " . $p['dni']
        . ", asistió al curso de "
        . strtoupper($p['licencia'])
        . " de formación vial para conductores, dictado por la Municipalidad de Esperanza.";

    $pdf->MultiCell($ancho - 16, 7, t($texto), 0, 'C');

    /* Mensaje personalizado (debajo del texto) */
    if ($msgActivo) {
        $msgPadding = 2.5;
        $msgAncho   = $ancho - 24;
        $msgX       = $margenX + 12;
        $altoLinea  = $msgTamano * 0.55;
        $yMsg       = $pdf->GetY() + 2;
        $lineas     = 0;
        if ($msgLinea1 !== '') $lineas++;
        if ($msgLinea2 !== '') $lineas++;
        $altoRect   = $lineas * $altoLinea + $msgPadding * 2;
        $pdf->Rect($msgX, $yMsg - $msgPadding, $msgAncho, $altoRect);
        $pdf->SetFont('Arial', $msgNegrita ? 'B' : '', $msgTamano);
        if ($msgLinea1 !== '') {
            $pdf->SetXY($msgX, $yMsg);
            $pdf->Cell($msgAncho, $altoLinea, t($msgLinea1), 0, 1, 'C');
            $yMsg = $pdf->GetY();
        }
        if ($msgLinea2 !== '') {
            $pdf->SetXY($msgX, $yMsg);
            $pdf->Cell($msgAncho, $altoLinea, t($msgLinea2), 0, 1, 'C');
        }
    }

    /* Firma */
    $yLinea    = $yBase + $altoCert - 25;
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
    if ($instructor) {
        $pdf->SetFont('Arial', 'B', 9);
        $yNombre = ($firmaH > 0) ? ($yLinea - 1 - ($firmaH + 5) / 2) : ($yLinea - 10);
        $pdf->SetXY($posXLinea, $yNombre);
        $pdf->Cell($anchoLinea, 5, t($instructor), 0, 0, 'C');
    }

    for ($i = 0; $i < $anchoLinea; $i += 2) {
        $pdf->Line($posXLinea + $i, $yLinea, $posXLinea + $i + 1, $yLinea);
    }

    $pdf->SetFont('Arial', '', 10);
    $pdf->SetXY($posXLinea, $yLinea + 2);
    $pdf->Cell($anchoLinea, 5, t("Instructor"), 0, 0, 'C');

    $pdf->SetXY($margenX + $ancho - 80, $yLinea + 2);
    $pdf->Cell(70, 5, t("Emitido el " . date("d/m/Y")), 0, 0, 'R');

    $contador++;
}

/* ================= METADATOS PDF ================= */
$pdf->SetTitle("Certificados de Asistencia");
$pdf->SetAuthor("Municipalidad de Esperanza");
$pdf->SetSubject("Formación Vial - DRC0475");
$pdf->SetKeywords(implode(", ", $pdf->codigos));

/* ================= SALIDA ================= */
$pdf->Output("I", "certificados_" . date("Ymd_His") . ".pdf");