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

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// ================= ASEGURAR TABLA REGLAS =================
$reglasOk = $conn->query("CREATE TABLE IF NOT EXISTS config_reglas_dias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dia_semana INT NOT NULL DEFAULT 0,
    orden_sabado INT NOT NULL DEFAULT 0,
    lugar VARCHAR(200) NOT NULL DEFAULT '',
    categoria VARCHAR(200) NOT NULL DEFAULT '',
    turno1_nombre VARCHAR(100) NOT NULL DEFAULT '',
    turno1_desde TIME DEFAULT NULL,
    turno1_hasta TIME DEFAULT NULL,
    turno2_nombre VARCHAR(100) NOT NULL DEFAULT '',
    turno2_desde TIME DEFAULT NULL,
    turno2_hasta TIME DEFAULT NULL,
    turno3_nombre VARCHAR(100) NOT NULL DEFAULT '',
    turno3_desde TIME DEFAULT NULL,
    turno3_hasta TIME DEFAULT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1
)");
// Migrar tablas existentes — agrega solo columnas faltantes
if ($reglasOk) {
    $existentes = [];
    $cols = $conn->query("SHOW COLUMNS FROM config_reglas_dias");
    if ($cols) while ($c = $cols->fetch_assoc()) $existentes[] = $c['Field'];
    $requeridas = ['turno1_desde','turno1_hasta','turno2_desde','turno2_hasta','turno3_desde','turno3_hasta','activo'];
    foreach ($requeridas as $col) {
        if (!in_array($col, $existentes)) {
            $tipo = ($col === 'activo') ? "TINYINT(1) NOT NULL DEFAULT 1" : "TIME DEFAULT NULL";
            @$conn->query("ALTER TABLE config_reglas_dias ADD COLUMN $col $tipo");
        }
    }
}

// ================= EDITAR REGLA (GET) =================
$editandoRegla = null;
if (isset($_GET['edit_regla'])) {
    $rid = (int)$_GET['edit_regla'];
    $st = $conn->prepare("SELECT * FROM config_reglas_dias WHERE id = ? LIMIT 1");
    $st->bind_param("i", $rid);
    $st->execute();
    $editandoRegla = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$editandoRegla) {
        header("Location: config_form_eventos.php");
        exit();
    }
}

// ================= GUARDAR REGLA (POST) =================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['_accion']) && $_POST['_accion'] === 'guardar_regla') {
    $rid        = (int)($_POST['regla_id'] ?? 0);
    $dia_semana = (int)($_POST['dia_semana'] ?? 0);
    $orden_sab  = (int)($_POST['orden_sabado'] ?? 0);
    $lugar      = strtoupper(trim($_POST['lugar'] ?? ''));
    $categoria  = strtoupper(trim($_POST['categoria'] ?? ''));
    $t1n        = trim($_POST['turno1_nombre'] ?? '');
    $t1d        = trim($_POST['turno1_desde'] ?? '');
    $t1h        = trim($_POST['turno1_hasta'] ?? '');
    $t2n        = trim($_POST['turno2_nombre'] ?? '');
    $t2d        = trim($_POST['turno2_desde'] ?? '');
    $t2h        = trim($_POST['turno2_hasta'] ?? '');
    $t3n        = trim($_POST['turno3_nombre'] ?? '');
    $t3d        = trim($_POST['turno3_desde'] ?? '');
    $t3h        = trim($_POST['turno3_hasta'] ?? '');
    $activo     = isset($_POST['activo']) ? 1 : 0;

    if ($t1d !== '') $t1d .= ':00';
    if ($t1h !== '') $t1h .= ':00';
    if ($t2d !== '') $t2d .= ':00';
    if ($t2h !== '') $t2h .= ':00';
    if ($t3d !== '') $t3d .= ':00';
    if ($t3h !== '') $t3h .= ':00';

    if ($rid > 0) {
        $st = $conn->prepare("
            UPDATE config_reglas_dias SET
                dia_semana=?, orden_sabado=?, lugar=?, categoria=?,
                turno1_nombre=?,
                turno1_desde=NULLIF(?, ''), turno1_hasta=NULLIF(?, ''),
                turno2_nombre=?,
                turno2_desde=NULLIF(?, ''), turno2_hasta=NULLIF(?, ''),
                turno3_nombre=?,
                turno3_desde=NULLIF(?, ''), turno3_hasta=NULLIF(?, ''),
                activo=?
            WHERE id=?
        ");
        $st->bind_param(
            "iisssssssssssii",
            $dia_semana,
            $orden_sab,
            $lugar,
            $categoria,
            $t1n,
            $t1d,
            $t1h,
            $t2n,
            $t2d,
            $t2h,
            $t3n,
            $t3d,
            $t3h,
            $activo,
            $rid
        );
        if ($st->execute()) {
            $msg = "<div class='alert alert-success'>✅ Regla actualizada.</div>";
        } else {
            $msg = "<div class='alert alert-danger'>Error: " . $st->error . "</div>";
        }
        $st->close();
    }
    $editandoRegla = null;
}

