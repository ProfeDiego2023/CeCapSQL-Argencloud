<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/auditar.php";
require_once __DIR__ . "/email_certificado_fn.php";

proteger();
if (!permiso("config_cert")) {
    die("Acceso denegado");
}

email_asegurarTablas($conn);
$config = email_cargarConfig($conn);
$probarResult = null;

if (isset($_POST['probar'])) {
    $testCfg = [
        'smtp_host'   => trim($_POST['smtp_host'] ?? ''),
        'smtp_port'   => max(1, min(65535, (int)($_POST['smtp_port'] ?? 587))),
        'smtp_user'   => trim($_POST['smtp_user'] ?? ''),
        'smtp_pass'   => trim($_POST['smtp_pass'] ?? ''),
        'smtp_secure' => in_array($_POST['smtp_secure'] ?? 'tls', ['tls', 'ssl', 'none'], true)
            ? $_POST['smtp_secure'] : 'tls',
    ];
    if ($testCfg['smtp_pass'] === '') {
        $testCfg['smtp_pass'] = $config['smtp_pass'];
    }
    $probarResult = email_probarConexion($testCfg);
}

if (isset($_POST['guardar'])) {
    $activo     = isset($_POST['activo']) ? 1 : 0;
    $smtp_host  = trim($_POST['smtp_host'] ?? '');
    $smtp_port  = max(1, min(65535, (int)($_POST['smtp_port'] ?? 587)));
    $smtp_user  = trim($_POST['smtp_user'] ?? '');
    $smtp_pass  = trim($_POST['smtp_pass'] ?? '');
    $smtp_secure= in_array($_POST['smtp_secure'] ?? 'tls', ['tls', 'ssl', 'none'], true)
        ? $_POST['smtp_secure'] : 'tls';
    $from_email = strtolower(trim($_POST['from_email'] ?? ''));
    $from_name  = trim($_POST['from_name'] ?? 'CeCap - Municipalidad de Esperanza');
    $asunto     = trim($_POST['asunto'] ?? 'Tu certificado de asistencia - CeCap');
    $cuerpo     = trim($_POST['cuerpo'] ?? '');

    if ($smtp_pass === '') {
        $smtp_pass = $config['smtp_pass'];
    }

    $st = $conn->prepare("
        INSERT INTO config_email
            (id, activo, smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure,
             from_email, from_name, asunto, cuerpo)
        VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            activo = VALUES(activo),
            smtp_host = VALUES(smtp_host),
            smtp_port = VALUES(smtp_port),
            smtp_user = VALUES(smtp_user),
            smtp_pass = VALUES(smtp_pass),
            smtp_secure = VALUES(smtp_secure),
            from_email = VALUES(from_email),
            from_name = VALUES(from_name),
            asunto = VALUES(asunto),
            cuerpo = VALUES(cuerpo)
    ");
    $st->bind_param(
        "isisssssss",
        $activo,
        $smtp_host,
        $smtp_port,
        $smtp_user,
        $smtp_pass,
        $smtp_secure,
        $from_email,
        $from_name,
        $asunto,
        $cuerpo
    );
    $st->execute();
    $st->close();

    auditar($conn, "CONFIG_EMAIL", "CONFIG", "Configuración de email actualizada");
    $_SESSION['msg_ok'] = "Configuración de email guardada correctamente.";
    header("Location: configurar_email.php");
    exit;
}

$config = email_cargarConfig($conn);
$msgOk = $_SESSION['msg_ok'] ?? null;
unset($_SESSION['msg_ok']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Configurar Email — CeCap</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/variables.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body {
    background: linear-gradient(135deg, #504254, #3C313F);
    background-attachment: fixed;
    min-height: 100vh;
    color: #F0EDF2;
    font-family: 'Segoe UI', sans-serif;
    padding: 20px;
}
.glass {
    background: rgba(255,255,255,.10);
    backdrop-filter: blur(14px);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 22px;
    box-shadow: 0 20px 50px rgba(0,0,0,.50);
    padding: 28px;
    max-width: 760px;
    margin: 0 auto;
}
.form-control, .form-select, textarea.form-control {
    background: rgba(255,255,255,.08) !important;
    border: 1px solid rgba(255,255,255,.22) !important;
    color: #F0EDF2 !important;
    border-radius: 12px !important;
}
.form-control:focus, .form-select:focus, textarea.form-control:focus {
    border-color: #5D5E70 !important;
    box-shadow: 0 0 0 2px rgba(93,94,112,.25) !important;
}
.form-label { color: #C7E4CF; font-weight: 600; font-size: .88rem; }
.btn-guardar {
    background: linear-gradient(135deg, #DD4841, #5D5E70);
    border: none; border-radius: 14px; color: #fff;
    font-weight: 700; padding: 12px 24px;
}
.btn-volver {
    border: 2px solid rgba(93,94,112,.55);
    color: #5D5E70; border-radius: 14px; font-weight: 700;
    padding: 10px 20px; text-decoration: none;
}
.hint { font-size: .78rem; color: rgba(199,228,207,.65); }
</style>
</head>
<body>
<div class="glass">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="mb-0 fw-bold"><i class="bi bi-envelope-at-fill"></i> Configurar envío por Email</h4>
        <a href="panel.php" class="btn-volver"><i class="bi bi-arrow-left"></i> Panel</a>
    </div>

    <?php if ($msgOk): ?>
    <div class="alert alert-success"><?= htmlspecialchars($msgOk) ?></div>
    <?php endif; ?>

    <?php if ($probarResult !== null): ?>
        <?php if ($probarResult['ok']): ?>
        <div class="alert alert-success">Conexión SMTP exitosa. El servidor aceptó la conexión y la autenticación.</div>
        <?php else: ?>
        <div class="alert alert-danger">
            <strong>Fallo la conexión.</strong>
            <pre style="white-space:pre-wrap;font-size:.72rem;margin:8px 0 0;max-height:220px;overflow:auto"><?= htmlspecialchars($probarResult['debug']) ?></pre>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <form method="POST">
        <div class="form-check form-switch mb-4">
            <input class="form-check-input" type="checkbox" id="activo" name="activo" value="1"
                   <?= !empty($config['activo']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="activo">Activar envío automático de certificados por email</label>
        </div>

        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label">Servidor SMTP</label>
                <input type="text" name="smtp_host" class="form-control"
                       value="<?= htmlspecialchars($config['smtp_host']) ?>"
                       placeholder="smtp.gmail.com">
            </div>
            <div class="col-md-4">
                <label class="form-label">Puerto</label>
                <input type="number" name="smtp_port" class="form-control"
                       value="<?= (int)$config['smtp_port'] ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Usuario SMTP</label>
                <input type="text" name="smtp_user" class="form-control"
                       value="<?= htmlspecialchars($config['smtp_user']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Contraseña SMTP</label>
                <input type="password" name="smtp_pass" class="form-control"
                       placeholder="<?= $config['smtp_pass'] !== '' ? '••••••••' : 'Contraseña' ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Seguridad</label>
                <select name="smtp_secure" class="form-select">
                    <?php foreach (['tls' => 'TLS', 'ssl' => 'SSL', 'none' => 'Ninguna'] as $val => $label): ?>
                    <option value="<?= $val ?>" <?= ($config['smtp_secure'] ?? 'tls') === $val ? 'selected' : '' ?>>
                        <?= $label ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label">Email remitente</label>
                <input type="email" name="from_email" class="form-control"
                       value="<?= htmlspecialchars($config['from_email']) ?>"
                       placeholder="cecape@esperanza.gov.ar">
            </div>
            <div class="col-12">
                <label class="form-label">Nombre remitente</label>
                <input type="text" name="from_name" class="form-control"
                       value="<?= htmlspecialchars($config['from_name']) ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Asunto del email</label>
                <input type="text" name="asunto" class="form-control"
                       value="<?= htmlspecialchars($config['asunto']) ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Cuerpo del mensaje</label>
                <textarea name="cuerpo" class="form-control" rows="7"
                          placeholder="Usá {NOMBRE}, {DNI}, {LICENCIA}, {FECHA} y {LINK} como variables."><?= htmlspecialchars($config['cuerpo']) ?></textarea>
                <div class="hint mt-1">Variables disponibles: {NOMBRE}, {DNI}, {LICENCIA}, {FECHA} y {LINK} (enlace de descarga de un solo uso). Si el mensaje no contiene {LINK}, se agrega automáticamente al final.</div>
            </div>
        </div>

        <div class="d-flex gap-2 mt-4 flex-wrap">
            <button type="submit" name="guardar" value="1" class="btn-guardar">
                <i class="bi bi-save"></i> Guardar configuración
            </button>
            <button type="submit" name="probar" value="1" class="btn-volver">
                <i class="bi bi-plug"></i> Probar conexión SMTP
            </button>
            <a href="enviar_certificados_email.php" class="btn-volver">
                <i class="bi bi-send"></i> Ir a enviar certificados
            </a>
        </div>
    </form>
</div>
</body>
</html>
