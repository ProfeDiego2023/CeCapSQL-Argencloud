<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/auth.php";

require_once __DIR__ . "/auditar.php";

proteger();

if (!permiso("admin")) {
    die("Acceso denegado");
}

$miId = (int)$_SESSION['id_instru'];

// Asegurar columna permiso_config_cert
$checkCol = $conn->query("SHOW COLUMNS FROM usuarios LIKE 'permiso_config_cert'");
if ($checkCol && $checkCol->num_rows === 0) {
    $conn->query("ALTER TABLE usuarios ADD COLUMN permiso_config_cert TINYINT(1) NOT NULL DEFAULT 0 AFTER permiso_config_form");
}

// Asegurar columna permiso_cert_email
$checkCol2 = $conn->query("SHOW COLUMNS FROM usuarios LIKE 'permiso_cert_email'");
if ($checkCol2 && $checkCol2->num_rows === 0) {
    $conn->query("ALTER TABLE usuarios ADD COLUMN permiso_cert_email TINYINT(1) NOT NULL DEFAULT 0 AFTER permiso_certificados");
}

// Asegurar columna permiso_calendario
$checkCol3 = $conn->query("SHOW COLUMNS FROM usuarios LIKE 'permiso_calendario'");
if ($checkCol3 && $checkCol3->num_rows === 0) {
    $conn->query("ALTER TABLE usuarios ADD COLUMN permiso_calendario TINYINT(1) NOT NULL DEFAULT 0 AFTER permiso_config_cert");
}

// Actualizar actividad
$stmt = $conn->prepare("UPDATE usuarios SET last_activity = NOW() WHERE id = ?");
$stmt->bind_param("i", $miId);
$stmt->execute();
$stmt->close();

/* ==============================
   CREAR USUARIO
================================ */
if (isset($_POST['guardar']) && empty($_POST['editar_id'])) {

    $usuario  = mb_strtoupper(trim($_POST['usuario']  ?? ''), 'UTF-8');
    $nombre   = mb_strtoupper(trim($_POST['nombre']   ?? ''), 'UTF-8');
    $apellido = mb_strtoupper(trim($_POST['apellidos'] ?? ''), 'UTF-8');
    $rol      = trim($_POST['rol'] ?? 'VISITANTE');

    if (empty($_POST['pass'])) {
        die("Ingrese una contraseña");
    }
    $pass = password_hash($_POST['pass'], PASSWORD_DEFAULT);

    $p_registrar    = isset($_POST['permiso_registrar'])    ? 1 : 0;
    $p_editar       = isset($_POST['permiso_editar'])       ? 1 : 0;
    $p_eliminar     = isset($_POST['permiso_eliminar'])     ? 1 : 0;
    $p_certificados = isset($_POST['permiso_certificados']) ? 1 : 0;
    $p_reportes     = isset($_POST['permiso_reportes'])     ? 1 : 0;
    $p_admin        = isset($_POST['permiso_panel_admin'])  ? 1 : 0;
    $p_ver_todos    = isset($_POST['permiso_ver_todos'])    ? 1 : 0;

    // Verificar si el usuario ya existe
    $ck = $conn->prepare("SELECT id FROM usuarios WHERE usuario = ? LIMIT 1");
    $ck->bind_param("s", $usuario);
    $ck->execute();
    $ck->store_result();
    if ($ck->num_rows > 0) {
        $ck->close();
        $_SESSION['msg_error'] = "El nombre de usuario '$usuario' ya existe. Elegí otro.";
        header("Location: usuarios.php");
        exit;
    }
    $ck->close();

    $p_buscar      = isset($_POST['permiso_buscar'])      ? 1 : 0;
    $p_formularios = isset($_POST['permiso_formularios']) ? 1 : 0;
    $p_config_form = isset($_POST['permiso_config_form']) ? 1 : 0;
    $p_config_cert = isset($_POST['permiso_config_cert']) ? 1 : 0;
    $p_cert_email  = isset($_POST['permiso_cert_email'])  ? 1 : 0;
    $p_calendario  = isset($_POST['permiso_calendario'])  ? 1 : 0;

    $stmt = $conn->prepare("
        INSERT INTO usuarios
            (usuario, pass, nombre, apellidos, rol,
             permiso_registrar, permiso_editar, permiso_eliminar,
             permiso_certificados, permiso_cert_email, permiso_reportes, permiso_panel_admin,
             permiso_ver_todos, permiso_buscar, permiso_formularios, permiso_config_form,
             permiso_config_cert, permiso_calendario,
             activo, debe_cambiar_pass, eliminado)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,1,0)
    ");
    $stmt->bind_param(
        "sssssiiiiiiiiiiiii",
        $usuario, $pass, $nombre, $apellido, $rol,
        $p_registrar, $p_editar, $p_eliminar,
        $p_certificados, $p_cert_email, $p_reportes, $p_admin, $p_ver_todos,
        $p_buscar, $p_formularios, $p_config_form,
        $p_config_cert, $p_calendario
    );

    if (!$stmt->execute()) {
        $stmt->close();
        $_SESSION['msg_error'] = "Error al crear el usuario. Intentá con otro nombre.";
        header("Location: usuarios.php");
        exit;
    }
    $stmt->close();

    auditar($conn, "CREAR", "USUARIOS", "Se creó usuario $usuario");
    $_SESSION['msg_ok'] = "Usuario '$usuario' creado correctamente.";
    header("Location: usuarios.php");
    exit;
}

