<?php
session_start();
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/auditar.php";

proteger();

if (!permiso("admin")) {
    die("Acceso denegado. Se requieren permisos de Administrador.");
}

// Crear la tabla si no existe
$conn->query("
    CREATE TABLE IF NOT EXISTS modales_actualizacion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titulo VARCHAR(255) NOT NULL,
        version VARCHAR(50) DEFAULT NULL,
        contenido TEXT NOT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
        fecha_actualizacion DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

$msg_ok = $_SESSION['msg_ok'] ?? '';
$msg_error = $_SESSION['msg_error'] ?? '';
unset($_SESSION['msg_ok'], $_SESSION['msg_error']);

// ================= ACCIONES POST =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'guardar') {
        $id        = (int)($_POST['id'] ?? 0);
        $titulo    = trim($_POST['titulo'] ?? '');
        $version   = trim($_POST['version'] ?? '');
        $contenido = trim($_POST['contenido'] ?? '');
        $activo    = isset($_POST['activo']) ? 1 : 0;

        if ($titulo === '' || $contenido === '') {
            $_SESSION['msg_error'] = "El título y el contenido son obligatorios.";
        } else {
            if ($id > 0) {
                // Actualizar
                $st = $conn->prepare("UPDATE modales_actualizacion SET titulo = ?, version = ?, contenido = ?, activo = ? WHERE id = ?");
                $st->bind_param("sssii", $titulo, $version, $contenido, $activo, $id);
                if ($st->execute()) {
                    auditar($conn, "MODIFICAR_MODAL", "MODALES", "Modal #$id ($titulo) actualizado");
                    $_SESSION['msg_ok'] = "Modal de actualización guardado correctamente.";
                } else {
                    $_SESSION['msg_error'] = "Error al actualizar el modal: " . $st->error;
                }
                $st->close();
            } else {
                // Crear nuevo
                $st = $conn->prepare("INSERT INTO modales_actualizacion (titulo, version, contenido, activo) VALUES (?, ?, ?, ?)");
                $st->bind_param("sssi", $titulo, $version, $contenido, $activo);
                if ($st->execute()) {
                    $nuevo_id = $conn->insert_id;
                    auditar($conn, "CREAR_MODAL", "MODALES", "Nuevo modal #$nuevo_id ($titulo) creado");
                    $_SESSION['msg_ok'] = "Nuevo aviso de actualización creado con éxito.";
                } else {
                    $_SESSION['msg_error'] = "Error al crear el modal: " . $st->error;
                }
                $st->close();
            }
        }
        header("Location: configurar_modales.php");
        exit;
    }

    if ($accion === 'toggle_activo') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $st = $conn->prepare("UPDATE modales_actualizacion SET activo = IF(activo = 1, 0, 1) WHERE id = ?");
            $st->bind_param("i", $id);
            if ($st->execute()) {
                auditar($conn, "TOGGLE_MODAL", "MODALES", "Estado del modal #$id alternado");
                $_SESSION['msg_ok'] = "Estado del modal actualizado.";
            } else {
                $_SESSION['msg_error'] = "Error al cambiar estado: " . $st->error;
            }
            $st->close();
        }
        header("Location: configurar_modales.php");
        exit;
    }

    if ($accion === 'eliminar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $st = $conn->prepare("DELETE FROM modales_actualizacion WHERE id = ?");
            $st->bind_param("i", $id);
            if ($st->execute()) {
                auditar($conn, "ELIMINAR_MODAL", "MODALES", "Modal #$id eliminado");
                $_SESSION['msg_ok'] = "Modal eliminado correctamente.";
            } else {
                $_SESSION['msg_error'] = "Error al eliminar modal: " . $st->error;
            }
            $st->close();
        }
        header("Location: configurar_modales.php");
        exit;
    }
}

// Cargar lista de modales
$modales = [];
$res = $conn->query("SELECT * FROM modales_actualizacion ORDER BY id DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $modales[] = $row;
    }
}

