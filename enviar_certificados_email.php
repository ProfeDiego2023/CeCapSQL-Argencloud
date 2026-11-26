<?php
session_start();
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/email_certificado_fn.php";
require_once __DIR__ . "/instructor_fn.php";

if (!permiso("cert_email")) {
    die("No autorizado");
}

email_asegurarTablas($conn);
$emailCfg = email_cargarConfig($conn);
$emailListo = email_configurado($emailCfg);

$instructores = inst_cargarInstructores($conn);
$instAuto = inst_tieneFirmaPropia($conn, (int)($_SESSION['id_instru'] ?? 0));

$desde    = $_GET['desde'] ?? '';
$hasta    = $_GET['hasta'] ?? '';
$turnoSel = $_GET['turno'] ?? 'TODOS';

if (isset($_GET['ajax'])) {
    header("Content-Type: application/json");
    header("Cache-Control: no-store, no-cache, must-revalidate");

    if (!$desde || !$hasta) {
        echo json_encode([]);
        exit;
    }

    $sql = "
        SELECT id, fecha_dia AS fecha, apellidos, nombres, dni, licencia, turno,
               email, cert_email_enviado, cert_email_fecha
        FROM asistencias
        WHERE oculto = 0
          AND fecha_dia BETWEEN ? AND ?
    ";
    $params = [$desde, $hasta];

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

$mostrarListado = ($desde !== '' && $hasta !== '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Enviar Certificados por Email — CeCap</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/variables.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">
<style>
html, body { height: 100%; margin: 0; }
body {
    font-family: 'Segoe UI', sans-serif;
    background: linear-gradient(135deg, #504254, #3C313F);
    background-attachment: fixed;
    color: #F0EDF2;
    padding: 16px;
    box-sizing: border-box;
    <?= $mostrarListado ? 'overflow:hidden; display:flex; flex-direction:column;' : 'display:flex; align-items:center; justify-content:center;' ?>
}
.glass {
    background: rgba(255,255,255,.10);
    backdrop-filter: blur(14px);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 20px;
    box-shadow: 0 20px 50px rgba(0,0,0,.50);
    padding: 20px;
    <?= $mostrarListado ? 'flex:1; min-height:0; display:flex; flex-direction:column; overflow:hidden;' : 'width:100%; max-width:420px;' ?>
}
.form-control, .form-select, input.flatpickr-input[readonly] {
    background: rgba(255,255,255,.15) !important;
    border: 1px solid rgba(255,255,255,.30) !important;
    color: #F0EDF2 !important;
    border-radius: 12px !important;
    height: 44px;
}
.form-label { color: #C7E4CF; font-weight: 600; font-size: .88rem; }
.btn-accion {
    height: 42px; border-radius: 12px; font-weight: 700; border: none;
}
.btn-primary-accion { background: linear-gradient(135deg, #DD4841, #B03030); color: #fff; }
.btn-primary-accion:hover{
    background: #B03030; color: #fff; transform: translateY(-2px);
}
.btn-success-accion { background: linear-gradient(135deg, #198754, #C7E4CF); color: #fff; }
.btn-volver {
    border: 2px solid rgba(93,94,112,.55); color: #5D5E70; border-radius: 12px;
    font-weight: 700; padding: 10px 16px; text-decoration: none;
    color: #F0EDF2;
}
.btn-volver:hover{
  background: #B03030;
  color: #fff; transform: translateY(-2px);  
}
.tabla-scroll { flex: 1; overflow-y: auto; min-height: 0; }
.table thead th {
    background: rgba(221,72,65,.35); position: sticky; top: 0; z-index: 2;
    font-size: .78rem; text-transform: uppercase; color: #C7E4CF;
}
.table { color: #F0EDF2; margin-bottom: 0; }
.badge-email-ok { background: #198754; color: #fff; font-weight: 600; }
.badge-email-no { background: #dc3545; color: #fff; font-weight: 600; }
.badge-enviado { background: #5D5E70; color: #062c36; font-weight: 600; }
.alert-config {
    background: rgba(255,193,7,.15);
    border: 1px solid rgba(255,193,7,.35);
    color: #FFD166;
    border-radius: 12px;
    padding: 12px 16px;
    margin-bottom: 16px;
}
#resultadoEnvio { font-size: .85rem; white-space: pre-wrap; }
</style>
</head>
<body>

<?php if (!$mostrarListado): ?>
<div class="glass">
    <h4 class="text-center mb-3" style="color:#fff;font-weight:800;text-shadow:0 2px 10px rgba(0,0,0,.5);">
        <i class="bi bi-envelope-paper-fill"></i> Enviar Certificados por Email
    </h4>

    <?php if (!$emailListo): ?>
    <div class="alert-config">
        <i class="bi bi-exclamation-triangle-fill"></i>
        Primero debés configurar el servidor SMTP.
        <a href="configurar_email.php" class="text-warning">Configurar email</a>
    </div>
    <?php endif; ?>

    <form method="GET">
        <div class="mb-3">
            <label class="form-label">Desde</label>
            <input type="text" id="fpDesde" name="desde" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Hasta</label>
            <input type="text" id="fpHasta" name="hasta" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Turno</label>
            <select name="turno" class="form-select">
                <option value="TODOS">Todos</option>
                <option value="1">Turno 1</option>
                <option value="2">Turno 2</option>
                <option value="3">Turno 3</option>
            </select>
        </div>
        <button type="submit" class="btn-accion btn-primary-accion w-100">Buscar asistentes</button>
        <a href="panel.php" class="btn-volver d-block text-center mt-3">Volver al panel</a>
        <?php if (permiso('config_cert')): ?>
        <a href="configurar_email.php" class="btn-volver d-block text-center mt-2">Configurar SMTP</a>
        <a href="configurar_instructores.php" class="btn-volver d-block text-center mt-2">Configurar Instructores</a>
        <?php endif; ?>
    </form>
</div>

<?php else: ?>
<div class="glass">
    <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2 flex-shrink-0">
        <h5 class="mb-0">
            <i class="bi bi-envelope-paper-fill"></i> Enviar certificados
            <span class="text-info fw-normal" style="font-size:.85rem">
                <?= htmlspecialchars(date('d/m/Y', strtotime($desde))) ?> →
                <?= htmlspecialchars(date('d/m/Y', strtotime($hasta))) ?>
            </span>
        </h5>
        <span id="badgeTotal" class="badge rounded-pill">0 seleccionados</span>
    </div>

    <?php if (!$emailListo): ?>
    <div class="alert-config flex-shrink-0">
        El envío por email no está configurado.
        <a href="configurar_email.php" class="text-warning">Configurar ahora</a>
    </div>
    <?php endif; ?>

    <div class="tabla-scroll mb-3">
        <table class="table table-dark table-sm align-middle">
            <thead>
                <tr>
                    <th><input type="checkbox" id="chkTodos"></th>
                    <th>Fecha</th>
                    <th>Apellidos y nombres</th>
                    <th>DNI</th>
                    <th>Email</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody id="cuerpoTabla">
                <tr><td colspan="6" class="text-center py-4">Cargando...</td></tr>
            </tbody>
        </table>
    </div>

    <div id="resultadoEnvio" class="mb-2"></div>

    <div class="d-flex gap-2 flex-wrap flex-shrink-0">
        <button type="button" id="btnEnviar" class="btn-accion btn-success-accion px-4"
                <?= $emailListo ? '' : 'disabled' ?>>
            <i class="bi bi-send-fill"></i> Enviar certificados seleccionados
        </button>
        <a href="enviar_certificados_email.php" class="btn-volver">Nueva búsqueda</a>
        <a href="panel.php" class="btn-volver">Panel</a>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
<?= inst_renderModal($instructores) ?>
<script>
const mostrarListado = <?= $mostrarListado ? 'true' : 'false' ?>;
const INST_AUTO = <?= $instAuto ? (int)$instAuto['id'] : 0 ?>;

if (!mostrarListado) {
    flatpickr("#fpDesde", { locale: "es", dateFormat: "Y-m-d", altInput: true, altFormat: "d/m/Y" });
    flatpickr("#fpHasta", { locale: "es", dateFormat: "Y-m-d", altInput: true, altFormat: "d/m/Y" });
} else {
    const params = new URLSearchParams({
        ajax: "1",
        desde: <?= json_encode($desde) ?>,
        hasta: <?= json_encode($hasta) ?>,
        turno: <?= json_encode($turnoSel) ?>
    });

    let registros = [];

    function fmtFecha(f) {
        if (!f) return "";
        const p = f.split("-");
        return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : f;
    }

    function emailValido(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test((email || "").trim());
    }

    function actualizarBadge() {
        const n = document.querySelectorAll(".chk-registro:checked").length;
        document.getElementById("badgeTotal").textContent = `${n} seleccionados`;
    }

    function renderTabla(rows) {
        registros = rows;
        const tbody = document.getElementById("cuerpoTabla");
        if (!rows.length) {
            tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4">No hay registros en el rango seleccionado.</td></tr>`;
            return;
        }

        tbody.innerHTML = rows.map(r => {
            const okEmail = emailValido(r.email);
            const enviado = Number(r.cert_email_enviado) === 1;
            const estado = enviado
                ? `<span class="badge badge-enviado">Enviado</span>`
                : `<span class="badge badge-email-no">Pendiente</span>`;
            const emailBadge = okEmail
                ? `<span class="badge badge-email-ok">${r.email}</span>`
                : `<span class="badge badge-email-no">${r.email || 'Sin email'}</span>`;

            return `
                <tr>
                    <td>
                        <input type="checkbox" class="chk-registro" value="${r.id}"
                               ${okEmail ? '' : 'disabled'}>
                    </td>
                    <td>${fmtFecha(r.fecha)}</td>
                    <td>${r.apellidos} ${r.nombres}</td>
                    <td>${r.dni}</td>
                    <td>${emailBadge}</td>
                    <td>${estado}</td>
                </tr>`;
        }).join("");

        actualizarBadge();
    }

    fetch(`enviar_certificados_email.php?${params}`)
        .then(r => r.json())
        .then(renderTabla)
        .catch(() => {
            document.getElementById("cuerpoTabla").innerHTML =
                `<tr><td colspan="6" class="text-center text-danger py-4">Error al cargar registros.</td></tr>`;
        });

    document.getElementById("chkTodos").addEventListener("change", function () {
        document.querySelectorAll(".chk-registro:not(:disabled)").forEach(chk => {
            chk.checked = this.checked;
        });
        actualizarBadge();
    });

    document.getElementById("cuerpoTabla").addEventListener("change", e => {
        if (e.target.classList.contains("chk-registro")) actualizarBadge();
    });

    document.getElementById("btnEnviar").addEventListener("click", function () {
        const ids = [...document.querySelectorAll(".chk-registro:checked")].map(el => el.value);
        if (!ids.length) {
            alert("Seleccioná al menos un asistente con email válido.");
            return;
        }
        const usarId = INST_AUTO || null;
        const enviar = (ins) => enviarCertificados(ids, ins || (usarId ? { id: usarId } : null));
        if (usarId) {
            enviar(null);
        } else {
            abrirSeleccionInstructor('¿Quién firma los certificados por email?', enviar);
        }
    });

    async function enviarCertificados(ids, instructor) {
        if (!confirm(`¿Enviar certificados por email a ${ids.length} asistente(s)?`)) return;

        const btn = document.getElementById("btnEnviar");
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Enviando...';
        const resDiv = document.getElementById("resultadoEnvio");
        resDiv.textContent = "";

        try {
            const payload = { ids };
            if (instructor) payload.instructor_id = instructor.id;

            const res = await fetch("procesar_envio_certificados.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload)
            });

            const rawText = await res.text();
            let data;
            try {
                data = JSON.parse(rawText);
            } catch (jsonErr) {
                resDiv.textContent = `Error en la respuesta del servidor (HTTP ${res.status}):\n` + rawText.substring(0, 800);
                return;
            }

            let texto = "";
            if (data.msg) {
                texto += data.msg + "\n";
            }
            if (typeof data.enviados !== "undefined" || typeof data.errores !== "undefined") {
                texto += `Enviados: ${data.enviados || 0} | Errores: ${data.errores || 0}\n`;
            }
            if (Array.isArray(data.detalle) && data.detalle.length > 0) {
                texto += "\nDetalle:\n" + data.detalle.join("\n");
            }
            resDiv.textContent = texto.trim();

            const refresh = await fetch(`enviar_certificados_email.php?${params}`);
            renderTabla(await refresh.json());
        } catch (err) {
            resDiv.textContent = "Error de red / conexión al enviar: " + (err.message || err);
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send-fill"></i> Enviar certificados seleccionados';
        }
    }
}
</script>
</body>
</html>
