<?php
session_start();
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/auditar.php";
proteger();

$esAdmin  = permiso('admin');
$qrDir    = __DIR__ . "/img/";
$qrFile   = $qrDir . "qr_asistencia.png";
$qrExiste = file_exists($qrFile);
$msg      = '';
$msgTipo  = '';

/* ==============================
   SUBIR QR — solo admin
================================ */
if ($esAdmin && isset($_FILES['qr_img']) && $_FILES['qr_img']['error'] === UPLOAD_ERR_OK) {

    $tmp  = $_FILES['qr_img']['tmp_name'];
    $mime = mime_content_type($tmp);

    if (!in_array($mime, ['image/png','image/jpeg','image/gif','image/webp'])) {
        $msg     = "Solo se permiten imágenes PNG, JPG, GIF o WEBP.";
        $msgTipo = "danger";
    } else {
        // Convertir a PNG si es necesario
        $destino = $qrDir . "qr_asistencia.png";
        $ok = false;

        if ($mime === 'image/png') {
            $ok = move_uploaded_file($tmp, $destino);
        } else {
            // Convertir con GD
            $src = match($mime) {
                'image/jpeg' => imagecreatefromjpeg($tmp),
                'image/gif'  => imagecreatefromgif($tmp),
                'image/webp' => imagecreatefromwebp($tmp),
                default      => null
            };
            if ($src) {
                $ok = imagepng($src, $destino);
                imagedestroy($src);
            }
        }

        if ($ok) {
            $qrExiste = true;
            $msg      = "✅ Código QR actualizado correctamente.";
            $msgTipo  = "success";
            auditar($conn, "SUBIR_QR", "QR", "Admin subió nueva imagen QR");
        } else {
            $msg     = "Error al guardar la imagen. Verificá permisos de la carpeta img/.";
            $msgTipo = "danger";
        }
    }
}

