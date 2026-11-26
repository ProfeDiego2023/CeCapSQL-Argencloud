<?php
session_start();
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/auditar.php";

proteger();
if (!permiso("config_cert")) {
    die("Acceso denegado");
}

$conn->query("
    CREATE TABLE IF NOT EXISTS config_certificados (
        id INT PRIMARY KEY DEFAULT 1,
        activo TINYINT(1) NOT NULL DEFAULT 0,
        linea1 VARCHAR(255) NOT NULL DEFAULT '',
        linea2 VARCHAR(255) NOT NULL DEFAULT '',
        negrita TINYINT(1) NOT NULL DEFAULT 0,
        tamano INT NOT NULL DEFAULT 10
    )
");

if (isset($_POST['guardar'])) {
    $activo  = isset($_POST['activo']) ? 1 : 0;
    $linea1  = trim($_POST['linea1'] ?? '');
    $linea2  = trim($_POST['linea2'] ?? '');
    $negrita = isset($_POST['negrita']) ? 1 : 0;
    $tamano  = min(20, max(6, (int)($_POST['tamano'] ?? 10)));

    $st = $conn->prepare("
        INSERT INTO config_certificados (id, activo, linea1, linea2, negrita, tamano)
        VALUES (1, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            activo = VALUES(activo),
            linea1 = VALUES(linea1),
            linea2 = VALUES(linea2),
            negrita = VALUES(negrita),
            tamano = VALUES(tamano)
    ");
    $st->bind_param("issii", $activo, $linea1, $linea2, $negrita, $tamano);
    $st->execute();
    $st->close();

    auditar($conn, "CONFIG_CERT", "CONFIG", "Configuración de certificados actualizada");
    $_SESSION['msg_ok'] = "Configuración guardada correctamente.";
    header("Location: configurar_certificados.php");
    exit;
}

$st = $conn->prepare("SELECT activo, linea1, linea2, negrita, tamano FROM config_certificados WHERE id = 1");
$st->execute();
$config = $st->get_result()->fetch_assoc();
$st->close();

if (!$config) {
    $config = ['activo' => 0, 'linea1' => '', 'linea2' => '', 'negrita' => 0, 'tamano' => 10];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Configurar Certificados — CeCap</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/variables.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
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
.glass {
    background: rgba(255,255,255,.10);
    backdrop-filter: blur(14px);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 20px;
    box-shadow: 0 20px 50px rgba(0,0,0,.50);
    padding: 24px;
}
.form-control, .form-select {
    background: rgba(0,0,0,.35) !important;
    color: #fff !important;
    border: 1px solid rgba(255,255,255,.22) !important;
    border-radius: 12px !important;
}
.form-control:focus, .form-select:focus {
    border-color: #5D5E70 !important;
    box-shadow: 0 0 0 2px rgba(93,94,112,.25) !important;
    background: rgba(0,0,0,.45) !important;
}
.form-control::placeholder { color: rgba(255,255,255,.45) !important; }
label { color: #C7E4CF; font-size: .85rem; }
.modal-content {
    background: rgba(10,20,35,.97);
    backdrop-filter: blur(16px);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 20px;
    color: #F0EDF2;
}
.modal-header, .modal-footer { border-color: rgba(255,255,255,.08) !important; }

/* Switch personalizado */
.form-switch .form-check-input {
    width: 3rem;
    height: 1.5rem;
    cursor: pointer;
}
.form-switch .form-check-input:checked {
    background-color: #5D5E70;
    border-color: #5D5E70;
}

/* Preview certificado */
.cert-preview {
    background: #fff;
    color: #000;
    border-radius: 8px;
    padding: 40px 20px;
    text-align: center;
    font-family: 'Arial', sans-serif;
    position: relative;
    min-height: 250px;
}
.cert-preview .cert-logo {
    width: 40px;
    height: 40px;
    background: #ddd;
    border-radius: 4px;
    margin: 0 auto 12px;
}
.cert-preview .cert-title {
    font-size: 16px;
    font-weight: bold;
    margin-bottom: 20px;
}
.cert-preview .cert-body {
    font-size: 11px;
    line-height: 1.6;
    max-width: 400px;
    margin: 0 auto 24px;
}
.cert-preview .cert-msg-line {
    line-height: 1.1;
}
.cert-preview .cert-footer {
    margin-top: 20px;
    font-size: 10px;
    color: #666;
    border-top: 1px solid #ccc;
    padding-top: 12px;
}
</style>
</head>
<body>

<div class="container py-3">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0 fw-bold"><i class="bi bi-gear-fill"></i> Configurar Mensaje en Certificados</h4>
        <a href="panel.php" class="btn btn-secondary btn-sm">⬅ Panel</a>
    </div>

    <?php if (!empty($_SESSION['msg_ok'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        ✅ <?= htmlspecialchars($_SESSION['msg_ok']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['msg_ok']); endif; ?>

    <div class="glass">
        <form method="POST">

            <div class="row g-4">

                <!-- Activar/Desactivar -->
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="activo" name="activo"
                               <?= $config['activo'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="activo">
                            Activar mensaje en certificados
                        </label>
                        <div class="text-muted small" style="color:rgba(199,228,207,.6) !important;">
                            Mientras esté activado, todos los certificados mostrarán el mensaje configurado debajo del texto principal.
                        </div>
                    </div>
                </div>

                <!-- Línea 1 -->
                <div class="col-md-6">
                    <label for="linea1" class="form-label">Línea 1</label>
                    <input type="text" class="form-control" id="linea1" name="linea1"
                           maxlength="255" placeholder="Ej: Curso homologado por..."
                           value="<?= htmlspecialchars($config['linea1'], ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <!-- Línea 2 -->
                <div class="col-md-6">
                    <label for="linea2" class="form-label">Línea 2</label>
                    <input type="text" class="form-control" id="linea2" name="linea2"
                           maxlength="255" placeholder="Ej: Resolución N° 1234/2025"
                           value="<?= htmlspecialchars($config['linea2'], ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <!-- Negrita -->
                <div class="col-md-4">
                    <div class="form-check form-switch" style="padding-top:28px">
                        <input class="form-check-input" type="checkbox" id="negrita" name="negrita"
                               <?= $config['negrita'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="negrita">Negrita</label>
                    </div>
                </div>

                <!-- Tamaño -->
                <div class="col-md-4">
                    <label for="tamano" class="form-label">Tamaño del texto</label>
                    <select class="form-select" id="tamano" name="tamano">
                        <?php for ($i = 6; $i <= 20; $i++): ?>
                        <option value="<?= $i ?>" <?= $config['tamano'] == $i ? 'selected' : '' ?>><?= $i ?> pt</option>
                        <?php endfor; ?>
                    </select>
                </div>

            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#modalPreview">
                    <i class="bi bi-eye-fill"></i> Vista previa
                </button>
                <button type="submit" name="guardar" class="btn btn-success">
                    <i class="bi bi-check-lg"></i> Guardar configuración
                </button>
            </div>

        </form>
    </div>
</div>

<!-- ════════════════════════════════════════
     MODAL VISTA PREVIA
════════════════════════════════════════ -->
<div class="modal fade" id="modalPreview" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content">
<div class="modal-header border-secondary">
    <h5 class="fw-bold"><i class="bi bi-file-earmark-pdf-fill"></i> Vista previa del certificado</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <div class="cert-preview" id="previewContainer">
        <div class="cert-logo"></div>
        <div class="cert-title">CERTIFICADO DE ASISTENCIA</div>
        <div class="cert-body">
            Se certifica que el/la Sr/a <strong>APELLIDO NOMBRE</strong>, DNI 00.000.000,
            asistió al curso de <strong>LICENCIA</strong> de formación vial para conductores,
            dictado por la Municipalidad de Esperanza.
        </div>

        <!-- Mensaje configurado (vista previa dinámica) con recuadro -->
        <div id="previewMensaje" style="margin-bottom:20px;display:inline-block;padding:6px 16px;border:1px solid #999;border-radius:4px;">
            <div id="previewLinea1" class="cert-msg-line"
                 style="font-family:Arial;font-size:<?= $config['tamano'] ?>pt;<?= $config['negrita'] ? 'font-weight:bold' : '' ?>">
                <?= htmlspecialchars($config['linea1'] ?: '(línea 1)') ?>
            </div>
            <div id="previewLinea2" class="cert-msg-line"
                 style="font-family:Arial;font-size:<?= $config['tamano'] ?>pt;<?= $config['negrita'] ? 'font-weight:bold' : '' ?>">
                <?= htmlspecialchars($config['linea2'] ?: '(línea 2)') ?>
            </div>
        </div>

        <div class="cert-footer">
            Instructor _________________________ &nbsp;&nbsp; Emitido el 04/06/2026
        </div>
    </div>

    <div class="text-center mt-2">
        <small class="text-muted" style="color:rgba(199,228,207,.5) !important;">
            <i class="bi bi-info-circle"></i> Esta es una simulación visual. El diseño real puede variar según los datos del asistente.
        </small>
    </div>
</div>
<div class="modal-footer border-secondary">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
</div>
</div>
</div>
</div>

<?php include __DIR__ . "/footer.php"; ?>

<script>
// Actualizar preview en vivo cuando cambian los campos
document.getElementById('linea1').addEventListener('input', actualizarPreview);
document.getElementById('linea2').addEventListener('input', actualizarPreview);
document.getElementById('negrita').addEventListener('change', actualizarPreview);
document.getElementById('tamano').addEventListener('change', actualizarPreview);

function actualizarPreview() {
    const linea1 = document.getElementById('linea1').value || '(línea 1)';
    const linea2 = document.getElementById('linea2').value || '(línea 2)';
    const negrita = document.getElementById('negrita').checked;
    const tamano = document.getElementById('tamano').value;

    document.getElementById('previewLinea1').textContent = linea1;
    document.getElementById('previewLinea2').textContent = linea2;
    document.getElementById('previewLinea1').style.fontSize = tamano + 'pt';
    document.getElementById('previewLinea2').style.fontSize = tamano + 'pt';
    document.getElementById('previewLinea1').style.fontWeight = negrita ? 'bold' : 'normal';
    document.getElementById('previewLinea2').style.fontWeight = negrita ? 'bold' : 'normal';
}
</script>

</body>
</html>
