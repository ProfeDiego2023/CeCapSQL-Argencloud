<?php
session_start();

require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/instructor_fn.php";

if (!permiso("certificados")) {
    die("No autorizado");
}

$instructores = inst_cargarInstructores($conn);
$instAuto = inst_tieneFirmaPropia($conn, (int)($_SESSION['id_instru'] ?? 0));

$desde    = $_GET['desde'] ?? '';
$hasta    = $_GET['hasta'] ?? '';
$turnoSel = $_GET['turno'] ?? 'TODOS';

/* ══════════════════════════════════════════════
   ENDPOINT AJAX
══════════════════════════════════════════════ */
if (isset($_GET['ajax'])) {
    header("Content-Type: application/json");
    header("Cache-Control: no-store, no-cache, must-revalidate");

    if (!$desde || !$hasta) { echo json_encode([]); exit; }

    $tz      = new DateTimeZone("America/Argentina/Buenos_Aires");
    $desdeDT = DateTime::createFromFormat("Y-m-d", $desde, $tz);
    $hastaDT = DateTime::createFromFormat("Y-m-d", $hasta, $tz);
    if (!$desdeDT || !$hastaDT) { echo json_encode([]); exit; }

    $sql = "
        SELECT id, fecha_dia AS fecha, apellidos, nombres, dni, licencia, turno
        FROM asistencias
        WHERE oculto = 0
          AND fecha_dia BETWEEN ? AND ?
    ";
    $params = [$desdeDT->format("Y-m-d"), $hastaDT->format("Y-m-d")];

    if ($turnoSel === "1")     $sql .= " AND turno LIKE '1%'";
    elseif ($turnoSel === "2") $sql .= " AND turno LIKE '2%'";
    elseif ($turnoSel === "3") $sql .= " AND turno LIKE '3%'";

    $sql .= " ORDER BY fecha ASC, apellidos ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", ...$params);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        if (empty($row['dni'])) continue;
        $rows[] = $row;
    }
    $stmt->close();
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ══════════════════════════════════════════════
   VISTA NORMAL
══════════════════════════════════════════════ */
if (!$desde || !$hasta) die("Debe seleccionar un rango de fechas.");

$tz      = new DateTimeZone("America/Argentina/Buenos_Aires");
$desdeDT = DateTime::createFromFormat("Y-m-d", $desde, $tz);
$hastaDT = DateTime::createFromFormat("Y-m-d", $hasta, $tz);

if (!$desdeDT || !$hastaDT) die("Formato de fecha inválido.");

if ($hastaDT < $desdeDT) {
    header("Location: filtrar_fechas.php?desde=" . urlencode($desde)
         . "&hasta=" . urlencode($hasta)
         . "&turno=" . urlencode($turnoSel)
         . "&error=rango");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Registros — CeCap</title>
<meta name="generator" content="listado-v3">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/variables.css">

<style>
/* ── Mismo esquema que registro_manual.php ── */
html, body {
    margin: 0;
}
body {
    font-family: 'Segoe UI', sans-serif;
    background: linear-gradient(135deg, #504254, #3C313F);
    background-attachment: fixed;
    color: #fff;
    padding: 16px;
    box-sizing: border-box;
    min-height: 100vh;
}

.glass {
    background: rgba(255,255,255,.10);
    backdrop-filter: blur(14px);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 20px;
    box-shadow: 0 20px 50px rgba(0,0,0,.50);
    padding: 20px;
    max-width: 1200px;
    margin: 0 auto;
}

/* La página crece naturalmente; la tabla muestra todas las filas */
.tabla-scroll {
    overflow-x: auto;
}
.tabla-scroll::-webkit-scrollbar { width: 5px; }
.tabla-scroll::-webkit-scrollbar-thumb {
    background: rgba(93,94,112,.4);
    border-radius: 3px;
}

/* Cabecera sticky */
.table thead th {
    background: rgba(221,72,65,.35);
    position: sticky;
    top: 0;
    z-index: 2;
    font-size: .78rem;
    text-transform: uppercase;
    color: #C7E4CF;
    white-space: nowrap;
}

.table { color: #F0EDF2; margin-bottom: 0; }
.table tbody tr { transition: background .15s; }
.table tbody tr:hover { background: rgba(255,255,255,.08) !important; }

.table input[type="checkbox"] {
    transform: scale(1.25);
    accent-color: #5D5E70;
    cursor: pointer;
}

/* Badge contador */
#badgeTotal {
    background: rgba(93,94,112,.25);
    border: 1px solid rgba(255,209,102,.45);
    color: #FFD166;
    border-radius: 20px;
    padding: 4px 14px;
    font-size: .85rem;
    font-weight: 700;
}

/* Barra de estado */
#barraEstado {
    font-size: .75rem;
    color: rgba(199,228,207,.55);
}
#barraEstado.act { color: #5D5E70; }

/* Fila nueva — destello suave */
@keyframes filaEntrada {
    from { background: rgba(93,94,112,.25); }
    to   { background: transparent; }
}
.fila-nueva { animation: filaEntrada 2.5s ease-out forwards; }

/* Botones */
.btn-accion {
    height: 42px;
    border-radius: 12px;
    font-weight: 700;
    font-size: .9rem;
    transition: transform .15s, box-shadow .15s;
}
.btn-accion:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(0,0,0,.3);
}
</style>
</head>

