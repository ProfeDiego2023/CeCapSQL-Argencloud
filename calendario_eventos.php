<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['id_instru'])) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/calendario_notificaciones_fn.php";

proteger();
if (!permiso('calendario')) {
    die("Acceso denegado");
}

calNotif_asegurarTablas($conn);

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$msg = "";

// ================= CALENDARIO JSON =================
if (isset($_GET['cal'])) {
    header('Content-Type: application/json');
    $out = [];
    $res = $conn->query("SELECT * FROM calendario_eventos ORDER BY fecha_desde ASC");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $end = date('Y-m-d', strtotime($r['fecha_hasta'] . ' +1 day'));
            $color = match ($r['estado'] ?? 'programado') {
                'programado' => '#C7E4CF',
                'en_curso'    => '#FFD166',
                'finalizado'  => '#6c757d',
                'cancelado'   => '#DD4841',
                default       => '#C7E4CF',
            };
            $title = $r['titulo'];
            if (!empty($r['hora_desde'])) {
                $title .= " (" . substr($r['hora_desde'], 0, 5) . "-" . substr($r['hora_hasta'], 0, 5) . ")";
            }
            $out[] = [
                "id"    => $r['id'],
                "title" => $title,
                "start" => $r['fecha_desde'],
                "end"   => $end,
                "color" => $color,
                "extendedProps" => [
                    'lugar'     => $r['lugar'],
                    'estado'    => $r['estado'],
                ],
            ];
        }
    }
    echo json_encode($out);
    exit;
}

// ================= ELIMINAR =================
if (isset($_GET['eliminar'])) {
    $id = (int)$_GET['eliminar'];
    $conn->query("DELETE FROM calendario_eventos WHERE id=$id");
    $conn->query("DELETE FROM calendario_notificaciones WHERE evento_id=$id");
    header("Location: calendario_eventos.php");
    exit();
}

// ================= TOGGLE ESTADO =================
if (isset($_GET['toggle_estado'])) {
    $id = (int)$_GET['toggle_estado'];
    $conn->query("UPDATE calendario_eventos SET estado = IF(estado='programado','cancelado','programado') WHERE id=$id");
    header("Location: calendario_eventos.php");
    exit();
}

// ================= ENVIAR NOTIFICACIONES AHORA =================
if (isset($_GET['enviar_notif'])) {
    $id = (int)$_GET['enviar_notif'];
    $st = $conn->prepare("SELECT * FROM calendario_eventos WHERE id = ? LIMIT 1");
    $st->bind_param("i", $id);
    $st->execute();
    $evento = $st->get_result()->fetch_assoc();
    $st->close();

    if ($evento) {
        $programado = date('Y-m-d H:i:s');
        $rEmail = ['ok' => false, 'msg' => ''];
        $rWhatsapp = ['ok' => false, 'msg' => ''];

        if ((int)$evento['aviso_email'] && !empty($evento['destinatarios_email'])) {
            $rEmail = calNotif_enviarEmail($conn, $evento, $evento['destinatarios_email']);
            calNotif_registrarNotificacion($conn, $id, 'email', $evento['destinatarios_email'], $programado, $rEmail['ok'] ? 'enviado' : 'error', $rEmail['msg']);
        }
        if ((int)$evento['aviso_whatsapp'] && !empty($evento['destinatarios_whatsapp'])) {
            calNotif_registrarNotificacion($conn, $id, 'whatsapp', $evento['destinatarios_whatsapp'], $programado, 'enviado', 'Enviado manualmente');
            $rWhatsapp = ['ok' => true, 'msg' => 'Links de WhatsApp generados'];
        }
        if ((int)$evento['aviso_modal']) {
            calNotif_registrarNotificacion($conn, $id, 'modal', 'panel', $programado, 'enviado', 'Modal activo');
        }

        $msgs = [];
        if ((int)$evento['aviso_email']) $msgs[] = "Email: " . $rEmail['msg'];
        if ((int)$evento['aviso_whatsapp']) $msgs[] = "WhatsApp: listo";
        if ((int)$evento['aviso_modal']) $msgs[] = "Modal: activado";
        $msg = "<div class='alert alert-success'>Notificaciones enviadas para evento #" . $id . ": " . implode(' | ', $msgs) . "</div>";
    }
}