// Cargar registro para edición si viene id_edit en GET
$modal_editar = null;
if (isset($_GET['edit'])) {
    $id_edit = (int)$_GET['edit'];
    $st = $conn->prepare("SELECT * FROM modales_actualizacion WHERE id = ? LIMIT 1");
    $st->bind_param("i", $id_edit);
    $st->execute();
    $modal_editar = $st->get_result()->fetch_assoc();
    $st->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Configurar Avisos de Actualización — CeCap</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/variables.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        :root {
            --glass: rgba(255, 255, 255, 0.06);
            --border: rgba(255, 255, 255, 0.12);
            --shadow: 0 14px 35px rgba(0, 0, 0, 0.40);
            --text: #F0EDF2;
            --muted: rgba(199, 228, 207, 0.65);
        }
        body {
            background: linear-gradient(135deg, #504254, #3C313F);
            background-attachment: fixed;
            min-height: 100vh;
            color: var(--text);
            font-family: 'Segoe UI', sans-serif;
            padding: 24px 12px 80px 12px;
        }
        .container-custom {
            max-width: 1100px;
            margin: 0 auto;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border: 1px solid var(--border);
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.45);
            padding: 26px;
            margin-bottom: 24px;
        }
        .header-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .header-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .header-title i {
            font-size: 1.8rem;
            color: #C7E4CF;
        }
        .header-title h2 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
        }
        .form-control, .form-select {
            background: rgba(0, 0, 0, 0.35) !important;
            color: #fff !important;
            border: 1px solid rgba(255, 255, 255, 0.22) !important;
            border-radius: 12px !important;
        }
        .form-control:focus, .form-select:focus {
            border-color: #C7E4CF !important;
            box-shadow: 0 0 0 2px rgba(199, 228, 207, 0.25) !important;
            background: rgba(0, 0, 0, 0.45) !important;
        }
        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.45) !important;
        }
        label {
            color: #C7E4CF;
            font-size: 0.88rem;
            font-weight: 600;
            margin-bottom: 6px;
        }
        .table-custom {
            color: #fff;
            vertical-align: middle;
        }
        .table-custom th {
            background: rgba(0, 0, 0, 0.4) !important;
            color: #C7E4CF;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            font-weight: 600;
        }
        .table-custom td {
            background: rgba(255, 255, 255, 0.03);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            color: #fff;
        }
        .badge-activo {
            background-color: rgba(25, 135, 84, 0.85);
            color: #fff;
            padding: 6px 12px;
            border-radius: 12px;
            font-size: 0.8rem;
        }
        .badge-inactivo {
            background-color: rgba(108, 117, 125, 0.85);
            color: #fff;
            padding: 6px 12px;
            border-radius: 12px;
            font-size: 0.8rem;
        }
        .btn-custom-primary {
            background: linear-gradient(135deg, #4A7C59, #3A6346);
            border: none;
            color: white;
            border-radius: 12px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        .btn-custom-primary:hover {
            background: linear-gradient(135deg, #5A9369, #4A7C59);
            color: white;
        }
    </style>
</head>
<body>

<div class="container-custom">
    <!-- Header -->
    <div class="header-bar">
        <div class="header-title">
            <i class="bi bi-window-stack"></i>
            <div>
                <h2>Avisos de Actualización del Sistema</h2>
                <small class="text-white-50">Crear y gestionar modales informativos de novedades para los usuarios</small>
            </div>
        </div>
        <div>
            <a href="panel.php" class="btn btn-outline-light btn-sm rounded-3">
                <i class="bi bi-arrow-left"></i> Volver al Panel
            </a>
        </div>
    </div>

    <!-- Mensajes de alerta -->
    <?php if ($msg_ok): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-4 bg-success text-white border-0" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> <?= h($msg_ok) ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($msg_error): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4 bg-danger text-white border-0" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= h($msg_error) ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Formulario Crear / Editar -->
    <div class="glass-card">
        <h4 class="mb-3 text-white">
            <i class="bi bi-pencil-square text-success me-2"></i>
            <?= $modal_editar ? 'Editar Aviso de Actualización' : 'Nuevo Aviso de Actualización' ?>
        </h4>

        <form action="configurar_modales.php" method="POST">
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="id" value="<?= $modal_editar ? (int)$modal_editar['id'] : 0 ?>">

            <div class="row g-3">
                <div class="col-md-8">
                    <label for="titulo">Título de la Actualización <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="titulo" name="titulo" 
                           placeholder="Ej: Novedades Versión 8.26" 
                           value="<?= h($modal_editar['titulo'] ?? '') ?>" required>
                </div>

                <div class="col-md-4">
                    <label for="version">Versión / Etiqueta (opcional)</label>
                    <input type="text" class="form-control" id="version" name="version" 
                           placeholder="Ej: V8.26" 
                           value="<?= h($modal_editar['version'] ?? '') ?>">
                </div>

                <div class="col-12">
                    <label for="contenido">Detalle / Novedades de la Actualización <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="contenido" name="contenido" rows="5" 
                              placeholder="Escriba aquí los detalles de la actualización. Puede usar saltos de línea o HTML sencillo (<b>, <ul>, <li>, etc.)." required><?= h($modal_editar['contenido'] ?? '') ?></textarea>
                </div>

                <div class="col-12 d-flex align-items-center justify-content-between pt-2">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="activo" name="activo" value="1" 
                               <?= (!$modal_editar || !empty($modal_editar['activo'])) ? 'checked' : '' ?>>
                        <label class="form-check-label text-white ms-2" for="activo">
                            Activar modal inmediatamente al ingresar al sistema
                        </label>
                    </div>

                    <div>
                        <?php if ($modal_editar): ?>
                            <a href="configurar_modales.php" class="btn btn-outline-secondary btn-sm rounded-3 me-2">Cancelar</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-custom-primary">
                            <i class="bi bi-save me-1"></i> <?= $modal_editar ? 'Actualizar Modal' : 'Guardar Aviso' ?>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Lista de Modales Registrados -->
    <div class="glass-card">
        <h4 class="mb-3 text-white">
            <i class="bi bi-list-task text-info me-2"></i> Avisos Registrados
        </h4>

        <?php if (empty($modales)): ?>
            <p class="text-white-50 mb-0">No se ha creado ningún aviso de actualización todavía.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom table-hover">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th>
                            <th>Título</th>
                            <th>Versión</th>
                            <th>Estado</th>
                            <th>Fecha</th>
                            <th class="text-end" style="width: 220px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($modales as $m): ?>
                            <tr>
                                <td>#<?= (int)$m['id'] ?></td>
                                <td>
                                    <strong><?= h($m['titulo']) ?></strong>
                                    <div class="small text-white-50 text-truncate" style="max-width: 400px;">
                                        <?= nl2br(h(mb_strimwidth($m['contenido'], 0, 100, "..."))) ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($m['version'])): ?>
                                        <span class="badge bg-secondary"><?= h($m['version']) ?></span>
                                    <?php else: ?>
                                        <span class="text-white-50">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($m['activo'] == 1): ?>
                                        <span class="badge-activo"><i class="bi bi-check-circle-fill me-1"></i> Activo</span>
                                    <?php else: ?>
                                        <span class="badge-inactivo"><i class="bi bi-pause-circle-fill me-1"></i> Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-white-50">
                                    <?= date("d/m/Y H:i", strtotime($m['fecha_creacion'])) ?>
                                </td>
                                <td class="text-end">
                                    <!-- Toggle Activo -->
                                    <form action="configurar_modales.php" method="POST" class="d-inline">
                                        <input type="hidden" name="accion" value="toggle_activo">
                                        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                        <button type="submit" class="btn btn-sm <?= $m['activo'] == 1 ? 'btn-outline-warning' : 'btn-outline-success' ?> rounded-3" 
                                                title="<?= $m['activo'] == 1 ? 'Desactivar aviso' : 'Activar aviso' ?>">
                                            <i class="bi <?= $m['activo'] == 1 ? 'bi-eye-slash-fill' : 'bi-eye-fill' ?>"></i>
                                        </button>
                                    </form>

                                    <!-- Editar -->
                                    <a href="configurar_modales.php?edit=<?= (int)$m['id'] ?>" class="btn btn-sm btn-outline-info rounded-3" title="Editar modal">
                                        <i class="bi bi-pencil-fill"></i>
                                    </a>

                                    <!-- Eliminar -->
                                    <form action="configurar_modales.php" method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este aviso de actualización?');">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-3" title="Eliminar aviso">
                                            <i class="bi bi-trash-fill"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . "/footer.php"; ?>

</body>
</html>