// ================= TOGGLE REGLA ACTIVO =================
if (isset($_GET['toggle_regla'])) {
    $rid = (int)$_GET['toggle_regla'];
    $conn->query("UPDATE config_reglas_dias SET activo = IF(activo=1,0,1) WHERE id=$rid");
    header("Location: config_form_eventos.php");
    exit();
}

// ================= CALENDARIO JSON =================
if (isset($_GET['cal'])) {
    header('Content-Type: application/json');
    $out = [];
    $res = $conn->query("SELECT * FROM config_form_eventos");
    while ($r = $res->fetch_assoc()) {
        $end   = date('Y-m-d', strtotime($r['fecha_hasta'] . ' +1 day'));
        $color = ((int)$r['activo'] === 1) ? '#C7E4CF' : '#6c757d';
        $title = $r['lugar'] . " • " . $r['licencia'] . " • " . $r['turno'];
        if (!empty($r['hora_desde'])) {
            $title .= " (" . substr($r['hora_desde'], 0, 5) . "-" . substr($r['hora_hasta'], 0, 5) . ")";
        }
        $out[] = [
            "id"    => $r['id'],
            "title" => $title,
            "start" => $r['fecha_desde'],
            "end"   => $end,
            "color" => $color
        ];
    }
    echo json_encode($out);
    exit;
}

$msg = "";

// ================= TOGGLE ACTIVO =================
if (isset($_GET["toggle"])) {
    $id = (int)$_GET["toggle"];
    $conn->query("UPDATE config_form_eventos SET activo = IF(activo=1,0,1) WHERE id=$id");
    header("Location: config_form_eventos.php");
    exit();
}

// ================= ELIMINAR =================
if (isset($_GET["eliminar"])) {
    $id = (int)$_GET["eliminar"];
    $conn->query("DELETE FROM config_form_eventos WHERE id=$id");
    header("Location: config_form_eventos.php");
    exit();
}

// ================= GUARDAR LOGO =================
if (isset($_POST['guardar_logo'])) {
    $logo = trim($_POST['logo']);
    $st = $conn->prepare("UPDATE config_general SET valor=? WHERE clave='logo_formulario'");
    $st->bind_param("s", $logo);
    $st->execute();
    $st->close();
    $msg = "<div class='alert alert-success'>Logo actualizado.</div>";
}

// ================= EDITAR EVENTO (GET — cargar datos) =================
$editando  = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $st = $conn->prepare("SELECT * FROM config_form_eventos WHERE id = ? LIMIT 1");
    $st->bind_param("i", $editId);
    $st->execute();
    $editando = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$editando) {
        header("Location: config_form_eventos.php");
        exit();
    }
}