/* ==============================
   EDITAR USUARIO
================================ */
if (!empty($_POST['editar_id'])) {

    $id = (int)$_POST['editar_id'];
    if ($id === 1) die("No se puede modificar al superadmin");

    $usuario  = mb_strtoupper(trim($_POST['usuario']   ?? ''), 'UTF-8');
    $nombre   = mb_strtoupper(trim($_POST['nombre']    ?? ''), 'UTF-8');
    $apellido = mb_strtoupper(trim($_POST['apellidos'] ?? ''), 'UTF-8');
    $rol      = trim($_POST['rol'] ?? 'VISITANTE');

    // Cambio de contraseña normal (campo pass del modal)
    if (!empty($_POST['pass'])) {
        $pass = password_hash($_POST['pass'], PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE usuarios SET pass = ?, debe_cambiar_pass = 0 WHERE id = ?");
        $stmt->bind_param("si", $pass, $id);
        $stmt->execute();
        $stmt->close();
    }

    // Reset de contraseña por admin (con forzar cambio)
    if (!empty($_POST['pass_reset']) && !empty($_POST['forzar_cambio'])) {
        $passReset   = password_hash(trim($_POST['pass_reset']), PASSWORD_DEFAULT);
        $forzarCambio = (int)$_POST['forzar_cambio'];
        $stmt = $conn->prepare("UPDATE usuarios SET pass = ?, debe_cambiar_pass = ? WHERE id = ?");
        $stmt->bind_param("sii", $passReset, $forzarCambio, $id);
        $stmt->execute();
        $stmt->close();
        auditar($conn, "RESET_PASS", "USUARIOS", "Admin reseteó contraseña ID $id");
    }

    $p_registrar    = isset($_POST['permiso_registrar'])    ? 1 : 0;
    $p_editar       = isset($_POST['permiso_editar'])       ? 1 : 0;
    $p_eliminar     = isset($_POST['permiso_eliminar'])     ? 1 : 0;
    $p_certificados = isset($_POST['permiso_certificados']) ? 1 : 0;
    $p_reportes     = isset($_POST['permiso_reportes'])     ? 1 : 0;
    $p_admin        = isset($_POST['permiso_panel_admin'])  ? 1 : 0;
    $p_ver_todos    = isset($_POST['permiso_ver_todos'])    ? 1 : 0;

    $p_buscar      = isset($_POST['permiso_buscar'])      ? 1 : 0;
    $p_formularios = isset($_POST['permiso_formularios']) ? 1 : 0;
    $p_config_form = isset($_POST['permiso_config_form']) ? 1 : 0;
    $p_config_cert = isset($_POST['permiso_config_cert']) ? 1 : 0;
    $p_cert_email  = isset($_POST['permiso_cert_email'])  ? 1 : 0;
    $p_calendario  = isset($_POST['permiso_calendario'])  ? 1 : 0;

    $stmt = $conn->prepare("
        UPDATE usuarios SET
            usuario = ?, nombre = ?, apellidos = ?, rol = ?,
            permiso_registrar = ?, permiso_editar = ?, permiso_eliminar = ?,
            permiso_certificados = ?, permiso_cert_email = ?, permiso_reportes = ?,
            permiso_panel_admin = ?, permiso_ver_todos = ?,
            permiso_buscar = ?, permiso_formularios = ?, permiso_config_form = ?,
            permiso_config_cert = ?, permiso_calendario = ?
        WHERE id = ?
    ");
    $stmt->bind_param(
        "ssssiiiiiiiiiiiiii",
        $usuario, $nombre, $apellido, $rol,
        $p_registrar, $p_editar, $p_eliminar,
        $p_certificados, $p_cert_email, $p_reportes, $p_admin, $p_ver_todos,
        $p_buscar, $p_formularios, $p_config_form,
        $p_config_cert, $p_calendario,
        $id
    );
    $stmt->execute();
    $stmt->close();

    auditar($conn, "EDITAR", "USUARIOS", "Editó usuario ID $id");
    header("Location: usuarios.php");
    exit;
}

/* ==============================
   ACCIONES GET — con prepare
================================ */
if (isset($_GET['kick'])) {
    $id = (int)$_GET['kick'];
    if ($id !== $miId && $id !== 1) {
        $stmt = $conn->prepare("UPDATE usuarios SET forzar_logout = 1 WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        auditar($conn, "FORZAR LOGOUT", "USUARIOS", "Expulsó ID $id");
    }
    header("Location: usuarios.php");
    exit;
}

if (isset($_GET['del'])) {
    $id = (int)$_GET['del'];
    if ($id !== 1 && $id !== $miId) {
        $stmt = $conn->prepare("UPDATE usuarios SET eliminado = 1 WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        auditar($conn, "ELIMINAR", "USUARIOS", "Envió a papelera $id");
    }
    header("Location: usuarios.php");
    exit;
}

if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    if ($id !== 1 && $id !== $miId) {
        $stmt = $conn->prepare("UPDATE usuarios SET activo = IF(activo=1,0,1) WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        auditar($conn, "ESTADO", "USUARIOS", "Cambio estado $id");
    }
    header("Location: usuarios.php");
    exit;
}

/* ==============================
   LISTAR USUARIOS
================================ */
// Total de usuarios activos
$stmtTotal = $conn->prepare("SELECT COUNT(*) FROM usuarios WHERE eliminado = 0");
$stmtTotal->execute();
$stmtTotal->bind_result($totalUsuarios);
$stmtTotal->fetch();
$stmtTotal->close();

$stmt = $conn->prepare("
    SELECT id, usuario, nombre, apellidos, rol, activo,
           last_activity, forzar_logout,
           permiso_registrar, permiso_editar, permiso_eliminar,
           permiso_certificados, permiso_cert_email, permiso_reportes,
           permiso_panel_admin, permiso_ver_todos,
           permiso_buscar, permiso_formularios, permiso_config_form,
           permiso_config_cert, permiso_calendario
    FROM usuarios
    WHERE eliminado = 0
    ORDER BY activo DESC, apellidos ASC
");
$stmt->execute();
$usuarios = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Administración de Usuarios — CeCap</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/variables.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<style>
body {
    background: linear-gradient(135deg, #504254, #3C313F);
    background-attachment: fixed;
    min-height: 100vh;
    color: #F0EDF2;
    font-family: 'Segoe UI', sans-serif;
    padding: 20px;
}

/* Glass card */
.glass {
    background: rgba(255,255,255,.10);
    backdrop-filter: blur(14px);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 20px;
    box-shadow: 0 20px 50px rgba(0,0,0,.50);
    padding: 20px;
}

/* Tabla */
.table         { color: #F0EDF2; margin-bottom: 0; }
.table thead th {
    background: rgba(80,66,84,.35);
    font-size: .75rem;
    text-transform: uppercase;
    color: #C7E4CF;
    white-space: nowrap;
}
.table tbody tr { transition: background .15s; }
.table tbody tr:hover { background: rgba(255,255,255,.08) !important; }

.badge-online  { background: #C7E4CF; color: #000; }
.badge-offline { background: #ef4444; }

/* Modal */
.modal-content {
    background: rgba(10,20,35,.97);
    backdrop-filter: blur(16px);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 20px;
    color: #F0EDF2;
}
.modal-header, .modal-footer { border-color: rgba(255,255,255,.12) !important; }
.modal-content .text-muted { color: #FFD166 !important; }

/* Inputs */
.form-control,
.form-select {
    background: rgba(0,0,0,.35) !important;
    color: #fff !important;
    border: 1px solid rgba(255,255,255,.22) !important;
    border-radius: 12px !important;
}
.form-control:focus,
.form-select:focus {
    border-color: #5D5E70 !important;
    box-shadow: 0 0 0 2px rgba(93,94,112,.25) !important;
    background: rgba(0,0,0,.45) !important;
}
.form-control::placeholder { color: rgba(255,255,255,.45) !important; }
.form-select option { background: #1b2a3a; color: #fff; }
label { color: #C7E4CF; font-size: .85rem; }
</style>
</head>

<body>

<?php if (!empty($_SESSION['msg_error'])): ?>
<div class="alert alert-danger alert-dismissible fade show mx-3 mt-3" role="alert">
    ⚠️ <?= htmlspecialchars($_SESSION['msg_error']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['msg_error']); endif; ?>

<?php if (!empty($_SESSION['msg_ok'])): ?>
<div class="alert alert-success alert-dismissible fade show mx-3 mt-3" role="alert">
    ✅ <?= htmlspecialchars($_SESSION['msg_ok']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['msg_ok']); endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0 fw-bold">👤 Usuarios
        <span class="badge bg-info text-dark ms-2" style="font-size:.7rem;vertical-align:middle">
            <?= $totalUsuarios ?> registrados
        </span>
    </h4>
    <div class="d-flex gap-2">
        <button class="btn btn-info btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalUsuario"
                onclick="resetModal()">+ Nuevo usuario</button>
        <a href="panel.php" class="btn btn-secondary btn-sm">⬅ Panel</a>
    </div>
</div>

<div class="glass mb-4">
<div class="table-responsive">
<table class="table table-dark table-striped align-middle mb-0">
<thead>
<tr>
    <th>Estado</th>
    <th>Usuario</th>
    <th>Nombre</th>
    <th>Rol</th>
    <th>Activo</th>
    <th>Editar</th>
    <th>Eliminar</th>
    <th>Expulsar</th>
</tr>
</thead>
<tbody>
<?php while ($u = $usuarios->fetch_assoc()):
    $online = ($u['id'] == $miId) ||
              (!empty($u['last_activity']) && strtotime($u['last_activity']) > time() - 300);
    $esMi   = ($u['id'] == $miId);
?>
<tr>
    <td>
        <span id="estado_<?= $u['id'] ?>"
              class="badge <?= $online ? 'badge-online' : 'badge-offline' ?>">
            <?= $online ? 'Online' : 'Off' ?>
        </span>
    </td>
    <td><?= htmlspecialchars($u['usuario']) ?></td>
    <td><?= htmlspecialchars($u['apellidos'] . ', ' . $u['nombre']) ?></td>
    <td>
        <?php
        $colores = [
            'ADMIN'         => 'danger',
            'ADMINISTRADOR' => 'danger',
            'COORDINADOR'   => 'warning text-dark',
            'INSTRUCTOR'    => 'info',
            'COLABORADOR'   => 'primary',
            'VISITANTE'     => 'secondary',
        ];
        // Normalizar: quitar tildes para que COORDINADOR/Coordinador matcheen siempre
        $rolNorm = strtr(mb_strtoupper($u['rol'],'UTF-8'),
            ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N']);
        $color = $colores[$rolNorm] ?? 'secondary';
        ?>
        <span class="badge bg-<?= $color ?> fw-bold"><?= htmlspecialchars($u['rol']) ?></span>
    </td>
    <td>
        <?php if (!$esMi): ?>
        <a href="?toggle=<?= $u['id'] ?>"
           class="btn btn-sm <?= $u['activo'] ? 'btn-success' : 'btn-danger' ?>">
            <?= $u['activo'] ? 'Activo' : 'Bloq' ?>
        </a>
        <?php else: ?>
        <span class="badge bg-success">Activo</span>
        <?php endif; ?>
    </td>
    <td>
        <button class="btn btn-warning btn-sm editarBtn"
            data-id="<?= $u['id'] ?>"
            data-usuario="<?= htmlspecialchars($u['usuario']) ?>"
            data-nombre="<?= htmlspecialchars($u['nombre']) ?>"
            data-apellidos="<?= htmlspecialchars($u['apellidos']) ?>"
            data-rol="<?= htmlspecialchars($u['rol']) ?>"
            data-reg="<?= $u['permiso_registrar'] ?>"
            data-edit="<?= $u['permiso_editar'] ?>"
            data-del="<?= $u['permiso_eliminar'] ?>"
            data-cert="<?= $u['permiso_certificados'] ?>"
            data-certemail="<?= $u['permiso_cert_email'] ?? 0 ?>"
            data-rep="<?= $u['permiso_reportes'] ?>"
            data-admin="<?= $u['permiso_panel_admin'] ?>"
            data-all="<?= $u['permiso_ver_todos'] ?>"
            data-buscar="<?= $u['permiso_buscar']       ?? 0 ?>"
            data-form="<?= $u['permiso_formularios']    ?? 0 ?>"
            data-cfgform="<?= $u['permiso_config_form'] ?? 0 ?>"
            data-cfgcert="<?= $u['permiso_config_cert'] ?? 0 ?>"
            data-cal="<?= $u['permiso_calendario']      ?? 0 ?>"
            data-bs-toggle="modal"
            data-bs-target="#modalUsuario">
            ✏
        </button>
    </td>
    <td>
        <?php if (!$esMi && $u['id'] != 1): ?>
        <a href="?del=<?= $u['id'] ?>"
           class="btn btn-danger btn-sm"
           onclick="return confirm('¿Eliminar usuario <?= htmlspecialchars($u['usuario']) ?>?')">
            🗑
        </a>
        <?php else: ?>
        <span class="text-muted">—</span>
        <?php endif; ?>
    </td>
    <td>
        <?php if (!$esMi): ?>
        <a href="?kick=<?= $u['id'] ?>"
           class="btn btn-dark btn-sm"
           onclick="return confirm('¿Expulsar a <?= htmlspecialchars($u['usuario']) ?>?')">
            ⛔
        </a>
        <?php else: ?>
        <span class="text-muted">—</span>
        <?php endif; ?>
    </td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
</div>
</div><!-- /table-responsive -->
</div><!-- /glass -->

<!-- ══════════════════════════════
     MODAL CREAR / EDITAR
══════════════════════════════ -->
<div class="modal fade" id="modalUsuario" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<form method="POST" id="formUsuario">
<input type="hidden" name="editar_id" id="editar_id">

<div class="modal-header border-secondary">
    <h5 id="modalTitle">Nuevo usuario</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
<div class="row g-3">

    <div class="col-md-4">
        <label>Usuario</label>
        <input name="usuario" id="inp-usuario" class="form-control" required>
    </div>
    <div class="col-md-4">
        <label>Contraseña <small id="pass-hint" style="color:#FFD166">(requerida)</small></label>
        <div class="input-group">
            <input type="password" name="pass" id="inp-pass" class="form-control">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnVerPass"
                    title="Mostrar/ocultar" onclick="toggleVerPass()">👁</button>
        </div>
    </div>

    <!-- Sección reset — solo visible al editar -->
    <div class="col-12" id="seccionReset" style="display:none">
        <hr class="border-secondary">
        <h6 class="text-warning">🔑 Restablecer contraseña</h6>
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="small" style="color:#FFD166">Nueva contraseña manual</label>
                <div class="input-group">
                    <input type="text" id="inp-pass-reset" class="form-control"
                           placeholder="Escribir nueva contraseña"
                           autocomplete="off">
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                            onclick="toggleVerReset()" title="Ver">👁</button>
                </div>
            </div>
            <div class="col-md-3">
                <button type="button" class="btn btn-warning btn-sm w-100"
                        onclick="generarAleatoria()">
                    🎲 Generar aleatoria
                </button>
            </div>
            <div class="col-md-4">
                <div id="passGenerada" class="p-2 rounded text-center fw-bold"
                     style="background:rgba(255,220,0,.15);border:1px solid rgba(255,220,0,.3);
                            font-size:.9rem;letter-spacing:.1em;display:none;cursor:pointer"
                     title="Clic para copiar" onclick="copiarPass()">
                </div>
            </div>
        </div>
        <div class="mt-2">
            <button type="button" class="btn btn-danger btn-sm" onclick="aplicarReset()">
                ✅ Aplicar reset y forzar cambio en próximo login
            </button>
            <small class="ms-2" style="color:#FFD166">El usuario deberá cambiar la contraseña al ingresar.</small>
        </div>
        <input type="hidden" name="pass_reset" id="inp-pass-reset-hidden">
        <input type="hidden" name="forzar_cambio" id="inp-forzar-cambio" value="0">
    </div>
    <div class="col-md-4">
        <label>Rol</label>
        <select name="rol" id="inp-rol" class="form-select">
            <option value="VISITANTE">Visitante</option>
            <option value="COLABORADOR">Colaborador</option>
            <option value="COORDINADOR">Coordinador</option>
            <option value="INSTRUCTOR">Instructor</option>
            <option value="ADMIN">Administrador</option>
        </select>
    </div>
    <div class="col-md-6">
        <label>Apellidos</label>
        <input name="apellidos" id="inp-apellidos" class="form-control">
    </div>
    <div class="col-md-6">
        <label>Nombre</label>
        <input name="nombre" id="inp-nombre" class="form-control">
    </div>

    <div class="col-12"><hr class="border-secondary"><h6 class="text-info">Permisos</h6></div>

    <div class="col-md-4"><label><input type="checkbox" id="permiso_registrar"    name="permiso_registrar"    value="1"> Registrar</label></div>
    <div class="col-md-4"><label><input type="checkbox" id="permiso_editar"       name="permiso_editar"       value="1"> Editar</label></div>
    <div class="col-md-4"><label><input type="checkbox" id="permiso_eliminar"     name="permiso_eliminar"     value="1"> Eliminar</label></div>
    <div class="col-md-4"><label><input type="checkbox" id="permiso_certificados" name="permiso_certificados" value="1"> Certificados</label></div>
    <div class="col-md-4"><label><input type="checkbox" id="permiso_reportes"     name="permiso_reportes"     value="1"> Reportes</label></div>
    <div class="col-md-4"><label><input type="checkbox" id="permiso_panel_admin"  name="permiso_panel_admin"  value="1"> Panel Admin</label></div>
    <div class="col-md-4"><label><input type="checkbox" id="permiso_ver_todos"    name="permiso_ver_todos"    value="1"> Ver todo</label></div>

    <div class="col-12"><hr class="border-secondary"><h6 class="text-warning">Acceso a secciones</h6></div>

    <div class="col-md-4">
        <label><input type="checkbox" id="permiso_buscar"      name="permiso_buscar"      value="1">
        🔍 Buscar asistentes</label>
        <div style="font-size:.72rem;color:#FFD166">buscar_asistentes.php</div>
    </div>
    <div class="col-md-4">
        <label><input type="checkbox" id="permiso_registrar2"  name="permiso_registrar"   value="1">
        ➕ Nuevos asistentes</label>
        <div style="font-size:.72rem;color:#FFD166">registro_manual.php</div>
    </div>
    <div class="col-md-4">
        <label><input type="checkbox" id="permiso_formularios" name="permiso_formularios" value="1">
        📄 Generar formularios</label>
        <div style="font-size:.72rem;color:#FFD166">asistentes.php</div>
    </div>
    <div class="col-md-4">
        <label><input type="checkbox" id="permiso_config_form" name="permiso_config_form" value="1">
        ⚙️ Configurar formulario</label>
        <div style="font-size:.72rem;color:#FFD166">config_form_eventos.php</div>
    </div>
    <div class="col-md-4">
        <label><input type="checkbox" id="permiso_config_cert" name="permiso_config_cert" value="1">
        🏷️ Configurar certificados</label>
        <div style="font-size:.72rem;color:#FFD166">configurar_certificados.php</div>
    </div>
    <div class="col-md-4">
        <label><input type="checkbox" id="permiso_certificados2" name="permiso_certificados" value="1">
        📋 Emitir certificados</label>
        <div style="font-size:.72rem;color:#FFD166">filtrar_fechas.php</div>
    </div>
    <div class="col-md-4">
        <label><input type="checkbox" id="permiso_cert_email" name="permiso_cert_email" value="1">
        ✉️ Enviar certificados por email</label>
        <div style="font-size:.72rem;color:#FFD166">enviar_certificados_email.php</div>
    </div>
    <div class="col-md-4">
        <label><input type="checkbox" id="permiso_calendario" name="permiso_calendario" value="1">
        📅 Programación de Calendario</label>
        <div style="font-size:.72rem;color:#FFD166">calendario_eventos.php</div>
    </div>

</div>
</div>

<div class="modal-footer border-secondary">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
    <button class="btn btn-info" name="guardar">Guardar</button>
</div>

</form>
</div>
</div>
</div>

<script>
/* ── Reset modal para nuevo usuario ── */
function resetModal() {
    document.getElementById("modalTitle").textContent = "Nuevo usuario";
    document.getElementById("editar_id").value = "";
    document.getElementById("inp-usuario").value = "";
    document.getElementById("inp-pass").value = "";
    document.getElementById("inp-apellidos").value = "";
    document.getElementById("inp-nombre").value = "";
    document.getElementById("inp-rol").value = "VISITANTE";
    document.getElementById("pass-hint").textContent = "(requerida)";
    document.getElementById("seccionReset").style.display = "none";
    document.getElementById("inp-pass-reset-hidden").value = "";
    document.getElementById("inp-forzar-cambio").value = "0";
    ["permiso_registrar","permiso_editar","permiso_eliminar",
     "permiso_certificados","permiso_reportes","permiso_panel_admin","permiso_ver_todos",
     "permiso_buscar","permiso_formularios","permiso_config_form","permiso_config_cert",
     "permiso_registrar2","permiso_certificados2","permiso_cert_email","permiso_calendario"]
        .forEach(id => document.getElementById(id).checked = false);
}

/* ── Poblar modal para editar ── */
document.querySelectorAll(".editarBtn").forEach(btn => {
    btn.addEventListener("click", () => {
        document.getElementById("modalTitle").textContent = "Editar usuario";
        document.getElementById("editar_id").value        = btn.dataset.id;
        document.getElementById("inp-usuario").value      = btn.dataset.usuario;
        document.getElementById("inp-apellidos").value    = btn.dataset.apellidos;
        document.getElementById("inp-nombre").value       = btn.dataset.nombre;
        document.getElementById("inp-rol").value          = btn.dataset.rol;
        document.getElementById("inp-pass").value         = "";
        document.getElementById("pass-hint").textContent   = "(dejar vacío para no cambiar)";
        document.getElementById("seccionReset").style.display = "block";
        document.getElementById("inp-pass-reset").value    = "";
        document.getElementById("inp-pass-reset-hidden").value = "";
        document.getElementById("inp-forzar-cambio").value = "0";
        document.getElementById("passGenerada").style.display  = "none";

        document.getElementById("permiso_registrar").checked    = btn.dataset.reg   == 1;
        document.getElementById("permiso_editar").checked       = btn.dataset.edit  == 1;
        document.getElementById("permiso_eliminar").checked     = btn.dataset.del   == 1;
        document.getElementById("permiso_certificados").checked = btn.dataset.cert  == 1;
        document.getElementById("permiso_reportes").checked     = btn.dataset.rep   == 1;
        document.getElementById("permiso_panel_admin").checked  = btn.dataset.admin   == 1;
        document.getElementById("permiso_ver_todos").checked    = btn.dataset.all     == 1;
        document.getElementById("permiso_buscar").checked       = btn.dataset.buscar  == 1;
        document.getElementById("permiso_formularios").checked  = btn.dataset.form    == 1;
        document.getElementById("permiso_config_form").checked  = btn.dataset.cfgform == 1;
        document.getElementById("permiso_config_cert").checked  = btn.dataset.cfgcert == 1;
        // Sección acceso — sincronizar permiso_registrar2 con permiso_registrar
        document.getElementById("permiso_registrar2").checked   = btn.dataset.reg     == 1;
        document.getElementById("permiso_certificados2").checked= btn.dataset.cert    == 1;
        document.getElementById("permiso_cert_email").checked  = btn.dataset.certemail == 1;
        document.getElementById("permiso_calendario").checked  = btn.dataset.cal       == 1;
    });
});

/* ── Gestión de contraseñas ── */
function toggleVerPass() {
    const inp = document.getElementById("inp-pass");
    inp.type = inp.type === "password" ? "text" : "password";
}

function toggleVerReset() {
    const inp = document.getElementById("inp-pass-reset");
    inp.type = inp.type === "password" ? "text" : "password";
}

function generarAleatoria() {
    const chars = "ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$";
    let pass = "";
    for (let i = 0; i < 10; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById("inp-pass-reset").value = pass;
    const div = document.getElementById("passGenerada");
    div.textContent = pass;
    div.style.display = "block";
    // Copiar al portapapeles automáticamente
    navigator.clipboard?.writeText(pass).catch(() => {});
}

function copiarPass() {
    const txt = document.getElementById("passGenerada").textContent;
    navigator.clipboard?.writeText(txt).then(() => {
        const div = document.getElementById("passGenerada");
        const orig = div.textContent;
        div.textContent = "✅ Copiado!";
        setTimeout(() => div.textContent = orig, 1200);
    });
}

function aplicarReset() {
    const pass = document.getElementById("inp-pass-reset").value.trim();
    if (!pass) {
        alert("Escribí o generá una contraseña primero.");
        return;
    }
    if (!confirm("¿Confirmar reset de contraseña? El usuario deberá cambiarla al próximo login.")) return;

    document.getElementById("inp-pass-reset-hidden").value = pass;
    document.getElementById("inp-forzar-cambio").value = "1";

    const div = document.getElementById("passGenerada");
    div.textContent = "🔑 Reset listo: " + pass;
    div.style.background = "rgba(0,200,100,.2)";
    div.style.display = "block";
}

/* ── Mayúsculas automáticas en campos de texto ── */
["inp-usuario","inp-apellidos","inp-nombre"].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener("input", function() {
        const pos = this.selectionStart;
        this.value = this.value.toUpperCase();
        this.setSelectionRange(pos, pos);
    });
});

/* ── Estado online ── */
function actualizarOnline() {
    fetch("online_status.php")
        .then(r => r.json())
        .then(data => {
            for (const id in data) {
                const el = document.getElementById("estado_" + id);
                if (!el) continue;
                if (data[id] == 1) {
                    el.className = "badge badge-online";
                    el.textContent = "Online";
                } else {
                    el.className = "badge badge-offline";
                    el.textContent = "Off";
                }
            }
        });
}
setInterval(actualizarOnline, 10000);

/* ── Keep alive ── */
setInterval(() => { fetch("keep_alive.php"); }, 30000);

/* ── Forzar logout ── */
setInterval(() => {
    fetch("check_force_logout.php")
        .then(r => r.text())
        .then(t => {
            if (t === "logout") {
                alert("Sesión cerrada por administrador");
                window.location = "login.php";
            }
        });
}, 5000);
</script>

</body>
</html>