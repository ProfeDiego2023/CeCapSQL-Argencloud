<?php
session_start();

require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/conexion.php";

proteger();

if (!permiso("buscar")) {
    http_response_code(403);
    die("<div style='font-family:sans-serif;text-align:center;padding:60px;color:#fff;background:#111;min-height:100vh'>
        <h2>&#9940; Acceso denegado</h2>
        <p>No tenés permiso para acceder a esta sección.</p>
        <a href='panel.php' style='color:#5D5E70'>← Volver al panel</a>
    </div>");
}

$puedeEmitir   = permiso("certificados") ? 'true' : 'false';
$puedeEditar   = permiso("editar")       ? 'true' : 'false';
$puedeEliminar = permiso("eliminar")     ? 'true' : 'false';

/* Rango de fechas para impresión */
$rangoDesde = trim($_GET['desde'] ?? '');
$rangoHasta = trim($_GET['hasta'] ?? '');
$registrosRango = [];
if ($rangoDesde !== '' || $rangoHasta !== '') {
    $desdeN = null; $hastaN = null;
    if ($rangoDesde && preg_match('/(\\d{4})-(\\d{2})-(\\d{2})/', $rangoDesde)) $desdeN = $rangoDesde;
    if ($rangoHasta && preg_match('/(\\d{4})-(\\d{2})-(\\d{2})/', $rangoHasta)) $hastaN = $rangoHasta;
    $sql = "SELECT id, fecha_dia, apellidos, nombres, dni, edad, licencia, turno, lugar
            FROM asistencias WHERE oculto = 0";
    $params = []; $types = '';
    if ($desdeN) { $sql .= " AND fecha_dia >= ?"; $params[] = $desdeN; $types .= 's'; }
    if ($hastaN) { $sql .= " AND fecha_dia <= ?"; $params[] = $hastaN; $types .= 's'; }
    $sql .= " ORDER BY fecha_dia ASC, apellidos ASC, nombres ASC LIMIT 500";
    $st = $conn->prepare($sql);
    if ($params) $st->bind_param($types, ...$params);
    $st->execute();
    $rs = $st->get_result();
    while ($rr = $rs->fetch_assoc()) $registrosRango[] = $rr;
    $st->close();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header("Content-Type: application/json");
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode([]); exit; }

    $like = "%" . $q . "%";
    $stmt = $conn->prepare("
        SELECT id, fecha_dia, apellidos, nombres, dni,
               licencia, turno, lugar,
               codigo_seguridad, estado_certificado
        FROM asistencias
        WHERE oculto = 0
          AND (
              dni                 LIKE ?
              OR apellidos        LIKE ?
              OR nombres          LIKE ?
              OR codigo_seguridad LIKE ?
          )
        ORDER BY fecha_dia DESC
        LIMIT 100
    ");
    $stmt->bind_param("ssss", $like, $like, $like, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ================== MARCAR COMO ESCUELA (AJAX) ================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'marcar_escuela') {
    header("Content-Type: application/json");
    $ids = $_POST['ids'] ?? [];
    $escuela = trim($_POST['escuela_nombre'] ?? '');
    $horario = trim($_POST['horario'] ?? '');
    $instructor = trim($_POST['instructor'] ?? '');
    if (!is_array($ids) || empty($ids)) { echo json_encode(["ok"=>false,"msg"=>"Sin selección."]); exit; }
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids);
    if (empty($ids)) { echo json_encode(["ok"=>false,"msg"=>"Sin IDs válidos."]); exit; }
    $lugar = $escuela !== '' ? 'ESCUELA - ' . mb_strtoupper($escuela, 'UTF-8') : 'ESCUELA';
    $licencia = 'ESCUELAS/COLEGIOS';
    $turno = $horario !== '' ? mb_strtoupper($horario, 'UTF-8') : null;
    $inst = $instructor !== '' ? mb_strtoupper($instructor, 'UTF-8') : null;

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "UPDATE asistencias SET es_escuela=1, lugar=? , licencia=?";
    $params = [$lugar, $licencia];
    $types2 = 'ss';
    if ($turno !== null) { $sql .= ", turno=?"; $params[] = $turno; $types2 .= 's'; }
    if ($inst !== null) { $sql .= ", instructor=?"; $params[] = $inst; $types2 .= 's'; }
    $sql .= " WHERE id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    $allTypes = $types2 . str_repeat('i', count($ids));
    $stmt->bind_param($allTypes, ...array_merge($params, $ids));
    if ($stmt->execute()) {
        echo json_encode(["ok"=>true, "msg"=>"Marcados como escuela: " . $stmt->affected_rows]);
    } else {
        echo json_encode(["ok"=>false, "msg"=>"Error: " . $stmt->error]);
    }
    $stmt->close();
    exit;
}

/* ================== DESMARCAR ESCUELA (AJAX) ================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'desmarcar_escuela') {
    header("Content-Type: application/json");
    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) { echo json_encode(["ok"=>false,"msg"=>"Sin selección."]); exit; }
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids);
    if (empty($ids)) { echo json_encode(["ok"=>false,"msg"=>"Sin IDs válidos."]); exit; }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("UPDATE asistencias SET es_escuela=0 WHERE id IN ($placeholders)");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    if ($stmt->execute()) {
        echo json_encode(["ok"=>true, "msg"=>"Desmarcados: " . $stmt->affected_rows]);
    } else {
        echo json_encode(["ok"=>false, "msg"=>"Error: " . $stmt->error]);
    }
    $stmt->close();
    exit;
}

/* ================== IMPRESIÓN PDF POR RANGO DE FECHAS ================== */
if (isset($_GET['pdf'])) {
    require_once __DIR__ . '/fpdf/fpdf.php';

    $desde = trim($_GET['desde'] ?? '');
    $hasta = trim($_GET['hasta'] ?? '');

    function _normFechaBA($f) {
        if (!$f) return null;
        if (preg_match('/(\\d{4})-(\\d{2})-(\\d{2})/', $f)) return $f;
        if (preg_match('/(\\d{2})\\/(\\d{2})\\/(\\d{4})/', $f, $m)) return "$m[3]-$m[2]-$m[1]";
        return null;
    }
    $desdeN = _normFechaBA($desde);
    $hastaN = _normFechaBA($hasta);

    $sql = "SELECT fecha_dia, apellidos, nombres, dni, edad, licencia, turno, lugar
            FROM asistencias WHERE oculto = 0";
    $params = []; $types = '';
    if ($desdeN) { $sql .= " AND fecha_dia >= ?"; $params[] = $desdeN; $types .= 's'; }
    if ($hastaN) { $sql .= " AND fecha_dia <= ?"; $params[] = $hastaN; $types .= 's'; }
    $sql .= " ORDER BY fecha_dia ASC, apellidos ASC, nombres ASC";

    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $filas = [];
    while ($r = $res->fetch_assoc()) $filas[] = $r;
    $stmt->close();

    if (empty($filas)) {
        die("No se encontraron registros en el rango seleccionado.");
    }

    $cols = [
        ['Fecha', 24],
        ['Apellido y Nombre', 60],
        ['DNI', 24],
        ['Edad', 14],
        ['Licencia', 34],
        ['Turno', 30],
        ['Lugar', 45],
    ];
    $anchoTotal = 0;
    foreach ($cols as $c) $anchoTotal += $c[1];
    $orientacion = ($anchoTotal > 190) ? 'L' : 'P';

    class PDFRango extends FPDF {
        function Header() {}
        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 8);
            $this->Cell(0, 10, utf8_decode('Página ') . $this->PageNo() . '/{nb}', 0, 0, 'C');
        }
    }

    $pdf = new PDFRango($orientacion, 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetMargins(10, 10, 10);

    $pdf->SetFont('Arial', 'B', 14);
    $pdf->SetTextColor(13, 110, 253);
    $pdf->Cell(0, 10, utf8_decode('LISTADO DE ASISTENTES'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(0, 0, 0);

    $textoRango = 'Rango: ';
    if ($desdeN) $textoRango .= 'desde ' . date('d/m/Y', strtotime($desdeN)) . ' ';
    if ($hastaN) $textoRango .= 'hasta ' . date('d/m/Y', strtotime($hastaN));
    if (!$desdeN && !$hastaN) $textoRango .= 'Todos los registros';
    $pdf->Cell(0, 8, utf8_decode($textoRango), 0, 1, 'C');
    $pdf->Ln(2);

    // Cabecera tabla
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(220, 230, 240);
    foreach ($cols as $c) {
        $pdf->Cell($c[1], 8, utf8_decode($c[0]), 1, 0, 'C', true);
    }
    $pdf->Ln();

    $pdf->SetFont('Arial', '', 9);
    foreach ($filas as $r) {
        $nombreCompleto = trim(($r['apellidos'] ?? '') . ' ' . ($r['nombres'] ?? ''));
        $valores = [
            date('d/m/Y', strtotime($r['fecha_dia'])),
            $nombreCompleto,
            $r['dni'] ?? '',
            $r['edad'] ?? '',
            $r['licencia'] ?? '',
            $r['turno'] ?? '',
            $r['lugar'] ?? '',
        ];
        foreach ($cols as $i => $c) {
            $pdf->Cell($c[1], 7, utf8_decode($valores[$i]), 1, 0, 'L');
        }
        $pdf->Ln();
    }

    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 8, utf8_decode('Total de registros: ' . count($filas)), 0, 1, 'L');

    ob_end_clean();
    $pdf->Output('I', 'Listado_Asistentes_Rango.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Buscar Asistentes — CeCap</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/variables.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

<style>
/* ── Layout: sin scroll en la página, solo en la tabla ── */
html, body {
    height: 100%;
    margin: 0;
    overflow: hidden;
}
body {
    background: linear-gradient(135deg, #504254, #3C313F);
    background-attachment: fixed;
    color: #F0EDF2;
    font-family: 'Segoe UI', sans-serif;
    display: flex;
    flex-direction: column;
    padding: 16px;
    box-sizing: border-box;
}

.card-glass {
    background: rgba(255,255,255,.10);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 20px;
    padding: 20px 24px 16px;
    backdrop-filter: blur(14px);
    box-shadow: 0 20px 50px rgba(0,0,0,.50);
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    min-height: 0;
}

/* Zona scrolleable — solo la tabla */
.tabla-wrap {
    flex: 1;
    overflow-y: auto;
    min-height: 0;
    border-radius: 10px;
}
.tabla-wrap::-webkit-scrollbar { width: 5px; }
.tabla-wrap::-webkit-scrollbar-thumb { background: rgba(93,94,112,.4); border-radius: 3px; }

/* Cabecera sticky */
.table thead th {
    background: rgba(80,66,84,.35);
    font-size: .75rem;
    text-transform: uppercase;
    color: #C7E4CF;
    position: sticky;
    top: 0;
    z-index: 2;
    white-space: nowrap;
}

.table { color: #F0EDF2; margin-bottom: 0; }
.table tbody tr { transition: background .15s; }
.table tbody tr:hover { background: rgba(255,255,255,.08) !important; }

/* ── Acciones siempre en línea ── */
.td-acciones {
    white-space: nowrap;
    text-align: center;
    width: 1%;       /* celda lo más angosta posible */
}
.td-acciones a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    padding: 0;
    font-size: .9rem;
    margin: 0 1px;
}

/* ── Input búsqueda ── */
.form-control {
    background: rgba(0,0,0,.35) !important;
    border: 1px solid rgba(255,255,255,.22) !important;
    color: #fff !important;
    border-radius: 12px !important;
}
.form-control::placeholder { color: rgba(255,255,255,.5) !important; }
.form-control:focus {
    border-color: #5D5E70 !important;
    box-shadow: 0 0 0 2px rgba(93,94,112,.25) !important;
    background: rgba(0,0,0,.45) !important;
}

#contadorResultados {
    font-size: .78rem;
    color: rgba(199,228,207,.55);
}
</style>
</head>

<body>
<div class="card-glass">

    <h3 class="mb-3 text-center flex-shrink-0">🔎 Buscar Asistentes</h3>

    <input type="text" id="buscar" class="form-control form-control-lg mb-1 flex-shrink-0"
           placeholder="Buscar por Apellido, Nombre, DNI o Código de Seguridad…"
           autocomplete="off">

    <!-- Búsqueda por rango de fechas + impresión -->
    <form method="get" class="row g-2 align-items-end mb-2 flex-shrink-0" id="formRango">
        <div class="col-md-3">
            <label class="form-label small mb-1">Desde</label>
            <input type="date" name="desde" class="form-control form-control-sm" value="<?= htmlspecialchars($rangoDesde) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Hasta</label>
            <input type="date" name="hasta" class="form-control form-control-sm" value="<?= htmlspecialchars($rangoHasta) ?>">
        </div>
        <div class="col-md-6 d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm">📅 Buscar por rango</button>
            <a id="btnPdfRango" class="btn btn-success btn-sm"
               href="buscar_asistentes.php?pdf=1&desde=<?= urlencode($rangoDesde) ?>&hasta=<?= urlencode($rangoHasta) ?>"
               target="_blank">🖨 Imprimir PDF</a>
            <?php if ($rangoDesde !== '' || $rangoHasta !== ''): ?>
            <a href="buscar_asistentes.php" class="btn btn-secondary btn-sm">Limpiar</a>
            <?php endif; ?>
        </div>
    </form>

    <div id="contadorResultados" class="mb-2 text-end flex-shrink-0">&nbsp;</div>

    <!-- Acciones masivas -->
    <div class="d-flex gap-2 mb-2 flex-shrink-0">
        <button type="button" class="btn btn-warning btn-sm" id="btnMarcarEscuela">🏫 Marcar como Escuela</button>
        <button type="button" class="btn btn-secondary btn-sm" id="btnDesmarcarEscuela">Quitar marca Escuela</button>
        <span class="small text-white-50 align-self-center" id="selCount">0 seleccionados</span>
    </div>

    <!-- Scroll solo acá -->
    <div class="tabla-wrap">
        <table class="table table-dark table-striped align-middle">
            <thead>
                <tr>
                    <th style="width:30px;"><input type="checkbox" id="chkAll"></th>
                    <th>Fecha</th>
                    <th>Apellido</th>
                    <th>Nombre</th>
                    <th>DNI</th>
                    <th>Edad</th>
                    <th>Licencia</th>
                    <th>Turno</th>
                    <th>Lugar</th>
                    <th>Código</th>
                    <th>Estado</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody id="resultados">
                <?php if (!empty($registrosRango)): ?>
                    <?php foreach ($registrosRango as $r): ?>
                    <tr data-id="<?= (int)$r['id'] ?? 0 ?>">
                        <td><input type="checkbox" class="chkRow" value="<?= (int)($r['id'] ?? 0) ?>"></td>
                        <td><?= htmlspecialchars(date('d/m/Y', strtotime($r['fecha_dia']))) ?></td>
                        <td><?= htmlspecialchars($r['apellidos']) ?></td>
                        <td><?= htmlspecialchars($r['nombres']) ?></td>
                        <td><?= htmlspecialchars($r['dni']) ?></td>
                        <td><?= htmlspecialchars($r['edad'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($r['licencia'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($r['turno'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($r['lugar'] ?? '-') ?></td>
                        <td colspan="3" class="text-center text-muted">—</td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                <tr>
                    <td colspan="11" class="text-center text-muted">
                        Escribí para buscar…
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="flex-shrink-0 mt-2">
        <a href="panel.php" class="btn btn-secondary">⬅ Volver al Panel</a>
    </div>

</div>

<!-- Modal marcar escuela -->
<div class="modal fade" id="modalMarcarEscuela" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content" style="background:rgba(20,25,35,.96);border:1px solid rgba(255,215,0,.4);border-radius:18px;color:#F0EDF2;">
    <div class="modal-header border-secondary border-opacity-25">
        <h5 class="modal-title text-warning">🏫 Marcar como Escuela</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <div class="mb-3">
            <label class="form-label" style="color:#FFD166;">Nombre de la Escuela *</label>
            <input type="text" id="meNomEscuela" class="form-control mayusc" placeholder="Ej: ESCUELA NORMAL SUPERIOR N° 30">
        </div>
        <div class="mb-3">
            <label class="form-label" style="color:#FFD166;">Horario / Turno</label>
            <input type="text" id="meHorario" class="form-control" placeholder="Ej: 3° TURNO 8:30">
        </div>
        <div class="mb-3">
            <label class="form-label" style="color:#FFD166;">Instructor a Cargo</label>
            <input type="text" id="meInstructor" class="form-control mayusc" placeholder="Nombre y Apellido">
        </div>
    </div>
    <div class="modal-footer border-secondary border-opacity-25">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-warning fw-bold" id="btnAceptarMarcar">✅ Aplicar</button>
    </div>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const PUEDE_EMITIR   = <?= $puedeEmitir ?>;
const PUEDE_EDITAR   = <?= $puedeEditar ?>;
const PUEDE_ELIMINAR = <?= $puedeEliminar ?>;

const input    = document.getElementById("buscar");
const tbody    = document.getElementById("resultados");
const contador = document.getElementById("contadorResultados");
let timer = null;

input.addEventListener("input", () => {
    clearTimeout(timer);
    timer = setTimeout(buscar, 300);
});

function buscar() {
    const q = input.value.trim();
    if (q.length < 2) {
        tbody.innerHTML = `<tr><td colspan="11" class="text-center text-muted">Escribí al menos 2 caracteres</td></tr>`;
        contador.innerHTML = "&nbsp;";
        return;
    }

    fetch(`buscar_asistentes.php?ajax=1&q=${encodeURIComponent(q)}`)
        .then(r => r.json())
        .then(data => {
            if (data.length === 0) {
                tbody.innerHTML = `<tr><td colspan="11" class="text-center text-warning">Sin resultados</td></tr>`;
                contador.textContent = "0 resultados";
                return;
            }

            contador.textContent = data.length === 100
                ? "Mostrando los primeros 100 resultados — refiná la búsqueda"
                : `${data.length} resultado${data.length !== 1 ? 's' : ''}`;

            tbody.innerHTML = data.map(r => {
                const acciones = [
                    PUEDE_EMITIR
                        ? `<a href="certificado_individual.php?id=${r.id}" class="btn btn-sm btn-success" title="Emitir certificado">🖨</a>`
                        : '',
                    PUEDE_EDITAR
                        ? `<a href="editar_asistentes.php?id=${r.id}" class="btn btn-sm btn-warning" title="Editar">✏</a>`
                        : '',
                    PUEDE_ELIMINAR
                        ? `<a href="ocultar_asistente.php?id=${r.id}" class="btn btn-sm btn-danger" title="Ocultar"
                              onclick="return confirm('¿Ocultar este registro?')">🗑</a>`
                        : '',
                ].join('');

                return `
                <tr data-id="${r.id}">
                    <td><input type="checkbox" class="chkRow" value="${r.id}"></td>
                    <td>${formatearFecha(r.fecha_dia)}</td>
                    <td>${esc(r.apellidos)}</td>
                    <td>${esc(r.nombres)}</td>
                    <td>${esc(r.dni)}</td>
                    <td>${esc(r.edad ?? '-')}</td>
                    <td>${esc(r.licencia)}</td>
                    <td>${esc(r.turno)}</td>
                    <td>${esc(r.lugar)}</td>
                    <td><code>${esc(r.codigo_seguridad)}</code></td>
                    <td>${mostrarEstado(r.estado_certificado)}</td>
                    <td class="td-acciones">${acciones}</td>
                </tr>`;
            }).join('');
        })
        .catch(() => {
            tbody.innerHTML = `<tr><td colspan="11" class="text-center text-danger">Error al buscar.</td></tr>`;
        });
}

function formatearFecha(f) {
    if (!f) return '-';
    const [y, m, d] = f.split('-');
    return `${d}/${m}/${y}`;
}

function mostrarEstado(estado) {
    const e = (estado ?? 'ORIGINAL').toUpperCase();
    const map = { 'ORIGINAL':'bg-success', 'DUPLICADO':'bg-warning text-dark', 'TRIPLICADO':'bg-danger' };
    return `<span class="badge ${map[e] ?? 'bg-secondary'}">${e}</span>`;
}

/* Actualizar link de impresión al cambiar fechas */
document.querySelectorAll('#formRango input[type="date"]').forEach(inp => {
    inp.addEventListener('change', actualizarLinkPdf);
});
function actualizarLinkPdf() {
    const d = document.querySelector('[name="desde"]').value;
    const h = document.querySelector('[name="hasta"]').value;
    document.getElementById('btnPdfRango').href =
        'buscar_asistentes.php?pdf=1&desde=' + encodeURIComponent(d) +
        '&hasta=' + encodeURIComponent(h);
}
actualizarLinkPdf();

function esc(str) {
    if (!str) return '-';
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── Selección masiva ── */
function seleccionados() {
    return [...document.querySelectorAll('.chkRow:checked')].map(c => c.value);
}
function actualizarContadorSel() {
    document.getElementById('selCount').textContent = seleccionados().length + ' seleccionados';
}
document.addEventListener('change', e => {
    if (e.target.classList.contains('chkRow') || e.target.id === 'chkAll') {
        if (e.target.id === 'chkAll') {
            document.querySelectorAll('.chkRow').forEach(c => c.checked = e.target.checked);
        }
        actualizarContadorSel();
    }
});

const bsModalMarcar = new bootstrap.Modal(document.getElementById('modalMarcarEscuela'));

document.getElementById('btnMarcarEscuela').addEventListener('click', () => {
    if (seleccionados().length === 0) { alert('Seleccioná al menos un registro.'); return; }
    bsModalMarcar.show();
});

document.getElementById('btnAceptarMarcar').addEventListener('click', async () => {
    const nom = document.getElementById('meNomEscuela').value.trim();
    if (nom === '') { alert('Ingresá el nombre de la escuela.'); return; }
    const body = new FormData();
    seleccionados().forEach(id => body.append('ids[]', id));
    body.append('escuela_nombre', nom);
    body.append('horario', document.getElementById('meHorario').value.trim());
    body.append('instructor', document.getElementById('meInstructor').value.trim());
    try {
        const resp = await fetch('buscar_asistentes.php?ajax=marcar_escuela', { method:'POST', body });
        const data = await resp.json();
        if (data.ok) { location.reload(); } else { alert('❌ ' + data.msg); }
    } catch { alert('❌ Error de conexión.'); }
});

document.getElementById('btnDesmarcarEscuela').addEventListener('click', async () => {
    const ids = seleccionados();
    if (ids.length === 0) { alert('Seleccioná al menos un registro.'); return; }
    if (!confirm('¿Quitar marca de escuela a los seleccionados?')) return;
    const body = new FormData();
    ids.forEach(id => body.append('ids[]', id));
    try {
        const resp = await fetch('buscar_asistentes.php?ajax=desmarcar_escuela', { method:'POST', body });
        const data = await resp.json();
        if (data.ok) { location.reload(); } else { alert('❌ ' + data.msg); }
    } catch { alert('❌ Error de conexión.'); }
});
</script>

</body>
</html>
