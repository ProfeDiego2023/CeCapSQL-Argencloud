<?php
session_start();
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/conexion.php";
proteger();

if (!permiso('registrar')) {
    http_response_code(403);
    die("<div style='font-family:sans-serif;text-align:center;padding:60px;color:#fff;background:#111;min-height:100vh'>
        <h2>&#9940; Acceso denegado</h2>
        <p>No tenés permiso para acceder a esta sección.</p>
        <a href='panel.php' style='color:#5D5E70'>← Volver al panel</a>
    </div>");
}

/* ── Opciones fijas ── */
$optsLicencia = ['AUTOMÓVIL','MOTOVEHÍCULO','AUTOMÓVIL + MOTOVEHÍCULO','PROFESIONAL','RCP','ESCUELAS/COLEGIOS'];
$optsLugar    = ['LEHMANN 1530','NIDO','PUNTO DIGITAL','DANTE ALIGHIERI','S. IRIONDO 5870','SARMIENTO 2551'];
$optsTurno    = ['1° TURNO 13:30','2° TURNO 20:00','3° TURNO 8:30'];
$optsGenero   = ['MASCULINO','FEMENINO','OTRO'];

/* ── Instructores disponibles para el modal ── */
$listaInstructores = [];
$resInst = $conn->query("
    SELECT DISTINCT apellido, nombre FROM firmas_instructores
    WHERE habilitado = 1
    ORDER BY apellido ASC, nombre ASC
");
if ($resInst && $resInst->num_rows > 0) {
    while ($inst = $resInst->fetch_assoc()) {
        $nomCompleto = trim($inst['nombre'] . ' ' . $inst['apellido']);
        if ($nomCompleto !== '') $listaInstructores[] = mb_strtoupper($nomCompleto, 'UTF-8');
    }
}
if (empty($listaInstructores)) {
    $listaInstructores = ['DIEGO CARAVACA', 'IVÁN CARDOSO'];
}
$instructorSesion = mb_strtoupper(trim($_SESSION['nombre'] ?? ''), 'UTF-8');

/* ── Asegurar columnas para escuela e instructor si aún no existen en BD ── */
$checkCols = $conn->query("SHOW COLUMNS FROM asistencias LIKE 'es_escuela'");
if ($checkCols && $checkCols->num_rows === 0) {
    @$conn->query("ALTER TABLE asistencias ADD COLUMN es_escuela TINYINT(1) NOT NULL DEFAULT 0");
}
$checkCols2 = $conn->query("SHOW COLUMNS FROM asistencias LIKE 'instructor'");
if ($checkCols2 && $checkCols2->num_rows === 0) {
    @$conn->query("ALTER TABLE asistencias ADD COLUMN instructor VARCHAR(150) NULL DEFAULT ''");
}

/* ── Escuelas registradas para sugerencias ── */
$escuelasExistentes = [];
$resEsc = $conn->query("
    SELECT DISTINCT lugar
    FROM asistencias
    WHERE lugar LIKE '%ESC%' AND oculto = 0
    ORDER BY lugar ASC
    LIMIT 100
");
if ($resEsc) {
    while ($rowEsc = $resEsc->fetch_assoc()) {
        $nom = trim(preg_replace('/^ESCUELA\s*[-:]?\s*/i', '', $rowEsc['lugar']));
        if ($nom !== '' && !in_array($nom, $escuelasExistentes, true)) {
            $escuelasExistentes[] = $nom;
        }
    }
}

/* ── Últimos 20 registros (carga inicial) ── */
$stmt = $conn->prepare("
    SELECT *
    FROM asistencias
    WHERE oculto = 0
    ORDER BY id DESC
    LIMIT 20
");
$stmt->execute();
$resInicial = $stmt->get_result();
$stmt->close();

/* ── Mensajes ── */
$msgOk    = $_SESSION['msg_ok']    ?? null;
$msgError = $_SESSION['msg_error'] ?? null;
unset($_SESSION['msg_ok'], $_SESSION['msg_error']);

/* ── Helper PHP para options ── */
function opts(array $list, string $sel = ''): string {
    $h = '';
    foreach ($list as $v) {
        $s = (mb_strtoupper($v) === mb_strtoupper($sel)) ? ' selected' : '';
        $h .= "<option value=\"" . htmlspecialchars($v) . "\"$s>" . htmlspecialchars($v) . "</option>";
    }
    return $h;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Registro Manual — CeCap</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="css/variables.css">

<style>
html, body {
    height: 100%;
    margin: 0;
    overflow: hidden;
}
body {
    font-family: 'Segoe UI', sans-serif;
    background: linear-gradient(135deg, #504254, #3C313F);
    background-attachment: fixed;
    color: #fff;
    display: flex;
    flex-direction: column;
    padding: 16px;
    box-sizing: border-box;
}

/* Layout flex horizontal */
.layout {
    display: flex;
    gap: 16px;
    flex: 1;
    min-height: 0;
}

/* Glass card */
.glass {
    background: rgba(255,255,255,.10);
    backdrop-filter: blur(14px);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 20px;
    box-shadow: 0 20px 50px rgba(0,0,0,.50);
    padding: 20px;
    display: flex;
    flex-direction: column;
}

/* Panel izquierdo — formulario */
.panel-form {
    width: 290px;
    flex-shrink: 0;
    overflow-y: auto;
}

/* Panel derecho — tabla */
.panel-tabla {
    flex: 1;
    min-width: 0;
}

/* Scroll solo en tabla */
.tabla-scroll {
    flex: 1;
    overflow-y: auto;
    min-height: 0;
}
.tabla-scroll::-webkit-scrollbar { width: 5px; }
.tabla-scroll::-webkit-scrollbar-thumb { background: rgba(93,94,112,.4); border-radius: 3px; }

/* Inputs */
.form-control, .form-select {
    background: rgba(0,0,0,.35) !important;
    border: 1px solid rgba(72, 187, 120, 0.45) !important;
    color: #fff !important;
    border-radius: 12px !important;
    transition: border-color .15s ease, box-shadow .15s ease, background-color .15s ease;
}
.form-control::placeholder { color: rgba(255,255,255,.5) !important; }
.form-control:focus, .form-select:focus {
    border-color: #48bb78 !important;
    box-shadow: 0 0 0 3px rgba(72, 187, 120, 0.30) !important;
    background: rgba(72, 187, 120, 0.12) !important;
    outline: none;
}
.form-select option { background: #1b2a3a; color: #fff; }

.form-label {
    font-size: .75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #C7E4CF;
    margin-bottom: 3px;
}

/* Botón principal */
.btn-main {
    height: 48px;
    border-radius: 14px;
    font-weight: 700;
    background: linear-gradient(135deg, #DD4841, #B03030);
    border: none;
    color: #fff;
}
.btn-main:hover { opacity: .9; }

/* Tabla */
.table { color: #F0EDF2; margin-bottom: 0; }
.table thead th {
    font-size: .75rem;
    text-transform: uppercase;
    color: #C7E4CF;
    background: rgba(80,66,84,.35);
    position: sticky;
    top: 0;
    z-index: 1;
    white-space: nowrap;
}
.table tbody tr {
    cursor: pointer;
    transition: background .15s;
}
.table tbody tr:hover { background: rgba(255,255,255,.10) !important; }
.fila-top {
    background: linear-gradient(135deg, rgba(199,228,207,.18), rgba(80,66,84,.18)) !important;
    font-weight: 600;
}

/* Indicador refresco */
#barraEstado {
    font-size: .75rem;
    color: rgba(199,228,207,.55);
}

/* Modal */
.modal-content {
    background: rgba(15,25,40,.95);
    backdrop-filter: blur(16px);
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 20px;
    color: #F0EDF2;
}
.modal-header { border-bottom: 1px solid rgba(255,255,255,.12); }
.modal-footer { border-top:   1px solid rgba(255,255,255,.12); }
#modalMensaje { font-size: .88rem; }
</style>
</head>

<body>

<?php if ($msgOk): ?>
<div class="alert alert-success alert-dismissible fade show mx-2 mb-2 py-2" role="alert">
    ✅ <?= htmlspecialchars($msgOk) ?>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($msgError): ?>
<div class="alert alert-danger alert-dismissible fade show mx-2 mb-2 py-2" role="alert">
    ❌ <?= htmlspecialchars($msgError) ?>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="layout">

    <!-- ══════════════════ FORMULARIO NUEVO REGISTRO ══════════════════ -->
    <div class="glass panel-form">
        <h5 class="text-center mb-2">➕ Registro Manual</h5>

        <!-- Switch / Tilde Ingreso a Escuelas -->
        <div class="mb-2 p-2 rounded" style="background: rgba(255,215,0,0.10); border: 1px solid rgba(255,215,0,0.30);">
            <div class="form-check form-switch m-0 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <input class="form-check-input me-2" type="checkbox" id="checkEsEscuela" style="cursor:pointer; width:2.2em; height:1.2em;">
                    <label class="form-check-label fw-bold" for="checkEsEscuela" style="cursor:pointer; font-size:.82rem; color:#FFD166;">
                        🏫 Ingreso a Escuelas
                    </label>
                </div>
                <button type="button" class="btn btn-sm btn-link text-warning p-0 text-decoration-none d-none" id="btnConfigEscuela" title="Configurar datos de la escuela">
                    ⚙️
                </button>
            </div>
        </div>

        <!-- Banner informativo de sesión de escuela activa -->
        <div id="bannerEscuelaActiva" class="p-2 mb-2 rounded d-none" style="background: rgba(255,215,0,0.16); border: 1px solid #FFD166;">
            <div class="d-flex justify-content-between align-items-start">
                <div style="min-width:0; flex:1;">
                    <div class="fw-bold text-warning" style="font-size:.73rem; text-transform:uppercase;">
                        🏫 Escuela Activa
                    </div>
                    <div class="text-truncate fw-bold text-white" id="txtEscuelaActiva" style="font-size:.85rem;">—</div>
                    <div class="text-white-50" style="font-size:.70rem;">
                        ⏰ <span id="txtHorarioActivo" class="text-light">—</span> &bull; 👤 <span id="txtInstructorActivo" class="text-light">—</span>
                    </div>
                </div>
                <div class="ms-1">
                    <button type="button" class="btn btn-outline-warning btn-sm py-0 px-1" id="btnEditarSesionEscuela" title="Editar datos de escuela" style="font-size:.7rem;">✏️</button>
                </div>
            </div>
        </div>

        <form action="guardar_registro_manual.php" method="POST" autocomplete="off" id="formRegistroManual">

            <!-- Hidden fields para modo escuela -->
            <input type="hidden" name="es_escuela" id="inputEsEscuela" value="0">
            <input type="hidden" name="escuela_nombre" id="inputEscuelaNombre" value="">
            <input type="hidden" name="escuela_horario" id="inputEscuelaHorario" value="">
            <input type="hidden" name="escuela_instructor" id="inputEscuelaInstructor" value="">

            <div class="mb-2">
                <label class="form-label">Apellidos</label>
                <input name="apellidos" id="apellidosNuevo" class="form-control mayusc" placeholder="APELLIDOS" required>
            </div>
            <div class="mb-2">
                <label class="form-label">Nombres</label>
                <input name="nombres" id="nombresNuevo" class="form-control mayusc" placeholder="NOMBRES" required>
            </div>
            <div class="mb-2">
                <label class="form-label">DNI</label>
                <input name="dni" id="dniNuevo" class="form-control solo-num" placeholder="Sin puntos" inputmode="numeric" required>
            </div>
            <div class="mb-2">
                <label class="form-label">Edad</label>
                <input type="number" name="edad" id="edadNuevo" class="form-control" placeholder="Edad" min="14" max="99">
            </div>
            <div class="mb-2">
                <label class="form-label">Género</label>
                <select name="genero" id="generoNuevo" class="form-select" required>
                    <option value="">— Seleccioná —</option>
                    <?= opts($optsGenero) ?>
                </select>
            </div>

            <div class="mb-2">
                <label class="form-label">Lugar</label>
                <div id="bloqueLugarNormal">
                    <select name="lugar" id="selectLugarForm" class="form-select" required>
                        <option value="">— Lugar —</option>
                        <?= opts($optsLugar) ?>
                        <option value="OTRO">Otro...</option>
                    </select>
                    <input name="lugar_manual" id="inputLugarManual" class="form-control mt-1 d-none" placeholder="Especificá el lugar">
                </div>
                <div id="bloqueLugarEscuela" class="d-none">
                    <div class="form-control text-warning fw-bold text-truncate" id="badgeLugarEscuela" style="background: rgba(0,0,0,.45);">
                        —
                    </div>
                </div>
            </div>

            <div class="mb-2">
                <label class="form-label">Email</label>
                <input type="email" name="email" id="emailNuevo" class="form-control" placeholder="email@ejemplo.com">
            </div>
            <div class="mb-2">
                <label class="form-label">Licencia / Categoría</label>
                <select name="licencia" id="selectLicenciaForm" class="form-select" required>
                    <option value="">— Categoría —</option>
                    <?= opts($optsLicencia) ?>
                </select>
            </div>

            <div class="mb-2">
                <label class="form-label">Turno / Horario</label>
                <div id="bloqueTurnoNormal">
                    <select name="turno" id="selectTurnoForm" class="form-select" required>
                        <option value="">— Turno —</option>
                        <?= opts($optsTurno) ?>
                        <option value="otro">Otro...</option>
                    </select>
                    <input name="turno_manual" id="turnoManualForm" class="form-control mt-1 d-none" placeholder="HH:MM">
                </div>
                <div id="bloqueTurnoEscuela" class="d-none">
                    <div class="form-control text-warning fw-bold text-truncate" id="badgeTurnoEscuela" style="background: rgba(0,0,0,.45);">
                        —
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Fecha</label>
                <input type="date" name="fecha" id="fechaNuevo" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>

            <button type="submit" class="btn btn-main w-100" id="btnSubmitForm">Guardar</button>
        </form>
    </div>

    <!-- ══════════════════ TABLA ══════════════════ -->
    <div class="glass panel-tabla">
        <div class="d-flex align-items-center justify-content-between mb-2 flex-shrink-0">
            <h5 class="mb-0">📋 Últimos Registros</h5>
            <span id="barraEstado">Cargando...</span>
        </div>

        <div class="mb-2 flex-shrink-0">
            <input id="filtro" class="form-control form-control-sm"
                   placeholder="🔍 Filtrar por apellido, DNI, lugar, escuela...">
        </div>

        <div class="tabla-scroll">
            <table class="table table-borderless align-middle" id="tablaReg">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Fecha</th>
                        <th>Apellido</th>
                        <th>Nombre</th>
                        <th>DNI</th>
                        <th>Edad</th>
                        <th>Género</th>
                        <th>Lugar</th>
                        <th>Licencia</th>
                        <th>Turno</th>
                        <th>Email</th>
                        <th class="text-center" style="width: 50px;">Acción</th>
                    </tr>
                </thead>
                <tbody id="tbodyReg">
                <?php
                $primero = true;
                while ($r = $resInicial->fetch_assoc()):
                    $cls = $primero ? 'fila-top' : '';
                    $fechaFmt = $r['fecha'] ? date("d/m/Y", strtotime($r['fecha'])) : ($r['fecha_dia'] ? date("d/m/Y", strtotime($r['fecha_dia'])) : '-');
                    $esEsc = (!empty($r['es_escuela']) || preg_match('/\bESC(UELA)?\b/i', $r['lugar'] ?? ''));
                    $lugarTxt = htmlspecialchars($r['lugar'] ?? '-');
                    $lugarFmt = $esEsc
                        ? '<span class="badge bg-warning text-dark me-1" title="Capacitación en Escuela">🏫</span> ' . $lugarTxt
                        : $lugarTxt;
                ?>
                <?php $nombreCompleto = htmlspecialchars(trim(($r['apellidos'] ?? '') . ' ' . ($r['nombres'] ?? ''))); ?>
                <tr class="<?= $cls ?>" data-id="<?= $r['id'] ?>" data-nombre="<?= $nombreCompleto ?>">
                    <td><?= $r['id'] ?></td>
                    <td><?= $fechaFmt ?></td>
                    <td><?= htmlspecialchars($r['apellidos']) ?></td>
                    <td><?= htmlspecialchars($r['nombres']) ?></td>
                    <td><?= htmlspecialchars($r['dni']) ?></td>
                    <td><?= htmlspecialchars($r['edad'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($r['genero'] ?? '-') ?></td>
                    <td><?= $lugarFmt ?></td>
                    <td><?= htmlspecialchars($r['licencia'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($r['turno'] ?? '-') ?></td>
                    <td><?= htmlspecialchars(strtolower($r['email'] ?? '-')) ?></td>
                    <td class="text-center" style="white-space: nowrap;">
                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 btn-eliminar-asist"
                                data-id="<?= $r['id'] ?>"
                                data-nombre="<?= $nombreCompleto ?>"
                                title="Eliminar asistente">
                            <i class="bi bi-trash3-fill"></i>
                        </button>
                    </td>
                </tr>
                <?php $primero = false; endwhile; ?>
                </tbody>
            </table>
        </div>

        <div class="flex-shrink-0 mt-2">
            <a href="panel.php" class="btn btn-sm btn-secondary">⬅ Panel</a>
        </div>
    </div>

</div><!-- /layout -->

<!-- ══════════════════ MODAL CONFIGURACIÓN ESCUELA ══════════════════ -->
<div class="modal fade" id="modalDatosEscuela" tabindex="-1" data-bs-backdrop="static">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content" style="background: rgba(20,25,35,0.96); border: 1px solid rgba(255,215,0,0.4); border-radius: 18px;">

    <div class="modal-header border-bottom border-secondary border-opacity-25">
        <h5 class="modal-title text-warning">🏫 Configurar Ingreso a Escuela</h5>
        <button type="button" class="btn-close btn-close-white" id="btnCerrarCruzEscuela" data-bs-dismiss="modal"></button>
    </div>

    <div class="modal-body">
        <p class="small text-white-50 mb-3">
            Ingresá los datos de la institución y la capacitación. Luego podrás registrar individualmente cada asistente (apellido, nombre, DNI, etc.) con la marca de escuela asignada.
        </p>

        <div class="mb-3">
            <label class="form-label" style="color:#FFD166;">Nombre de la Escuela *</label>
            <input type="text" id="modalNomEscuela" class="form-control mayusc" placeholder="Ej: ESCUELA NORMAL SUPERIOR N° 30" list="listaEscuelasModal">
            <datalist id="listaEscuelasModal">
                <?php foreach ($escuelasExistentes as $esc): ?>
                    <option value="<?= htmlspecialchars($esc) ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <div id="errNomEscuela" class="text-danger small d-none mt-1">Por favor ingresá el nombre de la escuela.</div>
        </div>

        <div class="mb-3">
            <label class="form-label" style="color:#FFD166;">Horario / Turno *</label>
            <select id="modalTurnoEscuela" class="form-select mb-1">
                <option value="3° TURNO 8:30">3° TURNO 8:30</option>
                <option value="1° TURNO 13:30">1° TURNO 13:30</option>
                <option value="2° TURNO 20:00">2° TURNO 20:00</option>
                <option value="TURNO MAÑANA">TURNO MAÑANA</option>
                <option value="TURNO TARDE">TURNO TARDE</option>
                <option value="OTRO">Otro horario...</option>
            </select>
            <input type="text" id="modalTurnoManualEscuela" class="form-control d-none mt-1" placeholder="Ej: 10:15 / HH:MM">
        </div>

        <div class="mb-3">
            <label class="form-label" style="color:#FFD166;">Instructor a Cargo</label>
            <select id="modalInstructorEscuela" class="form-select mb-1">
                <?php foreach ($listaInstructores as $ins): ?>
                    <option value="<?= htmlspecialchars($ins) ?>" <?= ($ins === $instructorSesion) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ins) ?>
                    </option>
                <?php endforeach; ?>
                <option value="OTRO">Otro instructor...</option>
            </select>
            <input type="text" id="modalInstructorManual" class="form-control d-none mt-1 mayusc" placeholder="Nombre y Apellido">
        </div>
    </div>

    <div class="modal-footer border-top border-secondary border-opacity-25">
        <button type="button" class="btn btn-secondary" id="btnCancelarModalEscuela" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-warning fw-bold" id="btnAceptarModalEscuela">
            ✅ Aceptar e Iniciar Carga
        </button>
    </div>

</div>
</div>
</div>

<!-- ══════════════════ MODAL EDICIÓN ══════════════════ -->
<div class="modal fade" id="modalEditar" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content">

    <div class="modal-header">
        <h5 class="modal-title">✏️ Editar Registro</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>

    <div class="modal-body">

        <div id="modalMensaje" class="mb-3 d-none"></div>

        <div class="row g-2">

            <div class="col-md-6">
                <label class="form-label">Apellidos</label>
                <input id="mApellidos" class="form-control mayusc" placeholder="APELLIDOS">
            </div>
            <div class="col-md-6">
                <label class="form-label">Nombres</label>
                <input id="mNombres" class="form-control mayusc" placeholder="NOMBRES">
            </div>

            <div class="col-md-4">
                <label class="form-label">DNI</label>
                <input id="mDni" class="form-control solo-num" inputmode="numeric">
            </div>
            <div class="col-md-2">
                <label class="form-label">Edad</label>
                <input id="mEdad" type="number" class="form-control" min="14" max="99">
            </div>
            <div class="col-md-3">
                <label class="form-label">Género</label>
                <select id="mGenero" class="form-select">
                    <option value="">—</option>
                    <?= opts($optsGenero) ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Fecha</label>
                <input id="mFecha" type="date" class="form-control">
            </div>

            <div class="col-md-6">
                <label class="form-label">Lugar</label>
                <select id="mLugar" class="form-select">
                    <option value="">—</option>
                    <?= opts($optsLugar) ?>
                    <option value="OTRO">Otro...</option>
                </select>
                <input id="mLugarManual" class="form-control mt-1 d-none" placeholder="Especificá el lugar">
            </div>
            <div class="col-md-6">
                <label class="form-label">Email</label>
                <input id="mEmail" type="email" class="form-control" placeholder="email@ejemplo.com">
            </div>

            <div class="col-md-6">
                <label class="form-label">Licencia / Categoría</label>
                <select id="mLicencia" class="form-select">
                    <option value="">—</option>
                    <?= opts($optsLicencia) ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Turno</label>
                <select id="mTurno" class="form-select">
                    <option value="">—</option>
                    <?= opts($optsTurno) ?>
                    <option value="OTRO">Otro...</option>
                </select>
                <input id="mTurnoManual" class="form-control mt-1 d-none" placeholder="HH:MM">
            </div>

        </div>
    </div>

    <div class="modal-footer d-flex justify-content-between">
        <button type="button" class="btn btn-outline-danger" id="btnEliminarModal">
            <i class="bi bi-trash3-fill"></i> Eliminar
        </button>
        <div>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-warning fw-bold" id="btnGuardarModal">
                💾 Guardar cambios
            </button>
        </div>
    </div>

</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
/* ══════════════════════════════════════════════
   HELPERS
══════════════════════════════════════════════ */
const OPCIONES_LUGAR    = <?= json_encode($optsLugar,    JSON_UNESCAPED_UNICODE) ?>;
const OPCIONES_TURNO    = <?= json_encode($optsTurno,    JSON_UNESCAPED_UNICODE) ?>;
const OPCIONES_LICENCIA = <?= json_encode($optsLicencia, JSON_UNESCAPED_UNICODE) ?>;
const OPCIONES_GENERO   = <?= json_encode($optsGenero,   JSON_UNESCAPED_UNICODE) ?>;

function normStr(s) {
    return String(s ?? '').toUpperCase()
        .replace(/[ÁÀÂÄ]/g,'A').replace(/[ÉÈÊË]/g,'E')
        .replace(/[ÍÌÎÏ]/g,'I').replace(/[ÓÒÔÖ]/g,'O')
        .replace(/[ÚÙÛÜ]/g,'U').replace(/Ñ/g,'N');
}

function setSelect(sel, val) {
    const norm = normStr(val);
    let found = false;
    for (const opt of sel.options) {
        if (normStr(opt.value) === norm) {
            sel.value = opt.value;
            found = true;
            break;
        }
    }
    if (!found) sel.value = '';
    return found;
}

/* ── Mayúsculas en tiempo real ── */
document.addEventListener('input', e => {
    if (e.target.classList.contains('mayusc')) {
        const pos = e.target.selectionStart;
        e.target.value = e.target.value.toUpperCase();
        e.target.setSelectionRange(pos, pos);
    }
    if (e.target.classList.contains('solo-num')) {
        e.target.value = e.target.value.replace(/\D/g, '');
    }
});

/* ── Lugar "Otro" en formulario nuevo ── */
document.querySelector('[name="lugar"]').addEventListener('change', function () {
    document.querySelector('[name="lugar_manual"]').classList.toggle('d-none', this.value !== 'OTRO');
});

/* ── Turno "otro" en formulario nuevo ── */
document.getElementById('selectTurnoForm').addEventListener('change', function () {
    document.getElementById('turnoManualForm').classList.toggle('d-none', this.value !== 'otro');
});

/* ── Lugar "Otro" en modal editar ── */
document.getElementById('mLugar').addEventListener('change', function () {
    document.getElementById('mLugarManual').classList.toggle('d-none', this.value !== 'OTRO');
});

/* ── Turno "Otro" en modal editar ── */
document.getElementById('mTurno').addEventListener('change', function () {
    document.getElementById('mTurnoManual').classList.toggle('d-none', this.value !== 'OTRO');
});

/* ══════════════════════════════════════════════
   GESTIÓN SESIÓN ESCUELA
══════════════════════════════════════════════ */
const bsModalEscuela = new bootstrap.Modal(document.getElementById('modalDatosEscuela'));
const checkEscuela   = document.getElementById('checkEsEscuela');
const bannerEscuela  = document.getElementById('bannerEscuelaActiva');
const btnConfigEsc   = document.getElementById('btnConfigEscuela');

const bloqueLugarNormal  = document.getElementById('bloqueLugarNormal');
const bloqueLugarEscuela = document.getElementById('bloqueLugarEscuela');
const badgeLugarEscuela  = document.getElementById('badgeLugarEscuela');
const selectLugarForm    = document.getElementById('selectLugarForm');

const bloqueTurnoNormal  = document.getElementById('bloqueTurnoNormal');
const bloqueTurnoEscuela = document.getElementById('bloqueTurnoEscuela');
const badgeTurnoEscuela  = document.getElementById('badgeTurnoEscuela');
const selectTurnoForm    = document.getElementById('selectTurnoForm');

const inputEsEscuela          = document.getElementById('inputEsEscuela');
const inputEscuelaNombre       = document.getElementById('inputEscuelaNombre');
const inputEscuelaHorario      = document.getElementById('inputEscuelaHorario');
const inputEscuelaInstructor   = document.getElementById('inputEscuelaInstructor');

const modalNomEscuela         = document.getElementById('modalNomEscuela');
const modalTurnoEscuela       = document.getElementById('modalTurnoEscuela');
const modalTurnoManualEscuela = document.getElementById('modalTurnoManualEscuela');
const modalInstructorEscuela  = document.getElementById('modalInstructorEscuela');
const modalInstructorManual   = document.getElementById('modalInstructorManual');
const errNomEscuela           = document.getElementById('errNomEscuela');
const btnSubmitForm           = document.getElementById('btnSubmitForm');

let sesionEscuela = {
    activa: false,
    nombre: '',
    horario: '',
    instructor: ''
};

// Alternar manual en modal turno
modalTurnoEscuela.addEventListener('change', function() {
    modalTurnoManualEscuela.classList.toggle('d-none', this.value !== 'OTRO');
    if (this.value === 'OTRO') modalTurnoManualEscuela.focus();
});

// Alternar manual en modal instructor
modalInstructorEscuela.addEventListener('change', function() {
    modalInstructorManual.classList.toggle('d-none', this.value !== 'OTRO');
    if (this.value === 'OTRO') modalInstructorManual.focus();
});

// Click en switch escuela
checkEscuela.addEventListener('change', function() {
    if (this.checked) {
        errNomEscuela.classList.add('d-none');
        bsModalEscuela.show();
        setTimeout(() => modalNomEscuela.focus(), 400);
    } else {
        desactivarModoEscuela();
    }
});

// Botones para editar configuración de escuela
document.getElementById('btnEditarSesionEscuela').addEventListener('click', () => {
    abrirModalConfigEscuela();
});
btnConfigEsc.addEventListener('click', () => {
    abrirModalConfigEscuela();
});

function abrirModalConfigEscuela() {
    modalNomEscuela.value = sesionEscuela.nombre;
    const okTurno = setSelect(modalTurnoEscuela, sesionEscuela.horario);
    if (!okTurno && sesionEscuela.horario) {
        modalTurnoEscuela.value = 'OTRO';
        modalTurnoManualEscuela.value = sesionEscuela.horario;
        modalTurnoManualEscuela.classList.remove('d-none');
    }
    const okInst = setSelect(modalInstructorEscuela, sesionEscuela.instructor);
    if (!okInst && sesionEscuela.instructor) {
        modalInstructorEscuela.value = 'OTRO';
        modalInstructorManual.value = sesionEscuela.instructor;
        modalInstructorManual.classList.remove('d-none');
    }
    errNomEscuela.classList.add('d-none');
    bsModalEscuela.show();
}

// Cancelar modal
function cancelarModal() {
    if (!sesionEscuela.activa) {
        checkEscuela.checked = false;
        desactivarModoEscuela();
    }
}
document.getElementById('btnCancelarModalEscuela').addEventListener('click', cancelarModal);
document.getElementById('btnCerrarCruzEscuela').addEventListener('click', cancelarModal);

// Aceptar modal escuela
document.getElementById('btnAceptarModalEscuela').addEventListener('click', () => {
    const nom = modalNomEscuela.value.trim().toUpperCase();
    if (nom === '') {
        errNomEscuela.classList.remove('d-none');
        modalNomEscuela.focus();
        return;
    }
    errNomEscuela.classList.add('d-none');

    let horario = modalTurnoEscuela.value;
    if (horario === 'OTRO') {
        horario = modalTurnoManualEscuela.value.trim().toUpperCase() || 'TURNO MAÑANA';
    }

    let instructor = modalInstructorEscuela.value;
    if (instructor === 'OTRO') {
        instructor = modalInstructorManual.value.trim().toUpperCase();
    }

    sesionEscuela.activa = true;
    sesionEscuela.nombre = nom;
    sesionEscuela.horario = horario;
    sesionEscuela.instructor = instructor;

    activarModoEscuelaUI();
    bsModalEscuela.hide();
    document.getElementById('apellidosNuevo').focus();
});

function activarModoEscuelaUI() {
    checkEscuela.checked = true;
    inputEsEscuela.value = '1';
    inputEscuelaNombre.value = sesionEscuela.nombre;
    inputEscuelaHorario.value = sesionEscuela.horario;
    inputEscuelaInstructor.value = sesionEscuela.instructor;

    document.getElementById('txtEscuelaActiva').textContent = sesionEscuela.nombre;
    document.getElementById('txtHorarioActivo').textContent = sesionEscuela.horario;
    document.getElementById('txtInstructorActivo').textContent = sesionEscuela.instructor || 'Sin especificar';

    bannerEscuela.classList.remove('d-none');
    btnConfigEsc.classList.remove('d-none');

    // Bloquear Lugar con el nombre de la escuela
    bloqueLugarNormal.classList.add('d-none');
    bloqueLugarEscuela.classList.remove('d-none');
    badgeLugarEscuela.textContent = '🏫 ' + sesionEscuela.nombre;
    selectLugarForm.required = false;

    // Bloquear Turno con el horario configurado
    bloqueTurnoNormal.classList.add('d-none');
    bloqueTurnoEscuela.classList.remove('d-none');
    badgeTurnoEscuela.textContent = '⏰ ' + sesionEscuela.horario;
    selectTurnoForm.required = false;

    // Preseleccionar categoría para escuela
    setSelect(document.getElementById('selectLicenciaForm'), 'ESCUELAS/COLEGIOS');

    btnSubmitForm.textContent = '➕ Guardar Asistente (Escuela)';
}

function desactivarModoEscuela() {
    sesionEscuela.activa = false;
    checkEscuela.checked = false;
    inputEsEscuela.value = '0';
    inputEscuelaNombre.value = '';
    inputEscuelaHorario.value = '';
    inputEscuelaInstructor.value = '';

    bannerEscuela.classList.add('d-none');
    btnConfigEsc.classList.add('d-none');

    bloqueLugarEscuela.classList.add('d-none');
    bloqueLugarNormal.classList.remove('d-none');
    selectLugarForm.required = true;

    bloqueTurnoEscuela.classList.add('d-none');
    bloqueTurnoNormal.classList.remove('d-none');
    selectTurnoForm.required = true;

    btnSubmitForm.textContent = 'Guardar';
}

/* ══════════════════════════════════════════════
   MODAL EDICIÓN — abrir con datos de la fila
══════════════════════════════════════════════ */
let modalIdActivo = null;
const bsModal = new bootstrap.Modal(document.getElementById('modalEditar'));

document.getElementById('tbodyReg').addEventListener('click', e => {
    const btnDel = e.target.closest('.btn-eliminar-asist');
    if (btnDel) {
        e.stopPropagation();
        const id = btnDel.dataset.id;
        const nom = btnDel.dataset.nombre || 'este asistente';
        confirmarEliminarRegistro(id, nom);
        return;
    }

    const tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    abrirModalEditar(tr.dataset.id);
});

async function confirmarEliminarRegistro(id, nom) {
    if (!confirm(`¿Estás seguro de que deseás eliminar el registro de "${nom}" (ID: ${id})?`)) {
        return;
    }
    const body = new FormData();
    body.append('id', id);
    try {
        const resp = await fetch('eliminar_registro_manual.php', { method: 'POST', body });
        const data = await resp.json();
        if (data.ok) {
            refrescarTabla();
        } else {
            alert('❌ ' + data.msg);
        }
    } catch {
        alert('❌ Error de conexión al eliminar.');
    }
}

async function abrirModalEditar(id) {
    const resp = await fetch(`tabla_asistencias.php?get_id=${encodeURIComponent(id)}`);
    const data = await resp.json();
    if (!data || data.error) {
        alert('No se pudieron cargar los datos del registro.');
        return;
    }

    modalIdActivo = id;

    document.getElementById('mApellidos').value = data.apellidos ?? '';
    document.getElementById('mNombres').value   = data.nombres   ?? '';
    document.getElementById('mDni').value        = data.dni       ?? '';
    document.getElementById('mEdad').value       = data.edad      ?? '';
    document.getElementById('mEmail').value      = data.email     ?? '';
    document.getElementById('mFecha').value      = data.fecha_dia ?? '';

    setSelect(document.getElementById('mGenero'),   data.genero   ?? '');
    setSelect(document.getElementById('mLicencia'), data.licencia ?? '');

    // Lugar
    const lugarOk = setSelect(document.getElementById('mLugar'), data.lugar ?? '');
    if (!lugarOk && data.lugar) {
        document.getElementById('mLugar').value       = 'OTRO';
        document.getElementById('mLugarManual').value = data.lugar;
        document.getElementById('mLugarManual').classList.remove('d-none');
    } else {
        document.getElementById('mLugarManual').classList.add('d-none');
    }

    // Turno
    const turnoOk = setSelect(document.getElementById('mTurno'), data.turno ?? '');
    if (!turnoOk && data.turno) {
        document.getElementById('mTurno').value       = 'OTRO';
        document.getElementById('mTurnoManual').value = data.turno;
        document.getElementById('mTurnoManual').classList.remove('d-none');
    } else {
        document.getElementById('mTurnoManual').classList.add('d-none');
    }

    const msg = document.getElementById('modalMensaje');
    msg.className = 'mb-3 d-none';
    msg.textContent = '';

    bsModal.show();
}

/* ══════════════════════════════════════════════
   GUARDAR DESDE MODAL EDICIÓN
══════════════════════════════════════════════ */
document.getElementById('btnEliminarModal').addEventListener('click', async () => {
    if (!modalIdActivo) return;
    const ape = document.getElementById('mApellidos').value;
    const nom = document.getElementById('mNombres').value;
    const nomCompleto = (ape + ' ' + nom).trim() || 'este asistente';
    if (!confirm(`¿Eliminar definitivamente a "${nomCompleto}" (ID: ${modalIdActivo})?`)) {
        return;
    }
    const body = new FormData();
    body.append('id', modalIdActivo);
    try {
        const resp = await fetch('eliminar_registro_manual.php', { method: 'POST', body });
        const data = await resp.json();
        if (data.ok) {
            bsModal.hide();
            refrescarTabla();
        } else {
            alert('❌ ' + data.msg);
        }
    } catch {
        alert('❌ Error de conexión al eliminar.');
    }
});

document.getElementById('btnGuardarModal').addEventListener('click', async () => {
    const btn  = document.getElementById('btnGuardarModal');
    const msg  = document.getElementById('modalMensaje');

    const lugar = document.getElementById('mLugar').value === 'OTRO'
        ? document.getElementById('mLugarManual').value
        : document.getElementById('mLugar').value;

    const turno = document.getElementById('mTurno').value === 'OTRO'
        ? document.getElementById('mTurnoManual').value
        : document.getElementById('mTurno').value;

    const body = new FormData();
    body.append('id',        modalIdActivo);
    body.append('apellidos', document.getElementById('mApellidos').value);
    body.append('nombres',   document.getElementById('mNombres').value);
    body.append('dni',       document.getElementById('mDni').value);
    body.append('edad',      document.getElementById('mEdad').value);
    body.append('genero',    document.getElementById('mGenero').value);
    body.append('email',     document.getElementById('mEmail').value);
    body.append('licencia',  document.getElementById('mLicencia').value);
    body.append('fecha',     document.getElementById('mFecha').value);
    body.append('lugar',     lugar);
    body.append('turno',     turno);

    btn.disabled = true;
    btn.textContent = 'Guardando...';

    try {
        const resp = await fetch('guardar_registro_manual.php', { method: 'POST', body });
        const data = await resp.json();

        msg.className = `mb-3 alert ${data.ok ? 'alert-success' : 'alert-danger'}`;
        msg.textContent = data.ok ? '✅ ' + data.msg : '❌ ' + data.msg;
        msg.classList.remove('d-none');

        if (data.ok) {
            setTimeout(() => {
                bsModal.hide();
                refrescarTabla();
            }, 1200);
        }
    } catch {
        msg.className = 'mb-3 alert alert-danger';
        msg.textContent = '❌ Error de conexión.';
        msg.classList.remove('d-none');
    }

    btn.disabled = false;
    btn.textContent = '💾 Guardar cambios';
});

/* ══════════════════════════════════════════════
   AUTO-REFRESH DE TABLA
══════════════════════════════════════════════ */
let intervaloRef = null;
let segundos     = 8;
const elEstado   = document.getElementById('barraEstado');

document.getElementById('modalEditar').addEventListener('show.bs.modal',  () => {
    clearInterval(intervaloRef);
    elEstado.textContent = '⏸ Refresco pausado (modal abierto)';
});
document.getElementById('modalEditar').addEventListener('hidden.bs.modal', () => {
    iniciarRefresco();
});

async function refrescarTabla() {
    const filtro = document.getElementById('filtro').value.toLowerCase();
    try {
        const resp = await fetch('tabla_asistencias.php');
        const html = await resp.text();
        document.getElementById('tbodyReg').innerHTML = html;
        if (filtro) aplicarFiltro(filtro);
    } catch {
        // silencioso
    }
}

/* ── Formulario nuevo → AJAX ── */
document.getElementById('formRegistroManual')
    .addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]');
        const textoOriginal = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Guardando...';

        try {
            const resp = await fetch('guardar_registro_manual.php', {
                method: 'POST',
                body: new FormData(this)
            });
            const data = await resp.json();

            if (data.ok) {
                // Limpiar solo datos del asistente
                document.getElementById('apellidosNuevo').value = '';
                document.getElementById('nombresNuevo').value   = '';
                document.getElementById('dniNuevo').value       = '';
                document.getElementById('edadNuevo').value      = '';
                document.getElementById('emailNuevo').value     = '';

                if (!sesionEscuela.activa) {
                    document.getElementById('generoNuevo').value = '';
                    selectLugarForm.value = '';
                    document.getElementById('inputLugarManual').value = '';
                    document.getElementById('inputLugarManual').classList.add('d-none');
                    selectTurnoForm.value = '';
                    document.getElementById('turnoManualForm').value = '';
                    document.getElementById('turnoManualForm').classList.add('d-none');
                    document.getElementById('selectLicenciaForm').value = '';
                }

                document.getElementById('apellidosNuevo').focus();
                refrescarTabla();
            } else {
                alert('❌ ' + data.msg);
            }
        } catch {
            alert('❌ Error de conexión.');
        }

        btn.disabled = false;
        btn.textContent = textoOriginal;
    });

function iniciarRefresco() {
    segundos = 8;
    clearInterval(intervaloRef);
    intervaloRef = setInterval(() => {
        segundos--;
        if (segundos <= 0) {
            segundos = 8;
            refrescarTabla();
            elEstado.textContent = `✅ Actualizado — próximo en ${segundos}s`;
        } else {
            elEstado.textContent = `🔄 Próximo refresco en ${segundos}s`;
        }
    }, 1000);
}

/* ══════════════════════════════════════════════
   FILTRO TABLA
══════════════════════════════════════════════ */
function aplicarFiltro(v) {
    document.querySelectorAll('#tbodyReg tr').forEach(tr => {
        tr.style.display = tr.innerText.toLowerCase().includes(v) ? '' : 'none';
    });
}

document.getElementById('filtro').addEventListener('input', function () {
    aplicarFiltro(this.value.toLowerCase());
});

/* ── Arrancar ── */
iniciarRefresco();
document.getElementById('apellidosNuevo').focus();
</script>

</body>
</html>