$qrUrl = "img/qr_asistencia.png" . ($qrExiste ? "?v=" . filemtime($qrFile) : '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Código QR — CeCap</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/variables.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

<style>
* { box-sizing: border-box; }
body {
    background: linear-gradient(135deg, #504254, #3C313F);
    background-attachment: fixed;
    min-height: 100vh;
    color: #F0EDF2;
    font-family: 'Segoe UI', sans-serif;
    padding: 0 0 40px;
}

/* Topbar */
.topbar {
    background: rgba(255,255,255,.08);
    backdrop-filter: blur(16px);
    border-bottom: 1px solid rgba(255,255,255,.13);
    padding: 12px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky; top: 0; z-index: 100;
}
.topbar h4 { margin: 0; font-weight: 700; }

/* Glass card */
.glass {
    background: rgba(255,255,255,.10);
    backdrop-filter: blur(14px);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 20px;
    box-shadow: 0 20px 50px rgba(0,0,0,.50);
    padding: 28px;
}

/* Inputs */
.form-control, .form-select {
    background: rgba(0,0,0,.35) !important;
    border: 1px solid rgba(255,255,255,.22) !important;
    color: #fff !important;
    border-radius: 12px !important;
}
.form-control:focus, .form-select:focus {
    border-color: #5D5E70 !important;
    box-shadow: 0 0 0 2px rgba(93,94,112,.25) !important;
}
label { color: #C7E4CF; font-size: .88rem; }

/* Vista previa QR */
.qr-preview {
    background: #fff;
    border-radius: 16px;
    padding: 20px;
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    box-shadow: 0 8px 30px rgba(0,0,0,.4);
}
.qr-preview img {
    width: 200px; height: 200px;
    object-fit: contain;
}

/* Marco impresión — oculto en pantalla */
.marco-impresion { display: none; }

/* ══════════════════════════════
   ESTILOS DE IMPRESIÓN
══════════════════════════════ */
@media print {
    body > *                { display: none !important; }
    .marco-impresion        { display: block !important; }
    .marco-impresion.activo { display: block !important; }

    @page {
        size: A4 portrait;
        margin: 10mm;
    }

    /* Modo A4 completo */
    .print-a4 {
        width: 190mm;
        min-height: 270mm;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        font-family: 'Segoe UI', Arial, sans-serif;
    }

    /* Modo mitad A4 — 2 copias */
    .print-mitad {
        width: 190mm;
        display: flex;
        flex-direction: column;
        gap: 0;
    }
    .print-mitad .copia {
        width: 190mm;
        height: 130mm;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        page-break-inside: avoid;
    }
    .print-mitad .separador {
        border-top: 1px dashed #999;
        margin: 0;
        width: 100%;
    }

    /* Marco visual */
    .marco-qr {
        border: 3px solid #1a3a6b;
        border-radius: 12px;
        padding: 20px 30px;
        text-align: center;
        background: #fff;
        color: #000;
    }
    .marco-qr .logo-cecap {
        font-size: 22pt;
        font-weight: 900;
        color: #1a3a6b;
        letter-spacing: 2px;
        border-bottom: 2px solid #1a3a6b;
        padding-bottom: 6px;
        margin-bottom: 8px;
    }
    .marco-qr .subtitulo {
        font-size: 11pt;
        color: #444;
        margin-bottom: 6px;
    }
    .marco-qr .instruccion {
        font-size: 16pt;
        font-weight: 700;
        color: #d35400;
        margin-bottom: 12px;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    .marco-qr img.qr-img {
        width: 160px; height: 160px;
        object-fit: contain;
        margin: 10px 0;
        border: 2px solid #eee;
        border-radius: 8px;
    }
    .print-mitad .copia .marco-qr img.qr-img {
        width: 120px; height: 120px;
    }
    .marco-qr .pie {
        font-size: 8pt;
        color: #666;
        margin-top: 10px;
        font-style: italic;
    }
    .marco-qr .espacio-url {
        font-size: 8pt;
        color: #888;
        word-break: break-all;
        margin-top: 4px;
    }
}
</style>
</head>
<body>

<!-- Topbar -->
<div class="topbar">
    <a href="panel.php" class="btn btn-sm btn-secondary">← Panel</a>
    <h4>📱 Código QR de Asistencia</h4>
    <div></div>
</div>

<div class="container mt-4" style="max-width:780px">

    <?php if ($msg): ?>
    <div class="alert alert-<?= $msgTipo ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="glass">

        <!-- Vista previa -->
        <div class="text-center mb-4">
            <?php if ($qrExiste): ?>
            <div class="qr-preview mx-auto">
                <div style="font-weight:800;font-size:1rem;color:#1a3a6b;letter-spacing:2px">
                    CeCap — Esperanza
                </div>
                <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR Asistencia" id="qrImg">
                <div style="font-size:.75rem;color:#555;text-align:center">
                    Registre su Asistencia
                </div>
            </div>
            <?php else: ?>
            <div class="p-5 text-center" style="background:rgba(255,255,255,.05);border-radius:16px;border:2px dashed rgba(255,255,255,.2)">
                <i class="bi bi-qr-code" style="font-size:4rem;opacity:.4"></i>
                <p class="mt-3 text-muted">No hay imagen QR cargada aún.</p>
                <?php if (!$esAdmin): ?>
                <p class="small text-muted">Solicitá al administrador que suba el código QR.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Botones de impresión -->
        <?php if ($qrExiste): ?>
        <div class="d-flex gap-3 justify-content-center flex-wrap mb-4">
            <button class="btn btn-primary" onclick="imprimirA4()">
                <i class="bi bi-printer-fill me-1"></i> Imprimir A4 completo
            </button>
            <button class="btn btn-outline-info" onclick="imprimirMitad()">
                <i class="bi bi-layout-split me-1"></i> Imprimir mitad A4 (2 copias)
            </button>
        </div>
        <?php endif; ?>

        <!-- Subir QR — solo admin -->
        <?php if ($esAdmin): ?>
        <hr style="border-color:rgba(255,255,255,.15)">
        <h6 class="text-warning mb-3"><i class="bi bi-cloud-upload me-1"></i> Actualizar imagen QR</h6>
        <form method="POST" enctype="multipart/form-data">
            <div class="row g-3 align-items-end">
                <div class="col-md-7">
                    <label>Seleccionar imagen (PNG, JPG, WEBP)</label>
                    <input type="file" name="qr_img" class="form-control"
                           accept="image/png,image/jpeg,image/gif,image/webp" required>
                </div>
                <div class="col-md-5">
                    <button type="submit" class="btn btn-warning w-100">
                        <i class="bi bi-upload me-1"></i> Subir QR
                    </button>
                </div>
            </div>
            <div class="mt-2">
                <small class="text-muted">
                    La imagen se guardará como <code>img/qr_asistencia.png</code> y será visible para todos los usuarios.
                </small>
            </div>
        </form>
        <?php endif; ?>

    </div>
</div>

<!-- ══════════════════════════════
     MARCOS DE IMPRESIÓN (ocultos)
══════════════════════════════ -->

<!-- Modo A4 completo -->
<div class="marco-impresion" id="printA4">
    <div class="print-a4">
        <div class="marco-qr" style="max-width:160mm">
            <div class="logo-cecap">CeCap — Esperanza</div>
            <div class="instruccion">Registre su Asistencia</div>
            <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR" class="qr-img">
            <div class="subtitulo">
                Escaneá el código con la cámara de tu celular
            </div>
            <div class="pie">
                Centro de Capacitación para la obtención de la Licencia Nacional de Conducir
            </div>
        </div>
    </div>
</div>

<!-- Modo mitad A4 — 2 copias -->
<div class="marco-impresion" id="printMitad">
    <div class="print-mitad">
        <div class="copia">
            <div class="marco-qr">
                <div class="logo-cecap">CeCap — Esperanza</div>
                <div class="instruccion">Registre su Asistencia</div>
                <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR" class="qr-img">
                <div class="pie">
                    Centro de Capacitación para la obtención de la Licencia Nacional de Conducir
                </div>
            </div>
        </div>
        <div class="separador"></div>
        <div class="copia">
            <div class="marco-qr">
                <div class="logo-cecap">CeCap — Esperanza</div>
                <div class="instruccion">Registre su Asistencia</div>
                <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR" class="qr-img">
                <div class="pie">
                    Centro de Capacitación para la obtención de la Licencia Nacional de Conducir
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let modoImpresion = null;

function imprimirA4() {
    modoImpresion = 'a4';
    document.getElementById('printA4').classList.add('activo');
    document.getElementById('printMitad').classList.remove('activo');
    setTimeout(() => { window.print(); limpiarModo(); }, 100);
}

function imprimirMitad() {
    modoImpresion = 'mitad';
    document.getElementById('printMitad').classList.add('activo');
    document.getElementById('printA4').classList.remove('activo');
    setTimeout(() => { window.print(); limpiarModo(); }, 100);
}

function limpiarModo() {
    document.getElementById('printA4').classList.remove('activo');
    document.getElementById('printMitad').classList.remove('activo');
    modoImpresion = null;
}
</script>

</body>
</html>