// ================= ACTUALIZAR EVENTO (POST) =================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['_accion']) && $_POST['_accion'] === 'editar') {
    $editId      = (int)($_POST['edit_id'] ?? 0);
    $fecha_desde = $_POST["fecha_desde"] ?? "";
    $fecha_hasta = $_POST["fecha_hasta"] ?? "";
    $hora_desde  = trim($_POST["hora_desde"] ?? "");
    $hora_hasta  = trim($_POST["hora_hasta"] ?? "");
    $lugar       = strtoupper(trim($_POST["lugar"]    ?? ""));
    $licencia    = strtoupper(trim($_POST["licencia"] ?? ""));
    $turno       = strtoupper(trim($_POST["turno"]    ?? ""));
    $motivo      = trim($_POST["motivo"] ?? "");

    if ($fecha_desde === "" || $fecha_hasta === "" || $lugar === "" || $licencia === "" || $turno === "") {
        $msg = "<div class='alert alert-danger'>Completá todos los campos obligatorios (fechas, lugar, licencia y <strong>turno</strong>).</div>";
        // Recargar datos para volver a mostrar el formulario de edición
        $st = $conn->prepare("SELECT * FROM config_form_eventos WHERE id = ? LIMIT 1");
        $st->bind_param("i", $editId);
        $st->execute();
        $editando = $st->get_result()->fetch_assoc();
        $st->close();
    } else {
        $hora_desde_db = ($hora_desde === "") ? null : $hora_desde . ":00";
        $hora_hasta_db = ($hora_hasta === "") ? null : $hora_hasta . ":00";

        if (($hora_desde_db && !$hora_hasta_db) || (!$hora_desde_db && $hora_hasta_db)) {
            $msg = "<div class='alert alert-danger'>Si usás horario, completá Hora desde y Hora hasta.</div>";
            $st = $conn->prepare("SELECT * FROM config_form_eventos WHERE id = ? LIMIT 1");
            $st->bind_param("i", $editId);
            $st->execute();
            $editando = $st->get_result()->fetch_assoc();
            $st->close();
        } else {
            $st = $conn->prepare("
                UPDATE config_form_eventos
                SET fecha_desde=?, fecha_hasta=?, hora_desde=?, hora_hasta=?,
                    lugar=?, licencia=?, turno=?, motivo=?
                WHERE id=?
            ");
            $st->bind_param(
                "ssssssssi",
                $fecha_desde,
                $fecha_hasta,
                $hora_desde_db,
                $hora_hasta_db,
                $lugar,
                $licencia,
                $turno,
                $motivo,
                $editId
            );
            if ($st->execute()) {
                $msg = "<div class='alert alert-success'>✅ Evento actualizado correctamente.</div>";
            } else {
                $msg = "<div class='alert alert-danger'>Error: " . $st->error . "</div>";
            }
            $st->close();
            $editando = null; // cerrar formulario de edición
        }
    }
}