// ================= EDITAR (GET) =================
$editando = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $st = $conn->prepare("SELECT * FROM calendario_eventos WHERE id = ? LIMIT 1");
    $st->bind_param("i", $editId);
    $st->execute();
    $editando = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$editando) {
        header("Location: calendario_eventos.php");
        exit();
    }
}

// ================= ACTUALIZAR (POST) =================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['_accion']) && $_POST['_accion'] === 'editar') {
    $editId          = (int)($_POST['edit_id'] ?? 0);
    $titulo          = trim($_POST['titulo'] ?? '');
    $descripcion     = trim($_POST['descripcion'] ?? '');
    $fecha_desde     = $_POST['fecha_desde'] ?? '';
    $fecha_hasta     = $_POST['fecha_hasta'] ?? '';
    $hora_desde      = trim($_POST['hora_desde'] ?? '');
    $hora_hasta      = trim($_POST['hora_hasta'] ?? '');
    $lugar           = trim($_POST['lugar'] ?? '');
    $capacidad       = (int)($_POST['capacidad'] ?? 0) ?: null;
    $dest_email      = trim($_POST['destinatarios_email'] ?? '');
    $dest_whatsapp   = trim($_POST['destinatarios_whatsapp'] ?? '');
    $aviso_email     = isset($_POST['aviso_email']) ? 1 : 0;
    $aviso_whatsapp  = isset($_POST['aviso_whatsapp']) ? 1 : 0;
    $aviso_modal     = isset($_POST['aviso_modal']) ? 1 : 0;
    $dias_anticip    = max(1, (int)($_POST['dias_anticipacion'] ?? 1));
    $estado          = $_POST['estado'] ?? 'programado';

    if ($titulo === '' || $fecha_desde === '' || $fecha_hasta === '' || $lugar === '') {
        $msg = "<div class='alert alert-danger'>Completá título, fechas y lugar.</div>";
        $st = $conn->prepare("SELECT * FROM calendario_eventos WHERE id = ? LIMIT 1");
        $st->bind_param("i", $editId);
        $st->execute();
        $editando = $st->get_result()->fetch_assoc();
        $st->close();
    } else {
        $hora_desde_db = ($hora_desde === '') ? null : $hora_desde . ':00';
        $hora_hasta_db = ($hora_hasta === '') ? null : $hora_hasta . ':00';

        $st = $conn->prepare("
            UPDATE calendario_eventos SET
                titulo=?, descripcion=?, fecha_desde=?, fecha_hasta=?,
                hora_desde=?, hora_hasta=?, lugar=?, capacidad=?,
                destinatarios_email=?, destinatarios_whatsapp=?,
                aviso_email=?, aviso_whatsapp=?, aviso_modal=?,
                dias_anticipacion=?, estado=?
            WHERE id=?
        ");
        $st->bind_param(
            "sssssssissiiiisi",
            $titulo, $descripcion, $fecha_desde, $fecha_hasta,
            $hora_desde_db, $hora_hasta_db, $lugar, $capacidad,
            $dest_email, $dest_whatsapp,
            $aviso_email, $aviso_whatsapp, $aviso_modal,
            $dias_anticip, $estado, $editId
        );
        if ($st->execute()) {
            $msg = "<div class='alert alert-success'>Evento actualizado correctamente.</div>";
        } else {
            $msg = "<div class='alert alert-danger'>Error: " . $st->error . "</div>";
        }
        $st->close();
        $editando = null;
    }
}

