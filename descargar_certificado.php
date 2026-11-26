<?php
require_once __DIR__ . "/conexion.php";

function pagDescarga(string $titulo, string $detalle, bool $error = true): void
{
    $color = $error ? '#dc3545' : '#5D5E70';
    header("Content-Type: text/html; charset=UTF-8");
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($titulo) . ' — CeCap</title>'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">'
        . '<link rel="stylesheet" href="css/variables.css">'
        . '<style>body{background:linear-gradient(135deg,#504254,#3C313F);background-attachment:fixed;'
        . 'min-height:100vh;display:flex;align-items:center;justify-content:center;color:#F0EDF2;font-family:"Segoe UI",sans-serif;padding:16px;}'
        . '.caja{background:rgba(255,255,255,.10);backdrop-filter:blur(14px);border:1px solid rgba(255,255,255,.18);'
        . 'border-radius:22px;box-shadow:0 20px 50px rgba(0,0,0,.5);padding:34px;max-width:520px;text-align:center;}</style>'
        . '</head><body>'
        . '<div class="caja"><h3 style="color:' . $color . ';font-weight:800;">' . htmlspecialchars($titulo) . '</h3>'
        . '<p class="mt-2">' . nl2br(htmlspecialchars($detalle)) . '</p>'
        . '<img src="img/LogoCapVial26.png" alt="CeCap" style="max-width:160px;margin-top:14px;opacity:.9">'
        . '</div></body></html>';
    exit;
}

$token = trim($_GET['t'] ?? '');
if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
    pagDescarga('Enlace inválido', 'Este enlace no es válido.');
}

$st = $conn->prepare("SELECT id, archivo, usado, fecha_expira FROM cert_descargas WHERE token = ? LIMIT 1");
$st->bind_param("s", $token);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();

if (!$row) {
    pagDescarga('Enlace inválido', 'Este enlace no existe o ya no está disponible.');
}

if ((int)$row['usado'] === 1) {
    pagDescarga('Enlace ya utilizado', "Este certificado ya fue descargado una vez.\n\n"
        . "Si necesitás otra copia, comunicate con la Municipalidad de Esperanza.");
}

if (strtotime($row['fecha_expira']) < time()) {
    pagDescarga('Enlace vencido', "El enlace para descargar este certificado venció (validez de 7 días).\n\n"
        . "Solicitá uno nuevo en la Municipalidad de Esperanza.");
}

$ruta = __DIR__ . '/cert_pdfs/' . $row['archivo'];
if (!is_file($ruta)) {
    pagDescarga('Archivo no disponible', 'El certificado ya no está disponible para descargar.');
}

/* Marcar como usado ANTES de servir para garantizar un solo uso */
$st = $conn->prepare("UPDATE cert_descargas SET usado = 1, fecha_descarga = NOW() WHERE id = ?");
$st->bind_param("i", $row['id']);
$st->execute();
$st->close();

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="certificado.pdf"');
header('Content-Length: ' . filesize($ruta));
header('Cache-Control: no-store, no-cache, must-revalidate');

readfile($ruta);
@unlink($ruta);
exit;