// ================= CREAR EVENTO (POST) =================
if ($_SERVER["REQUEST_METHOD"] === "POST" && !isset($_POST['_accion']) && !isset($_POST['guardar_logo'])) {
    $fecha_desde = $_POST["fecha_desde"] ?? "";
    $fecha_hasta = $_POST["fecha_hasta"] ?? "";
    $hora_desde  = trim($_POST["hora_desde"] ?? "");
    $hora_hasta  = trim($_POST["hora_hasta"] ?? "");
    $lugar       = strtoupper(trim($_POST["lugar"]    ?? ""));
    $licencia    = strtoupper(trim($_POST["licencia"] ?? ""));
    $turno       = strtoupper(trim($_POST["turno"]    ?? ""));
    $motivo      = trim($_POST["motivo"] ?? "");

    if ($fecha_desde === "" || $fecha_hasta === "" || $lugar === "" || $licencia === "" || $turno === "") {
        $msg = "<div class='alert alert-danger'>Completá fechas + lugar + licencia + turno.</div>";
    } else {
        $hora_desde_db = ($hora_desde === "") ? null : $hora_desde . ":00";
        $hora_hasta_db = ($hora_hasta === "") ? null : $hora_hasta . ":00";

        if (($hora_desde_db && !$hora_hasta_db) || (!$hora_desde_db && $hora_hasta_db)) {
            $msg = "<div class='alert alert-danger'>Si usás horario, completá Hora desde y Hora hasta.</div>";
        } else {
            $st = $conn->prepare("
                INSERT INTO config_form_eventos
                    (activo, fecha_desde, fecha_hasta, hora_desde, hora_hasta, lugar, licencia, turno, motivo)
                VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $st->bind_param(
                "ssssssss",
                $fecha_desde,
                $fecha_hasta,
                $hora_desde_db,
                $hora_hasta_db,
                $lugar,
                $licencia,
                $turno,
                $motivo
            );
            if ($st->execute()) {
                $msg = "<div class='alert alert-success'>✅ Evento creado.</div>";
            } else {
                $msg = "<div class='alert alert-danger'>Error: " . $st->error . "</div>";
            }
            $st->close();
        }
    }
}

// ================= LISTADO =================
$lista = [];
$res = $conn->query("SELECT * FROM config_form_eventos ORDER BY actualizado_en DESC, id DESC");
if ($res) while ($row = $res->fetch_assoc()) $lista[] = $row;
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Configurar Formulario • Eventos</title>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/variables.css">
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
            max-width: 1100px;
            margin: 0 auto;
            background: rgba(10, 18, 32, .78);
            border: 1px solid rgba(255, 255, 255, .14);
            border-radius: 22px;
            backdrop-filter: blur(14px);
            padding: 18px;
            box-shadow: 0 18px 55px rgba(0, 0, 0, .60);
        }

        .form-control,
        .form-select {
            background: rgba(0, 0, 0, .35) !important;
            border: 1px solid rgba(255, 255, 255, .14) !important;
            color: #fff !important;
        }

        .form-control::placeholder {
            color: rgba(255, 255, 255, .55) !important;
        }

        .table {
            --bs-table-bg: transparent;
            --bs-table-color: #fff;
            --bs-table-border-color: rgba(255, 255, 255, .12);
        }

        .badge {
            font-weight: 800;
        }

        /* Panel de edición */
        .panel-edicion {
            background: rgba(255, 180, 0, .10);
            border: 1px solid rgba(255, 180, 0, .35);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .panel-edicion h5 {
            color: #FFD166;
        }
    </style>
</head>

<body>
    <div class="cardx">

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div>
                <h3 class="mb-0">🛠 Eventos especiales del Formulario</h3>
                <div style="opacity:.8">Programá lugar/licencia/turno por fecha (y opcional horario). El formulario se ajusta solo.</div>
            </div>
            <a href="panel.php" class="btn btn-outline-light">Volver al panel</a>
        </div>

        <?= $msg ?>

        <!-- ====== PANEL EDICIÓN (aparece solo cuando ?edit=ID) ====== -->
        <?php if ($editando): ?>
            <div class="panel-edicion">
                <h5>✏️ Editando evento #<?= (int)$editando['id'] ?></h5>
                <form method="post">
                    <input type="hidden" name="_accion" value="editar">
                    <input type="hidden" name="edit_id" value="<?= (int)$editando['id'] ?>">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Fecha desde</label>
                            <input type="date" name="fecha_desde" class="form-control" required
                                value="<?= h($editando['fecha_desde']) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Fecha hasta</label>
                            <input type="date" name="fecha_hasta" class="form-control" required
                                value="<?= h($editando['fecha_hasta']) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Hora desde (opcional)</label>
                            <input type="time" name="hora_desde" class="form-control" step="60"
                                value="<?= $editando['hora_desde'] ? h(substr($editando['hora_desde'], 0, 5)) : '' ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Hora hasta (opcional)</label>
                            <input type="time" name="hora_hasta" class="form-control" step="60"
                                value="<?= $editando['hora_hasta'] ? h(substr($editando['hora_hasta'], 0, 5)) : '' ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Lugar</label>
                            <input name="lugar" class="form-control" required
                                placeholder="Ej: ESCUELA / SALÓN VECINAL"
                                value="<?= h($editando['lugar']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Licencia</label>
                            <input name="licencia" class="form-control" required
                                placeholder="Ej: AUTOMÓVIL / MOTOVEHÍCULO / AMBAS"
                                value="<?= h($editando['licencia']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">
                                Turno <span class="badge bg-danger ms-1">Obligatorio</span>
                            </label>
                            <input name="turno" class="form-control" required
                                placeholder="Ej: 1° TURNO 13:30 / 2° TURNO 20:00"
                                value="<?= h($editando['turno']) ?>">
                            <div class="form-text text-warning mt-1">
                                ⚠️ El turno es obligatorio para que el formulario QR se desbloquee.
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Motivo / Nota</label>
                            <input name="motivo" class="form-control"
                                placeholder="Ej: capacitación especial"
                                value="<?= h($editando['motivo'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-warning fw-bold flex-grow-1">
                            💾 Guardar cambios
                        </button>
                        <a href="config_form_eventos.php" class="btn btn-outline-light">
                            Cancelar
                        </a>
                        <a href="?eliminar=<?= (int)$editando['id'] ?>"
                            class="btn btn-danger"
                            onclick="return confirm('¿Eliminar este evento? Esta acción no se puede deshacer.')">
                            🗑 Eliminar
                        </a>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- ====== LOGO ====== -->
        <div class="p-3 mb-3 rounded-4" style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);">
            <h5>Logo del formulario QR</h5>
            <form method="post">
                <div class="row g-2">
                    <div class="col-9">
                        <input name="logo" class="form-control" placeholder="img/logo_evento.png">
                    </div>
                    <div class="col-3">
                        <button name="guardar_logo" class="btn btn-primary w-100">Guardar</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- ====== CREAR + TABLA ====== -->
        <div class="row g-3 mt-1">

            <!-- Formulario crear -->
            <div class="col-12 col-lg-6">
                <div class="p-3 rounded-4" style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);">
                    <h5 class="mb-3">Crear evento</h5>
                    <form method="post">
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label">Fecha desde</label>
                                <input type="date" name="fecha_desde" class="form-control" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Fecha hasta</label>
                                <input type="date" name="fecha_hasta" class="form-control" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Hora desde (opcional)</label>
                                <input type="time" name="hora_desde" class="form-control" step="60">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Hora hasta (opcional)</label>
                                <input type="time" name="hora_hasta" class="form-control" step="60">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Lugar</label>
                                <input name="lugar" class="form-control" required
                                    placeholder="Ej: ESCUELA / SALÓN VECINAL / PUNTO DIGITAL">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Licencia</label>
                                <input name="licencia" class="form-control" required
                                    placeholder="Ej: AUTOMÓVIL / MOTOVEHÍCULO / AMBAS">
                            </div>
                            <div class="col-12">
                                <label class="form-label">
                                    Turno <span class="badge bg-danger ms-1">Obligatorio</span>
                                </label>
                                <input name="turno" class="form-control" required
                                    placeholder="Ej: 1° TURNO 13:30 / 2° TURNO 20:00">
                                <div class="form-text text-warning mt-1">
                                    ⚠️ Sin turno el formulario QR permanece bloqueado.
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Motivo / Nota</label>
                                <input name="motivo" class="form-control"
                                    placeholder="Ej: capacitación especial fuera de cronograma">
                            </div>
                        </div>
                        <button class="btn btn-primary w-100 mt-3">Guardar evento</button>
                    </form>
                </div>
            </div>

            <!-- Calendario -->
            <div class="col-12 col-lg-6">
                <div class="p-3 rounded-4" style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);">
                    <h5 class="mb-3">📅 Calendario de eventos</h5>
                    <div id="calendario"></div>
                </div>
            </div>

        </div><!-- fin row crear+calendario -->

        <!-- ====== TABLA EVENTOS — ANCHO COMPLETO ====== -->
        <div class="p-3 rounded-4 mt-4" style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);">
            <h5 class="mb-3">📋 Eventos programados</h5>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Estado</th>
                            <th>Fecha desde</th>
                            <th>Fecha hasta</th>
                            <th>Hora</th>
                            <th>Lugar</th>
                            <th>Licencia</th>
                            <th>Turno</th>
                            <th>Motivo</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lista as $e): ?>
                            <tr>
                                <td>
                                    <?php if ((int)$e["activo"] === 1): ?>
                                        <span class="badge bg-success">ACTIVO</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">INACTIVO</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($e["fecha_desde"]) ?></td>
                                <td><?= h($e["fecha_hasta"]) ?></td>
                                <td>
                                    <?= $e["hora_desde"]
                                        ? h(substr($e["hora_desde"], 0, 5)) . "–" . h(substr($e["hora_hasta"], 0, 5))
                                        : "<span style='opacity:.6'>Todo el día</span>" ?>
                                </td>
                                <td><b><?= h($e["lugar"]) ?></b></td>
                                <td><?= h($e["licencia"]) ?></td>
                                <td><?= h($e["turno"]) ?></td>
                                <td style="font-size:.85rem;opacity:.8">
                                    <?= !empty($e["motivo"]) ? "📝 " . h($e["motivo"]) : "—" ?>
                                </td>
                                <td class="text-end" style="white-space:nowrap">
                                    <a class="btn btn-sm btn-outline-warning me-1"
                                        href="?edit=<?= (int)$e["id"] ?>">✏️ Editar</a>
                                    <a class="btn btn-sm btn-outline-light"
                                        href="?toggle=<?= (int)$e["id"] ?>">
                                        <?= ((int)$e["activo"] === 1) ? "Desactivar" : "Activar" ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($lista) === 0): ?>
                            <tr>
                                <td colspan="9" class="text-center" style="opacity:.8">No hay eventos aún.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div style="opacity:.65;font-size:.85rem" class="mt-2">
                Tip: podés cargar un evento por un solo día (desde=hasta) para algo puntual.
            </div>
        </div>

        <!-- ====== REGLAS AUTOMÁTICAS ====== -->
        <h4 class="mt-4">⚙ Reglas automáticas por día</h4>

        <?php if ($editandoRegla): ?>
            <div class="panel-edicion">
                <h5>✏️ Editando regla #<?= (int)$editandoRegla['id'] ?> — <?= h($editandoRegla['lugar']) ?></h5>
                <form method="post">
                    <input type="hidden" name="_accion" value="guardar_regla">
                    <input type="hidden" name="regla_id" value="<?= (int)$editandoRegla['id'] ?>">
                    <div class="row g-2">
                        <div class="col-4">
                            <label class="form-label">Día semana</label>
                            <select name="dia_semana" class="form-select">
                                <?php foreach ([1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'] as $k => $v): ?>
                                    <option value="<?= $k ?>" <?= $editandoRegla['dia_semana'] == $k ? 'selected' : '' ?>><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-2">
                            <label class="form-label">Ord. Sábado</label>
                            <input type="number" name="orden_sabado" class="form-control" min="0" max="5"
                                value="<?= (int)$editandoRegla['orden_sabado'] ?>">
                        </div>
                        <div class="col-3">
                            <label class="form-label">Lugar</label>
                            <input name="lugar" class="form-control" value="<?= h($editandoRegla['lugar']) ?>">
                        </div>
                        <div class="col-3">
                            <label class="form-label">Categoría</label>
                            <input name="categoria" class="form-control" value="<?= h($editandoRegla['categoria']) ?>">
                        </div>

                        <div class="col-12 mt-2">
                            <hr class="border-secondary">
                            <h6 class="text-info">Turno 1</h6>
                        </div>
                        <div class="col-4">
                            <label class="form-label">Nombre</label>
                            <input name="turno1_nombre" class="form-control" value="<?= h($editandoRegla['turno1_nombre']) ?>">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Hora desde</label>
                            <input type="time" name="turno1_desde" class="form-control" step="60"
                                value="<?= $editandoRegla['turno1_desde'] ? h(substr($editandoRegla['turno1_desde'], 0, 5)) : '' ?>">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Hora hasta</label>
                            <input type="time" name="turno1_hasta" class="form-control" step="60"
                                value="<?= $editandoRegla['turno1_hasta'] ? h(substr($editandoRegla['turno1_hasta'], 0, 5)) : '' ?>">
                        </div>

                        <div class="col-12 mt-2">
                            <hr class="border-secondary">
                            <h6 class="text-info">Turno 2</h6>
                        </div>
                        <div class="col-4">
                            <label class="form-label">Nombre</label>
                            <input name="turno2_nombre" class="form-control" value="<?= h($editandoRegla['turno2_nombre']) ?>">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Hora desde</label>
                            <input type="time" name="turno2_desde" class="form-control" step="60"
                                value="<?= $editandoRegla['turno2_desde'] ? h(substr($editandoRegla['turno2_desde'], 0, 5)) : '' ?>">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Hora hasta</label>
                            <input type="time" name="turno2_hasta" class="form-control" step="60"
                                value="<?= $editandoRegla['turno2_hasta'] ? h(substr($editandoRegla['turno2_hasta'], 0, 5)) : '' ?>">
                        </div>

                        <div class="col-12 mt-2">
                            <hr class="border-secondary">
                            <h6 class="text-info">Turno 3</h6>
                        </div>
                        <div class="col-4">
                            <label class="form-label">Nombre</label>
                            <input name="turno3_nombre" class="form-control" value="<?= h($editandoRegla['turno3_nombre']) ?>">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Hora desde</label>
                            <input type="time" name="turno3_desde" class="form-control" step="60"
                                value="<?= $editandoRegla['turno3_desde'] ? h(substr($editandoRegla['turno3_desde'], 0, 5)) : '' ?>">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Hora hasta</label>
                            <input type="time" name="turno3_hasta" class="form-control" step="60"
                                value="<?= $editandoRegla['turno3_hasta'] ? h(substr($editandoRegla['turno3_hasta'], 0, 5)) : '' ?>">
                        </div>

                        <div class="col-12 mt-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="regla_activo" name="activo"
                                    <?= (int)$editandoRegla['activo'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="regla_activo">Activo</label>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-warning fw-bold flex-grow-1">💾 Guardar regla</button>
                        <a href="config_form_eventos.php" class="btn btn-outline-light">Cancelar</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-dark table-bordered">
                <tr>
                    <th>Estado</th>
                    <th>Día</th>
                    <th>Lugar</th>
                    <th>Categoría</th>
                    <th>Turno 1</th>
                    <th>Turno 2</th>
                    <th>Turno 3</th>
                    <th>Acción</th>
                </tr>
                <?php
                $res = $conn->query("SELECT * FROM config_reglas_dias ORDER BY dia_semana, orden_sabado");
                $dias = [1 => "Lunes", 2 => "Martes", 3 => "Miércoles", 4 => "Jueves", 5 => "Viernes", 6 => "Sábado", 7 => "Domingo"];
                if ($res && is_object($res)):
                    while ($r = $res->fetch_assoc()):
                        $activo = (int)$r['activo'];
                ?>
                        <tr style="<?= $activo ? '' : 'opacity:.55' ?>">
                            <td>
                                <?php if ($activo): ?>
                                    <span class="badge bg-success">ACTIVO</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">INACTIVO</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $dias[$r['dia_semana']] ?? '' ?>
                                <?= $r['orden_sabado'] ? " " . $r['orden_sabado'] . "°" : "" ?>
                            </td>
                            <td><?= h($r['lugar']) ?></td>
                            <td><?= h($r['categoria']) ?></td>
                            <td>
                                <?= h($r['turno1_nombre']) ?>
                                <?php if ($r['turno1_desde']): ?><br><small><?= substr($r['turno1_desde'], 0, 5) ?>-<?= substr($r['turno1_hasta'], 0, 5) ?></small><?php endif; ?>
                            </td>
                            <td>
                                <?= h($r['turno2_nombre']) ?>
                                <?php if ($r['turno2_desde']): ?><br><small><?= substr($r['turno2_desde'], 0, 5) ?>-<?= substr($r['turno2_hasta'], 0, 5) ?></small><?php endif; ?>
                            </td>
                            <td>
                                <?= h($r['turno3_nombre']) ?>
                                <?php if ($r['turno3_desde']): ?><br><small><?= substr($r['turno3_desde'], 0, 5) ?>-<?= substr($r['turno3_hasta'], 0, 5) ?></small><?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <a class="btn btn-sm btn-outline-warning me-1" href="?edit_regla=<?= (int)$r['id'] ?>">✏️</a>
                                <a class="btn btn-sm <?= $activo ? 'btn-outline-secondary' : 'btn-outline-success' ?>" href="?toggle_regla=<?= (int)$r['id'] ?>">
                                    <?= $activo ? 'Desactivar' : 'Activar' ?>
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
                <?php if (!$res || (is_object($res) && $res->num_rows === 0)): ?>
                    <tr>
                        <td colspan="8" class="text-center" style="opacity:.8">No hay reglas configuradas.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>

    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {

            const calendarEl = document.getElementById('calendario');
            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'es',
                height: 650,
                events: 'config_form_eventos.php?cal=1',

                eventClick: function(info) {
                    if (confirm("¿Editar este evento?")) {
                        window.location = "config_form_eventos.php?edit=" + info.event.id;
                    }
                },

                dateClick: function(info) {
                    if (confirm("¿Crear evento para esta fecha?")) {
                        document.querySelector("input[name='fecha_desde']").value = info.dateStr;
                        document.querySelector("input[name='fecha_hasta']").value = info.dateStr;
                        window.scrollTo(0, 0);
                    }
                }
            });

            calendar.render();

            // Si hay panel de edición abierto, hacer scroll hasta él
            const panelEdicion = document.querySelector('.panel-edicion');
            if (panelEdicion) {
                panelEdicion.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    </script>

</body>

</html>