<body>
<div class="glass">

    <!-- Encabezado -->
    <div class="d-flex align-items-center justify-content-between mb-2 flex-shrink-0 flex-wrap gap-2">
        <h5 class="mb-0">
            📋 Registros
            <span class="text-info fw-normal" style="font-size:.85rem">
                <?= htmlspecialchars($desdeDT->format("d/m/Y")) ?> →
                <?= htmlspecialchars($hastaDT->format("d/m/Y")) ?>
            </span>
        </h5>
        <div class="d-flex align-items-center gap-3">
            <span id="badgeTotal">Cargando...</span>
            <span id="barraEstado">⏳</span>
            <span class="text-muted" style="font-size:.7rem" title="Versión del listado">v3</span>
        </div>
    </div>

    <!-- Formulario con la tabla -->
    <form action="certificados.php" method="POST" id="formRegistros">

        <input type="hidden" name="desde" value="<?= htmlspecialchars($desde) ?>">
        <input type="hidden" name="hasta" value="<?= htmlspecialchars($hasta) ?>">
        <input type="hidden" name="turno" value="<?= htmlspecialchars($turnoSel) ?>">

        <!-- Scroll solo aquí -->
        <div class="tabla-scroll">
            <table class="table table-dark table-striped table-bordered align-middle">
            <thead>
            <tr>
                <th style="width:36px">
                    <input type="checkbox" id="checkAll" checked title="Todos">
                </th>
                <th>Fecha</th>
                <th>Apellido</th>
                <th>Nombre</th>
                <th>DNI</th>
                <th>Licencia</th>
                <th>Turno</th>
            </tr>
            </thead>
            <tbody id="cuerpoTabla">
                <tr><td colspan="7" class="text-center text-muted">Cargando registros...</td></tr>
            </tbody>
            </table>
        </div>

        <!-- Resultado de envío por email -->
        <div id="resultadoEnvio" class="mt-3 d-none"></div>

        <!-- Botones fijos abajo -->
        <div class="d-flex gap-2 justify-content-between align-items-center mt-3 flex-shrink-0 flex-wrap">
            <a href="filtrar_fechas.php" class="btn btn-secondary btn-accion px-3">
                🔙 Volver
            </a>
            <div class="d-flex gap-2">
                <?php if (permiso('cert_email')): ?>
                <button type="button" id="btnEnviarEmail" class="btn btn-primary btn-accion px-3">
                    ✉️ Enviar Certificados
                </button>
                <?php endif; ?>
                <button type="submit" id="btnCertificados" class="btn btn-success btn-accion px-3">
                    🟢 Generar Certificados
                </button>
            </div>
        </div>

    </form>
</div>

<?= inst_renderModal($instructores) ?>

<script>
const DESDE     = <?= json_encode($desde) ?>;
const HASTA     = <?= json_encode($hasta) ?>;
const TURNO_SEL = <?= json_encode($turnoSel) ?>;
const INTERVALO = 5000;
const INST_AUTO = <?= $instAuto ? (int)$instAuto['id'] : 0 ?>;
let intervaloId   = null;
let countdownId   = null;
let refrescando   = false;
let pausado       = false;
let segundosResto = INTERVALO / 1000;

const estadoChecks = {};
let   idsConocidos  = new Set();

