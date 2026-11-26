<?php
session_start();

require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/fpdf/fpdf.php";

/* ================= SEGURIDAD ================= */
if (!isset($_SESSION['id_instru'])) {
    die("Acceso no autorizado");
}

/* ================= HELPER UTF-8 → ISO ================= */
function t(?string $str): string {
    return mb_convert_encoding($str ?? '', 'ISO-8859-1', 'UTF-8');
}

/* ================= TIPOS DE CURSO ================= */
$tiposCurso = [
    ''                        => 'Dejar en blanco',
    'MOTOVEHÍCULOS'           => 'MOTOVEHÍCULOS',
    'AUTOMÓVILES'             => 'AUTOMÓVILES',
    'PROFESIONAL'             => 'PROFESIONAL',
    'RCP'                     => 'RCP',
    'AUTOMÓVIL+MOTOVEHÍCULO'  => 'AUTOMÓVIL + MOTOVEHÍCULO',
    'ESCUELAS/COLEGIOS'       => 'ESCUELAS/COLEGIOS',
];

$tipoBlanco = '______________________________';

/* ================= INSTRUCTORES ================= */
$instructores = [
    ''              => 'Dejar en blanco',
    'IVAN CARDOSO'  => 'IVAN CARDOSO',
    'DIEGO CARAVACA' => 'DIEGO CARAVACA',
];