// ================= CREAR (POST) =================
if ($_SERVER["REQUEST_METHOD"] === "POST" && !isset($_POST['_accion'])) {
    $titulo          = trim($_POST['titulo'] ?? '');
    $descripcion     = trim($_POST['descripcion'] ?? '');
    $fecha_desde     = $_POST['fecha_desde'] ?? '';
    $fecha_hasta     = $_POST['fecha_hasta'] ?? '';
    $hora_desde      = trim($_POST['hora_desde'] ?? '');
    $hora_hasta      = trim($_POST['hora_hasta'] ?? '');
    $lugar           = trim($_POST['lugar'] ?? '');
    $capacidad       = (int)($_POST['capacidad'] ?? 0) ?: null;
    $dest_email      = trim($_POST['destinatarios_email'] ?? '');
    $dest_whatsapp   = trim($_POST['destinatarios_whatsapp'] ?? '');
    $aviso_email     = isset($_POST['aviso_email']) ? 1 : 0;
    $aviso_whatsapp  = isset($_POST['aviso_whatsapp']) ? 1 : 0;
    $aviso_modal     = isset($_POST['aviso_modal']) ? 1 : 0;
    $dias_anticip    = max(1, (int)($_POST['dias_anticipacion'] ?? 1));

    if ($titulo === '' || $fecha_desde === '' || $fecha_hasta === '' || $lugar === '') {
        $msg = "<div class='alert alert-danger'>Completá título, fechas y lugar.</div>";
    } else {
        $hora_desde_db = ($hora_desde === '') ? null : $hora_desde . ':00';
        $hora_hasta_db = ($hora_hasta === '') ? null : $hora_hasta . ':00';

        $st = $conn->prepare("
            INSERT INTO calendario_eventos
                (titulo, descripcion, fecha_desde, fecha_hasta, hora_desde, hora_hasta,
                 lugar, capacidad, destinatarios_email, destinatarios_whatsapp,
                 aviso_email, aviso_whatsapp, aviso_modal, dias_anticipacion, creado_por)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $userId = (int)$_SESSION['id_instru'];
        $st->bind_param(
            "sssssssissiiiii",
            $titulo, $descripcion, $fecha_desde, $fecha_hasta,
            $hora_desde_db, $hora_hasta_db, $lugar, $capacidad,
            $dest_email, $dest_whatsapp,
            $aviso_email, $aviso_whatsapp, $aviso_modal, $dias_anticip, $userId
        );
        if ($st->execute()) {
            $msg = "<div class='alert alert-success'>Evento creado correctamente.</div>";
        } else {
            $msg = "<div class='alert alert-danger'>Error: " . $st->error . "</div>";
        }
        $st->close();
    }
}

// ================= LISTADO =================
$lista = [];
$res = $conn->query("SELECT * FROM calendario_eventos ORDER BY fecha_desde DESC, created_at DESC");
if ($res) while ($row = $res->fetch_assoc()) $lista[] = $row;

// ================= PROCESAR AVISOS PENDIENTES =================
calNotif_procesarPendientes($conn);

// ================= WHATSAPP LINKS JSON =================
if (isset($_GET['wpp_links'])) {
    header('Content-Type: application/json');
    $wppId = (int)$_GET['wpp_links'];
    $st = $conn->prepare("SELECT * FROM calendario_eventos WHERE id = ? LIMIT 1");
    $st->bind_param("i", $wppId);
    $st->execute();
    $wppEvento = $st->get_result()->fetch_assoc();
    $st->close();

    $links = [];
    if ($wppEvento) {
        $links = calNotif_generarLinksWhatsApp($wppEvento);
    }
    echo json_encode(['links' => $links]);
    exit;
}
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Calendario - Programación de Eventos</title>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/variables.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            padding: 18px;
            background: linear-gradient(135deg, #504254, #3C313F);
            background-attachment: fixed;
            color: #fff;
        }

        .cardx {
            max-width: 1200px;
            margin: 0 auto;
            background: rgba(10, 18, 32, .78);
            border: 1px solid rgba(255, 255, 255, .14);
            border-radius: 22px;
            backdrop-filter: blur(14px);
            padding: 22px;
            box-shadow: 0 18px 55px rgba(0, 0, 0, .60);
        }

        .form-control,
        .form-select {
            background: rgba(0, 0, 0, .35) !important;
            border: 1px solid rgba(255, 255, 255, .14) !important;
            color: #fff !important;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #5D5E70 !important;
            box-shadow: 0 0 0 2px rgba(93, 94, 112, .25) !important;
        }

        .form-control::placeholder {
            color: rgba(255, 255, 255, .55) !important;
        }

        .form-label {
            color: #C7E4CF;
            font-weight: 600;
            font-size: .85rem;
        }

        .table {
            --bs-table-bg: transparent;
            --bs-table-color: #fff;
            --bs-table-border-color: rgba(255, 255, 255, .12);
        }

        .badge {
            font-weight: 800;
        }

        .seccion-titulo {
            font-size: .8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #5D5E70;
            font-weight: 800;
            margin-bottom: 8px;
            margin-top: 12px;
        }

        .notif-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: .75rem;
            padding: 2px 8px;
            border-radius: 20px;
            font-weight: 700;
        }

        .notif-tag.email {
            background: rgba(13, 202, 240, .18);
            color: #0DCAF0;
            border: 1px solid rgba(13, 202, 240, .35);
        }

        .notif-tag.whatsapp {
            background: rgba(37, 211, 102, .18);
            color: #25D366;
            border: 1px solid rgba(37, 211, 102, .35);
        }

        .notif-tag.modal {
            background: rgba(255, 209, 102, .18);
            color: #FFD166;
            border: 1px solid rgba(255, 209, 102, .35);
        }

        .estado-programado {
            background: rgba(199, 228, 207, .18);
            color: #C7E4CF;
            border: 1px solid rgba(199, 228, 207, .35);
        }

        .estado-en_curso {
            background: rgba(255, 209, 102, .18);
            color: #FFD166;
            border: 1px solid rgba(255, 209, 102, .35);
        }

        .estado-finalizado {
            background: rgba(108, 117, 125, .25);
            color: #adb5bd;
            border: 1px solid rgba(108, 117, 125, .45);
        }

        .estado-cancelado {
            background: rgba(221, 72, 65, .18);
            color: #DD4841;
            border: 1px solid rgba(221, 72, 65, .35);
        }

        .whatsapp-link {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: #25D366;
            text-decoration: none;
            font-size: .82rem;
            font-weight: 600;
        }

        .whatsapp-link:hover {
            color: #128C7E;
        }

        .fc .fc-toolbar-title {
            font-size: 1.1rem;
        }

        .fc .fc-button {
            background: rgba(221, 72, 65, .85);
            border-color: rgba(221, 72, 65, .85);
        }

        .fc .fc-button:hover {
            background: #C0302A;
        }

        .fc .fc-today-button {
            background: rgba(93, 94, 112, .85);
        }

        .fc .fc-daygrid-day-number,
        .fc .fc-col-header-cell-cushion {
            color: #F0EDF2;
        }

        .fc .fc-day-today {
            background: rgba(199, 228, 207, .12) !important;
        }

        .fc-event {
            cursor: pointer;
            font-size: .78rem;
        }

        .modal:not(.show) {
            display: none !important;
            pointer-events: none !important;
        }

        .modal-backdrop {
            z-index: 1040 !important;
        }

        .modal {
            z-index: 1050 !important;
        }

        .modal-glass .modal-content {
            background: rgba(45, 36, 48, 0.95);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 20px;
            color: #F0EDF2;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.65);
        }

        .modal-glass .modal-header {
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
        }

        .modal-glass .btn-close {
            filter: invert(1);
        }
    </style>