/* ── Fetch ── */
async function actualizarTabla() {
    if (refrescando || pausado) return;
    refrescando = true;

    const barra = document.getElementById('barraEstado');
    barra.className = 'act';
    barra.textContent = '🔄';

    try {
        const url = `filtrar_fechas_listado.php?ajax=1`
            + `&desde=${encodeURIComponent(DESDE)}`
            + `&hasta=${encodeURIComponent(HASTA)}`
            + `&turno=${encodeURIComponent(TURNO_SEL)}`;
        const resp = await fetch(url);
        const data = await resp.json();
        renderTabla(data);
        const ahora = new Date().toLocaleTimeString('es-AR');
        barra.className = '';
        barra.textContent = `✅ ${ahora}`;
    } catch {
        barra.className = '';
        barra.textContent = '⚠️ Error';
    }

    refrescando   = false;
    segundosResto = INTERVALO / 1000;
}

/* ── Render preservando checks ── */
function renderTabla(datos) {
    const tbody = document.getElementById('cuerpoTabla');
    const badge = document.getElementById('badgeTotal');

    badge.textContent = `📊 ${datos.length} registro${datos.length !== 1 ? 's' : ''}`;

    if (datos.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" class="text-center text-warning">
            No hay registros en este rango.</td></tr>`;
        return;
    }

    // Guardar estado actual
    tbody.querySelectorAll("input[type='checkbox']").forEach(cb => {
        estadoChecks[cb.dataset.key] = cb.checked;
    });

    const nuevosIds = new Set(datos.map(r => String(r.id)));

    tbody.innerHTML = datos.map(r => {
        const key     = String(r.id);
        const esNuevo = !idsConocidos.has(key);
        const checked = (key in estadoChecks) ? estadoChecks[key] : true;
        const fechaFmt = r.fecha ? r.fecha.split('-').reverse().join('/') : '-';

        return `
        <tr class="${esNuevo && idsConocidos.size > 0 ? 'fila-nueva' : ''}">
            <td>
                <input type="checkbox"
                       name="registros[]"
                       value="${esc(r.dni + '|' + r.fecha)}"
                       data-key="${key}"
                       ${checked ? 'checked' : ''}>
            </td>
            <td>${fechaFmt}</td>
            <td>${esc(r.apellidos)}</td>
            <td>${esc(r.nombres)}</td>
            <td>${esc(r.dni)}</td>
            <td>${esc(r.licencia ?? '-')}</td>
            <td>${esc(r.turno ?? '-')}</td>
        </tr>`;
    }).join('');

    idsConocidos = nuevosIds;

    // Sincronizar checkAll
    const todos     = tbody.querySelectorAll("input[type='checkbox']");
    const nMarcados = [...todos].filter(c => c.checked).length;
    const ca        = document.getElementById('checkAll');
    ca.checked       = nMarcados === todos.length;
    ca.indeterminate = nMarcados > 0 && nMarcados < todos.length;
}

function esc(str) {
    if (!str) return '-';
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── Check all ── */
document.getElementById('checkAll').addEventListener('change', function () {
    document.querySelectorAll("#cuerpoTabla input[type='checkbox']").forEach(cb => {
        cb.checked = this.checked;
        estadoChecks[cb.dataset.key] = this.checked;
    });
});

/* ── Detener al generar/exportar ── */
function detener(motivo) {
    pausado = true;
    clearInterval(intervaloId);
    clearInterval(countdownId);
    const b = document.getElementById('barraEstado');
    b.className = '';
    b.textContent = `⏸ ${motivo}`;
}

document.getElementById('formRegistros').addEventListener('submit', function (e) {
    e.preventDefault();
    detener('generando');
    const usarId = INST_AUTO || null;
    const enviar = (ins) => {
        const id = ins ? ins.id : usarId;
        if (id) {
            const h = document.createElement('input');
            h.type = 'hidden';
            h.name = 'instructor_id';
            h.value = id;
            this.appendChild(h);
        }
        this.submit();
    };
    if (usarId) {
        enviar(null);
    } else {
        abrirSeleccionInstructor('¿Quién firma los certificados?', enviar);
    }
});
document.getElementById('btnExportarPDF')?.addEventListener('click', () => {
    detener('exportando');
    const f = document.getElementById('formRegistros');
    f.action = 'reporte.php';
    f.submit();
});

/* ── Enviar certificados por email (link de un solo uso) ── */
let sinEmailPares = [];

const btnEnviarEmail = document.getElementById('btnEnviarEmail');
if (btnEnviarEmail) {
    btnEnviarEmail.addEventListener('click', function () {
        const checks = document.querySelectorAll("#cuerpoTabla input[type='checkbox']:checked");
        if (!checks.length) {
            alert('Seleccioná al menos un asistente para enviar.');
            return;
        }
        const usarId = INST_AUTO || null;
        const enviar = (ins) => enviarCertificadosEmail(checks, ins || (usarId ? { id: usarId } : null));
        if (usarId) {
            enviar(null);
        } else {
            abrirSeleccionInstructor('¿Quién firma los certificados por email?', enviar);
        }
    });
}

async function enviarCertificadosEmail(checks, instructor) {
    if (!confirm(`¿Enviar certificados por email a ${checks.length} asistente(s)?`)) return;

    detener('enviando');

    const panel = document.getElementById('resultadoEnvio');
    panel.classList.remove('d-none');
    panel.innerHTML = '<div class="alert alert-info">⏳ Enviando certificados...</div>';

    const btn = document.getElementById('btnEnviarEmail');
    btn.disabled = true;
    const textoOrig = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Enviando...';

    const formData = new FormData();
    checks.forEach(cb => formData.append('registros[]', cb.value));
    if (instructor) formData.append('instructor_id', instructor.id);

    try {
        const resp = await fetch('procesar_envio_links.php', { method: 'POST', body: formData });
        const data = await resp.json();

        sinEmailPares = Array.isArray(data.sinEmail) ? data.sinEmail : [];

        let html = '';
        const claseEnviados = data.enviados > 0 ? 'alert-success' : 'alert-warning';
        html += `<div class="alert ${claseEnviados}">
            ✅ Enviados: ${data.enviados} &nbsp;|&nbsp; ❌ Errores: ${data.errores}</div>`;

        if (data.detalle && data.detalle.length) {
            html += `<div class="alert alert-secondary" style="max-height:180px;overflow:auto;font-size:.8rem">
                <pre style="margin:0">${data.detalle.map(esc).join('\n')}</pre></div>`;
        }

        if (sinEmailPares.length) {
            const nombres = sinEmailPares
                .map(p => esc((p.apellidos || '') + ' ' + (p.nombres || '')))
                .join(', ');
            html += `<div class="alert alert-warning">
                ⚠️ <strong>${sinEmailPares.length}</strong> asistente(s) sin email:
                ${nombres}</div>`;
            html += `<div class="d-flex gap-2 flex-wrap mb-2">
                <button type="button" class="btn btn-outline-warning btn-accion px-3"
                        onclick="imprimirSinEmail()">
                    🖨️ Imprimir certificados sin email (${sinEmailPares.length})
                </button>
            </div>`;
        } else {
            html += `<div class="text-info" style="font-size:.85rem">
                ✔ Todos los asistentes seleccionados tenían email.</div>`;
        }

        panel.innerHTML = html;
    } catch {
        panel.innerHTML = '<div class="alert alert-danger">❌ Error de conexión al enviar.</div>';
    }

    btn.disabled = false;
    btn.innerHTML = textoOrig;
}

function imprimirSinEmail() {
    if (!sinEmailPares.length) return;
    const enviar = (ins) => {
        const f = document.createElement('form');
        f.method = 'POST';
        f.action = 'certificados.php';
        const id = ins ? ins.id : INST_AUTO;
        if (id) {
            const h = document.createElement('input');
            h.type = 'hidden';
            h.name = 'instructor_id';
            h.value = id;
            f.appendChild(h);
        }
        sinEmailPares.forEach(p => {
            const i = document.createElement('input');
            i.type = 'hidden';
            i.name = 'registros[]';
            i.value = (p.dni || '') + '|' + (p.fecha_dia || '');
            f.appendChild(i);
        });
        document.body.appendChild(f);
        f.submit();
    };
    if (INST_AUTO) {
        enviar(null);
    } else {
        abrirSeleccionInstructor('¿Quién firma los certificados sin email?', enviar);
    }
}

/* ── Countdown ── */
function iniciarCountdown() {
    clearInterval(countdownId);
    countdownId = setInterval(() => {
        if (pausado) { clearInterval(countdownId); return; }
        segundosResto--;
        const b = document.getElementById('barraEstado');
        if (segundosResto > 0) {
            b.textContent = `🔄 en ${segundosResto}s`;
        } else {
            segundosResto = INTERVALO / 1000;
        }
    }, 1000);
}

/* ── Arrancar ── */
actualizarTabla();
intervaloId = setInterval(actualizarTabla, INTERVALO);
iniciarCountdown();
</script>

</body>
</html>