/* =====================================================================
   PANTALLA DE ELECCIÓN DE TIPO DE CURSO E INSTRUCTOR
   Si no viene el parámetro "tipo", se muestra el selector para imprimir.
===================================================================== */
if (!array_key_exists('tipo', $_GET)) {
    $nombreSesion   = strtoupper(trim((string)($_SESSION['nombre'] ?? '')));
    $instructorDef  = array_key_exists($nombreSesion, $instructores) ? $nombreSesion : '';
    $previewIni     = "Se certifica que el/la Sr/a ___________________________, DNI ______________, asistió al curso de ";
    $previewFin     = " de formación vial para conductores, dictado por la Municipalidad de Esperanza.";
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Certificados Vacíos — CeCap</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/variables.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
* { box-sizing: border-box; }
body {
    background: linear-gradient(135deg, #504254, #3C313F);
    background-attachment: fixed;
    min-height: 100vh;
    color: #F0EDF2;
    font-family: 'Segoe UI', sans-serif;
    padding: 0 0 40px;
}
.topbar {
    background: rgba(255,255,255,.08);
    backdrop-filter: blur(16px);
    border-bottom: 1px solid rgba(255,255,255,.13);
    padding: 12px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky; top: 0; z-index: 100;
}
.topbar h4 { margin: 0; font-weight: 700; }
.glass {
    background: rgba(255,255,255,.10);
    backdrop-filter: blur(14px);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 20px;
    box-shadow: 0 20px 50px rgba(0,0,0,.50);
    padding: 28px;
}
.form-select {
    background: rgba(0,0,0,.35) !important;
    border: 1px solid rgba(255,255,255,.22) !important;
    color: #fff !important;
    border-radius: 12px !important;
    padding: 12px 16px !important;
    font-size: 1rem;
}
.form-select option {
    background: var(--select-option-bg);
    color: #fff;
}
.form-select:focus {
    border-color: #5D5E70 !important;
    box-shadow: 0 0 0 2px rgba(93,94,112,.25) !important;
}
label { color: #C7E4CF; font-size: .88rem; font-weight: 600; }
.preview-box {
    background: rgba(0,0,0,.30);
    border: 1px dashed rgba(199,228,207,.45);
    border-radius: 12px;
    padding: 14px 18px;
    font-size: .95rem;
    line-height: 1.6;
    color: #E9F5EC;
}
.preview-box .curso {
    font-weight: 700;
    color: #DD4841;
    text-transform: uppercase;
}
.btn-imprimir {
    background: linear-gradient(135deg, #DD4841, #B03030);
    border: none;
    border-radius: 12px;
    padding: 12px 28px;
    font-weight: 700;
    font-size: 1rem;
    color: #fff;
    transition: transform .15s ease, box-shadow .15s ease;
}
.btn-imprimir:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(221,72,65,.4);
    color: #fff;
}
</style>
</head>
<body>

<div class="topbar">
    <a href="panel.php" class="btn btn-sm btn-secondary">← Panel</a>
    <h4>🖨️ Certificados Vacíos</h4>
    <div></div>
</div>

<div class="container mt-4" style="max-width:680px">
    <div class="glass">
        <h5 class="mb-1">Certificados vacíos</h5>
        <p class="text-muted small mb-3" style="color:var(--text-muted)">
            Elegí el tipo de curso y el instructor que se imprimirán en el certificado.
            En "Dejar en blanco" se imprime con el espacio subrayado para completar a mano.
        </p>

        <form method="get" action="certificados_vacios.php" id="formTipo">
            <div class="mb-3">
                <label for="tipoCurso" class="form-label">Curso</label>
                <select class="form-select" id="tipoCurso" name="tipo">
                    <?php foreach ($tiposCurso as $valor => $etiqueta): ?>
                        <option value="<?= htmlspecialchars($valor, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($etiqueta, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label for="instructor" class="form-label">Instructor</label>
                <select class="form-select" id="instructor" name="instructor">
                    <?php foreach ($instructores as $valor => $etiqueta): ?>
                        <option value="<?= htmlspecialchars($valor, ENT_QUOTES, 'UTF-8') ?>"
                            <?= ($instructorDef === $valor) ? 'selected' : '' ?>><?= htmlspecialchars($etiqueta, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-4">
                <label class="form-label">Vista previa del texto</label>
                <div class="preview-box" id="previewTexto">
                    <?= htmlspecialchars($previewIni, ENT_QUOTES, 'UTF-8') ?>
                    <span class="curso"><?= htmlspecialchars($tipoBlanco, ENT_QUOTES, 'UTF-8') ?></span>
                    <?= htmlspecialchars($previewFin, ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label">Vista previa de la firma</label>
                <div class="preview-box" id="previewFirma" style="text-align:center">
                    <span class="curso" id="previewInstructor"><?= htmlspecialchars($instructorDef ?: $tipoBlanco, ENT_QUOTES, 'UTF-8') ?></span>
                    <p class="mb-0 mt-1 small" style="color:var(--text-subtle)">Instructor</p>
                </div>
            </div>

            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-imprimir">
                    <i class="bi bi-printer-fill me-1"></i> Generar e imprimir
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const mapaTipos = <?= json_encode($tiposCurso, JSON_UNESCAPED_UNICODE) ?>;
const tipoBlanco = "<?= $tipoBlanco ?>";
const sel = document.getElementById('tipoCurso');
const selIns = document.getElementById('instructor');
const preview = document.getElementById('previewTexto');
const previewIns = document.getElementById('previewInstructor');

function actualizarPreview() {
    const curso = (sel.value === '' ? tipoBlanco : sel.value);
    preview.innerHTML =
        "Se certifica que el/la Sr/a ___________________________, DNI ______________, asistió al curso de " +
        '<span class="curso">' + curso.replace(/&/g, '&amp;').replace(/</g, '&lt;') + "</span>" +
        " de formación vial para conductores, dictado por la Municipalidad de Esperanza.";

    previewIns.textContent = (selIns.value === '' ? tipoBlanco : selIns.value);
}

sel.addEventListener('change', actualizarPreview);
selIns.addEventListener('change', actualizarPreview);
</script>

</body>
</html>
    <?php
    exit;
}

/* =====================================================================
   GENERACIÓN DEL PDF
===================================================================== */

/* Valida y normaliza el tipo elegido */
$tipo = strtoupper(trim((string)$_GET['tipo']));
if ($tipo !== '' && !array_key_exists($tipo, $tiposCurso)) {
    $tipo = '';
}

/* Valida y normaliza el instructor elegido */
$instructorRaw = strtoupper(trim((string)($_GET['instructor'] ?? '')));
$instructor    = array_key_exists($instructorRaw, $instructores) ? $instructorRaw : '';

/* ================= DATOS FICTICIOS (CAMPOS EN BLANCO) ================= */
$p = [
    'apellidos'        => '___________________________',
    'nombres'          => '',
    'dni'              => '______________',
    'licencia'         => $tipo !== '' ? $tipo : $tipoBlanco,
    'turno'            => '',
    'fecha_dia'        => '',
    'id'               => 0,
    'codigo_seguridad' => '____________',
];

$instructor = strtoupper(trim($instructor));

/* ================= PDF (misma clase/config que el original) ================= */
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(false);

$altoCert = 95;
$margenX  = 15;
$ancho    = 180;
$yInicio  = 20;

$logo       = __DIR__ . "/img/logo_certificado.png";
$logoExiste = file_exists($logo);

$pdf->AddPage();

/* ── 3 certificados en blanco por hoja, igual que el original ── */
for ($contador = 0; $contador < 3; $contador++) {

    $yBase = $yInicio + $altoCert * $contador;

    /* Marco */
    $pdf->Rect($margenX, $yBase - 10, $ancho, $altoCert - 5);

    /* Logo */
    if ($logoExiste) {
        $pdf->Image($logo, $margenX + 5, $yBase - 10, 30);
    }

    /* Código de seguridad (en blanco) */
    $pdf->SetFont('Arial', '', 11);
    $pdf->SetXY($margenX + $ancho - 40, $yBase - 8);
    $pdf->Cell(35, 8, t($p['codigo_seguridad']), 0, 0, 'R');

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

    /* Firma */
    $yLinea     = $yBase + $altoCert - 25;
    $anchoLinea = 60;
    $posXLinea  = $margenX + 10;

    if ($instructor) {
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetXY($posXLinea, $yLinea - 10);
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
}

/* ================= SALIDA ================= */
$pdf->SetTitle("Certificado de Asistencia - Modelo");
$pdf->SetAuthor("Municipalidad de Esperanza");
$pdf->Output("I", "certificado_modelo.pdf");