</head>

<body>
    <div class="cardx">

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div>
                <h3 class="mb-0"><i class="bi bi-calendar2-event-fill"></i> Calendario - Programación de Eventos</h3>
                <div style="opacity:.8">Gestioná capacitaciones, eventos y enviá avisos por email, WhatsApp y modal.</div>
            </div>
            <a href="panel.php" class="btn btn-outline-light"><i class="bi bi-arrow-left"></i> Volver al panel</a>
        </div>

        <?= $msg ?>

        <!-- ====== CREAR + CALENDARIO ====== -->
        <div class="row g-3 mt-1">

            <!-- Formulario crear -->
            <div class="col-12 col-lg-5">
                <div class="p-3 rounded-4" style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);">
                    <h5 class="mb-3"><i class="bi bi-plus-circle-fill"></i> Crear evento</h5>
                    <form method="post">
                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label">Título *</label>
                                <input name="titulo" class="form-control" required
                                    placeholder="Ej: Capacitación Escuela San Martín">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Descripción</label>
                                <textarea name="descripcion" class="form-control" rows="2"
                                    placeholder="Detalle del evento..."></textarea>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Fecha desde *</label>
                                <input type="date" name="fecha_desde" class="form-control" required id="fecha_desde">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Fecha hasta *</label>
                                <input type="date" name="fecha_hasta" class="form-control" required id="fecha_hasta">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Hora desde</label>
                                <input type="time" name="hora_desde" class="form-control" step="60">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Hora hasta</label>
                                <input type="time" name="hora_hasta" class="form-control" step="60">
                            </div>
                            <div class="col-8">
                                <label class="form-label">Lugar *</label>
                                <input name="lugar" class="form-control" required
                                    placeholder="Ej: Escuela / Salón Vecinal">
                            </div>
                            <div class="col-4">
                                <label class="form-label">Capacidad</label>
                                <input type="number" name="capacidad" class="form-control" min="1">
                            </div>

                            <div class="col-12">
                                <hr class="border-secondary my-1">
                                <div class="seccion-titulo">Canales de notificación</div>
                            </div>

                            <div class="col-12 col-md-4">
                                <div class="form-check form-switch mb-1">
                                    <input class="form-check-input" type="checkbox" id="aviso_email" name="aviso_email" checked>
                                    <label class="form-check-label" for="aviso_email">
                                        <i class="bi bi-envelope-fill text-info"></i> Email
                                    </label>
                                </div>
                                <textarea name="destinatarios_email" class="form-control" rows="2" id="dest_email"
                                    placeholder="email1@ej.com, email2@ej.com"></textarea>
                            </div>
                            <div class="col-12 col-md-4">
                                <div class="form-check form-switch mb-1">
                                    <input class="form-check-input" type="checkbox" id="aviso_whatsapp" name="aviso_whatsapp">
                                    <label class="form-check-label" for="aviso_whatsapp">
                                        <i class="bi bi-whatsapp" style="color:#25D366"></i> WhatsApp
                                    </label>
                                </div>
                                <textarea name="destinatarios_whatsapp" class="form-control" rows="2" id="dest_wpp"
                                    placeholder="3421234567, 3427654321"></textarea>
                            </div>
                            <div class="col-12 col-md-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="aviso_modal" name="aviso_modal" checked>
                                    <label class="form-check-label" for="aviso_modal">
                                        <i class="bi bi-window-stack text-warning"></i> Modal en Panel
                                    </label>
                                </div>
                                <div class="form-text text-white-50" style="font-size:.78rem">
                                    Aviso emergente al ingresar al panel.
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Días de anticipación del aviso</label>
                                <input type="number" name="dias_anticipacion" class="form-control" min="1" max="30" value="1">
                            </div>
                        </div>
                        <button class="btn btn-primary w-100 mt-3 fw-bold">
                            <i class="bi bi-calendar-plus-fill"></i> Crear evento
                        </button>
                    </form>
                </div>
            </div>

            <!-- Calendario -->
            <div class="col-12 col-lg-7">
                <div class="p-3 rounded-4" style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);">
                    <h5 class="mb-3"><i class="bi bi-calendar3"></i> Calendario</h5>
                    <div id="calendario"></div>
                    <div class="d-flex gap-3 mt-2" style="font-size:.78rem;opacity:.7">
                        <span><span style="display:inline-block;width:12px;height:12px;background:#C7E4CF;border-radius:3px;vertical-align:middle"></span> Programado</span>
                        <span><span style="display:inline-block;width:12px;height:12px;background:#FFD166;border-radius:3px;vertical-align:middle"></span> En curso</span>
                        <span><span style="display:inline-block;width:12px;height:12px;background:#6c757d;border-radius:3px;vertical-align:middle"></span> Finalizado</span>
                        <span><span style="display:inline-block;width:12px;height:12px;background:#DD4841;border-radius:3px;vertical-align:middle"></span> Cancelado</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ====== TABLA EVENTOS ====== -->
        <div class="p-3 rounded-4 mt-4" style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);">
            <h5 class="mb-3"><i class="bi bi-list-ul"></i> Eventos programados</h5>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Estado</th>
                            <th>Título</th>
                            <th>Fecha</th>
                            <th>Hora</th>
                            <th>Lugar</th>
                            <th>Cap.</th>
                            <th>Avisos</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lista as $e): ?>
                            <tr>
                                <td>
                                    <span class="notif-tag estado-<?= h($e['estado']) ?>"><?= h(ucfirst(str_replace('_', ' ', $e['estado']))) ?></span>
                                </td>
                                <td>
                                    <b><?= h($e['titulo']) ?></b>
                                    <?php if (!empty($e['descripcion'])): ?>
                                        <div style="font-size:.75rem;opacity:.7;max-width:250px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                                            <?= h($e['descripcion']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space:nowrap">
                                    <?= date('d/m/Y', strtotime($e['fecha_desde'])) ?>
                                    <?php if ($e['fecha_desde'] !== $e['fecha_hasta']): ?>
                                        <br><small style="opacity:.7">al <?= date('d/m/Y', strtotime($e['fecha_hasta'])) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $e['hora_desde']
                                        ? h(substr($e['hora_desde'], 0, 5)) . "–" . h(substr($e['hora_hasta'], 0, 5))
                                        : "<span style='opacity:.5'>Todo el día</span>" ?>
                                </td>
                                <td><?= h($e['lugar']) ?></td>
                                <td><?= $e['capacidad'] ? h($e['capacidad']) : '—' ?></td>
                                <td>
                                    <?php if ((int)$e['aviso_email']): ?>
                                        <span class="notif-tag email"><i class="bi bi-envelope-fill"></i> Email</span>
                                    <?php endif; ?>
                                    <?php if ((int)$e['aviso_whatsapp']): ?>
                                        <span class="notif-tag whatsapp"><i class="bi bi-whatsapp"></i> WPP</span>
                                    <?php endif; ?>
                                    <?php if ((int)$e['aviso_modal']): ?>
                                        <span class="notif-tag modal"><i class="bi bi-window-stack"></i> Modal</span>
                                    <?php endif; ?>
                                    <div style="font-size:.7rem;opacity:.6;margin-top:2px">
                                        <?= (int)$e['dias_anticipacion'] ?>d antelación
                                    </div>
                                </td>
                                <td class="text-end" style="white-space:nowrap">
                                    <a class="btn btn-sm btn-outline-warning me-1" href="?edit=<?= (int)$e['id'] ?>"
                                        title="Editar">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a class="btn btn-sm btn-outline-info me-1" href="?enviar_notif=<?= (int)$e['id'] ?>"
                                        title="Enviar avisos ahora"
                                        onclick="return confirm('¿Enviar notificaciones de este evento ahora?')">
                                        <i class="bi bi-send"></i>
                                    </a>
                                    <a class="btn btn-sm <?= (int)$e['aviso_whatsapp'] ? 'btn-outline-success' : 'btn-outline-secondary' ?> me-1"
                                        href="#"
                                        onclick="mostrarWhatsApp(<?= (int)$e['id'] ?>); return false;"
                                        title="Links de WhatsApp"
                                        <?= !(int)$e['aviso_whatsapp'] ? 'style="opacity:.4"' : '' ?>>
                                        <i class="bi bi-whatsapp"></i>
                                    </a>
                                    <a class="btn btn-sm btn-outline-light" href="?toggle_estado=<?= (int)$e['id'] ?>"
                                        title="<?= $e['estado'] === 'cancelado' ? 'Reactivar' : 'Cancelar' ?>">
                                        <i class="bi bi-<?= $e['estado'] === 'cancelado' ? 'arrow-counterclockwise' : 'x-circle' ?>"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($lista)): ?>
                            <tr>
                                <td colspan="8" class="text-center" style="opacity:.8">
                                    No hay eventos programados. Creá uno desde el formulario.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ====== MODAL EDICION ====== -->
        <?php if ($editando): ?>
        <div class="modal fade modal-glass" id="modalEdicion" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Editando evento #<?= (int)$editando['id'] ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="post">
                        <div class="modal-body">
                            <input type="hidden" name="_accion" value="editar">
                            <input type="hidden" name="edit_id" value="<?= (int)$editando['id'] ?>">
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label">Título del evento *</label>
                                    <input name="titulo" class="form-control" required
                                        placeholder="Ej: Capacitación Escuela San Martín"
                                        value="<?= h($editando['titulo']) ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Descripción</label>
                                    <textarea name="descripcion" class="form-control" rows="2"
                                        placeholder="Detalle del evento..."><?= h($editando['descripcion'] ?? '') ?></textarea>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label">Fecha desde *</label>
                                    <input type="date" name="fecha_desde" class="form-control" required
                                        value="<?= h($editando['fecha_desde']) ?>">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label">Fecha hasta *</label>
                                    <input type="date" name="fecha_hasta" class="form-control" required
                                        value="<?= h($editando['fecha_hasta']) ?>">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label">Hora desde</label>
                                    <input type="time" name="hora_desde" class="form-control" step="60"
                                        value="<?= $editando['hora_desde'] ? h(substr($editando['hora_desde'], 0, 5)) : '' ?>">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label">Hora hasta</label>
                                    <input type="time" name="hora_hasta" class="form-control" step="60"
                                        value="<?= $editando['hora_hasta'] ? h(substr($editando['hora_hasta'], 0, 5)) : '' ?>">
                                </div>
                                <div class="col-8 col-md-6">
                                    <label class="form-label">Lugar *</label>
                                    <input name="lugar" class="form-control" required
                                        placeholder="Ej: Escuela San Martín / Salón Vecinal"
                                        value="<?= h($editando['lugar']) ?>">
                                </div>
                                <div class="col-4 col-md-3">
                                    <label class="form-label">Capacidad</label>
                                    <input type="number" name="capacidad" class="form-control" min="1"
                                        value="<?= $editando['capacidad'] ?: '' ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Estado</label>
                                    <select name="estado" class="form-select">
                                        <?php foreach (['programado' => 'Programado', 'en_curso' => 'En curso', 'finalizado' => 'Finalizado', 'cancelado' => 'Cancelado'] as $k => $v): ?>
                                            <option value="<?= $k ?>" <?= $editando['estado'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="seccion-titulo mt-3">Canales de notificación</div>
                            <div class="row g-2">
                                <div class="col-12 col-md-4">
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" id="ed_aviso_email" name="aviso_email"
                                            <?= (int)$editando['aviso_email'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="ed_aviso_email">
                                            <i class="bi bi-envelope-fill text-info"></i> Enviar por Email
                                        </label>
                                    </div>
                                    <textarea name="destinatarios_email" class="form-control" rows="2"
                                        placeholder="email1@ejemplo.com, email2@ejemplo.com"
                                        <?= !(int)$editando['aviso_email'] ? 'disabled' : '' ?>><?= h($editando['destinatarios_email'] ?? '') ?></textarea>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" id="ed_aviso_whatsapp" name="aviso_whatsapp"
                                            <?= (int)$editando['aviso_whatsapp'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="ed_aviso_whatsapp">
                                            <i class="bi bi-whatsapp" style="color:#25D366"></i> Enviar por WhatsApp
                                        </label>
                                    </div>
                                    <textarea name="destinatarios_whatsapp" class="form-control" rows="2"
                                        placeholder="3421234567, 3427654321"
                                        <?= !(int)$editando['aviso_whatsapp'] ? 'disabled' : '' ?>><?= h($editando['destinatarios_whatsapp'] ?? '') ?></textarea>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" id="ed_aviso_modal" name="aviso_modal"
                                            <?= (int)$editando['aviso_modal'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="ed_aviso_modal">
                                            <i class="bi bi-window-stack text-warning"></i> Mostrar en Panel (Modal)
                                        </label>
                                    </div>
                                    <div class="form-text text-white-50" style="font-size:.78rem">
                                        Se mostrará como aviso emergente al ingresar al panel.
                                    </div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Días de anticipación del aviso</label>
                                    <input type="number" name="dias_anticipacion" class="form-control" min="1" max="30"
                                        value="<?= (int)$editando['dias_anticipacion'] ?>">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer d-flex gap-2 flex-wrap">
                            <button type="submit" class="btn btn-warning fw-bold flex-grow-1">
                                <i class="bi bi-save"></i> Guardar cambios
                            </button>
                            <a href="?enviar_notif=<?= (int)$editando['id'] ?>" class="btn btn-info fw-bold"
                                onclick="return confirm('¿Enviar notificaciones de este evento ahora?')">
                                <i class="bi bi-send"></i> Enviar avisos
                            </a>
                            <a href="?eliminar=<?= (int)$editando['id'] ?>" class="btn btn-danger"
                                onclick="return confirm('¿Eliminar este evento? Esta acción no se puede deshacer.')">
                                <i class="bi bi-trash"></i> Eliminar
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ====== MODAL WHATSAPP ====== -->
        <div class="modal fade modal-glass" id="modalWhatsApp" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-whatsapp" style="color:#25D366"></i> Links de WhatsApp</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" id="wpp-body">
                        <p style="opacity:.7">Cargando...</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Limpieza de backdrops huérfanos que pudieran quedar tras recargas o navegaciones
        function limpiarBackdrops() {
            if (!document.querySelector('.modal.show')) {
                document.querySelectorAll('.modal-backdrop').forEach(function(b) { b.remove(); });
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            limpiarBackdrops();

            window.addEventListener('pageshow', limpiarBackdrops);
            window.addEventListener('focus', function() {
                setTimeout(limpiarBackdrops, 150);
            });

            // FullCalendar
            const calendarEl = document.getElementById('calendario');
            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'es',
                height: 520,
                events: 'calendario_eventos.php?cal=1',

                eventClick: function(info) {
                    if (confirm("¿Editar este evento?")) {
                        window.location = "calendario_eventos.php?edit=" + info.event.id;
                    }
                },

                dateClick: function(info) {
                    if (confirm("¿Crear evento para el " + info.dateStr + "?")) {
                        document.getElementById('fecha_desde').value = info.dateStr;
                        document.getElementById('fecha_hasta').value = info.dateStr;
                        document.querySelector('input[name="titulo"]').focus();
                    }
                }
            });
            calendar.render();

            // Toggle habilitar/deshabilitar campos de notificación
            function setupToggle(checkId, textareaId) {
                const chk = document.getElementById(checkId);
                const txt = document.getElementById(textareaId);
                if (chk && txt) {
                    chk.addEventListener('change', function() {
                        txt.disabled = !this.checked;
                    });
                }
            }
            setupToggle('aviso_email', 'dest_email');
            setupToggle('aviso_whatsapp', 'dest_wpp');
            setupToggle('ed_aviso_email', null);
            setupToggle('ed_aviso_whatsapp', null);

            // Si hay un modal de edición definido (modo ?edit=ID), mostrarlo centrado
            const modalEdicion = document.getElementById('modalEdicion');
            if (modalEdicion) {
                const bsModal = new bootstrap.Modal(modalEdicion);
                bsModal.show();

                modalEdicion.addEventListener('hidden.bs.modal', function() {
                    limpiarBackdrops();
                    window.location = 'calendario_eventos.php';
                });
            }
        });

        // WhatsApp modal
        function mostrarWhatsApp(eventoId) {
            const body = document.getElementById('wpp-body');
            body.innerHTML = '<p style="opacity:.7">Cargando links...</p>';
            const modalEl = document.getElementById('modalWhatsApp');
            const modal = new bootstrap.Modal(modalEl);
            modal.show();

            modalEl.addEventListener('hidden.bs.modal', function() {
                limpiarBackdrops();
            });

            fetch('calendario_eventos.php?wpp_links=' + eventoId)
                .then(r => r.json())
                .then(data => {
                    if (data.links && data.links.length > 0) {
                        let html = '<div class="d-flex flex-column gap-2">';
                        data.links.forEach(function(l) {
                            html += '<a href="' + l.url + '" target="_blank" class="whatsapp-link">' +
                                '<i class="bi bi-whatsapp"></i> ' + l.numero +
                                ' <i class="bi bi-box-arrow-up-right" style="font-size:.7rem"></i></a>';
                        });
                        html += '</div>';
                        html += '<div class="mt-3" style="font-size:.78rem;opacity:.6">Haz clic en cada número para abrir WhatsApp con el mensaje pre-armado.</div>';
                        body.innerHTML = html;
                    } else {
                        body.innerHTML = '<p style="opacity:.7">No hay números de WhatsApp configurados para este evento.</p>';
                    }
                })
                .catch(function() {
                    body.innerHTML = '<p class="text-danger">Error al obtener los links.</p>';
                });
        }

        // WhatsApp links JSON endpoint
        // (se maneja via PHP en la parte superior si se detecta el parámetro)
    </script>

</body>

</html>
