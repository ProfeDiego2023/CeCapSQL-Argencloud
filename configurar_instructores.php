<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/auditar.php";
require_once __DIR__ . "/instructor_fn.php";

proteger();
if (!permiso("config_cert")) {
    die("Acceso denegado");
}

$firmasDir = __DIR__ . "/firmas/";
if (!is_dir($firmasDir)) {
    mkdir($firmasDir, 0775, true);
}

inst_asegurarTabla($conn);

$msg     = '';
$msgTipo = '';

function fir_guardarImagen(string $firmasDir, string $contenidoPng): string
{
    $archivo = 'firma_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.png';
    $ruta    = $firmasDir . $archivo;
    $ok      = @file_put_contents($ruta, $contenidoPng);
    if ($ok === false) {
        return '';
    }
    @chmod($ruta, 0644);
    return $archivo;
}

/* ── Toggle habilitado ── */
if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $conn->query("UPDATE firmas_instructores SET habilitado = IF(habilitado = 1, 0, 1) WHERE id = " . $id);
    auditar($conn, "INSTRUCTOR", "FIRMAS", "Toggle habilitado (id $id)");
    header("Location: configurar_instructores.php");
    exit;
}

/* ── Eliminar ── */
if (isset($_GET['del'])) {
    $id = (int)$_GET['del'];
    $st = $conn->prepare("SELECT archivo FROM firmas_instructores WHERE id = ?");
    $st->bind_param("i", $id);
    $st->execute();
    $archivo = $st->get_result()->fetch_assoc()['archivo'] ?? '';
    $st->close();

    $st = $conn->prepare("DELETE FROM firmas_instructores WHERE id = ?");
    $st->bind_param("i", $id);
    $st->execute();
    $st->close();

    if ($archivo !== '' && file_exists($firmasDir . $archivo)) {
        unlink($firmasDir . $archivo);
    }

    auditar($conn, "INSTRUCTOR", "FIRMAS", "Instructor eliminado (id $id)");
    $msg     = "Instructor eliminado correctamente.";
    $msgTipo = "success";
}

/* ── Guardar / actualizar ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id       = (int)($_POST['id'] ?? 0);
    $apellido = trim($_POST['apellido'] ?? '');
    $nombre   = trim($_POST['nombre'] ?? '');
    $usuarioId  = (int)($_POST['usuario_id'] ?? 0);
    $habilitado = isset($_POST['habilitado']) ? 1 : 0;
    $nuevaFirma = null;

    $firmaDataUrl = trim($_POST['firma_dataurl'] ?? '');
    $firmaFile    = $_FILES['firma_img'] ?? null;

    if ($firmaDataUrl !== '') {
        if (preg_match('#^data:image/png;base64,#i', $firmaDataUrl)) {
            $png = base64_decode(substr($firmaDataUrl, strpos($firmaDataUrl, ',') + 1), true);
            if ($png !== false && strlen($png) > 100) {
                $nuevaFirma = $png;
            } else {
                $msg = "La firma dibujada no se pudo procesar. Intentá dibujarla nuevamente.";
                $msgTipo = "danger";
            }
        } else {
            $msg = "El formato de la firma dibujada no es válido.";
            $msgTipo = "danger";
        }
    } elseif (is_array($firmaFile) && ($firmaFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $fileErr = (int)($firmaFile['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($fileErr !== UPLOAD_ERR_OK) {
            $errMap = [
                UPLOAD_ERR_INI_SIZE   => "El archivo excede el tamaño máximo permitido por el servidor.",
                UPLOAD_ERR_FORM_SIZE  => "El archivo excede el tamaño máximo permitido por el formulario.",
                UPLOAD_ERR_PARTIAL    => "La carga del archivo fue incompleta.",
                UPLOAD_ERR_NO_TMP_DIR => "Falta la carpeta temporal en el servidor.",
                UPLOAD_ERR_CANT_WRITE => "No se pudo escribir el archivo en el disco del servidor.",
                UPLOAD_ERR_EXTENSION  => "Una extensión de PHP detuvo la carga del archivo.",
            ];
            $msg = $errMap[$fileErr] ?? ("Error al cargar el archivo de firma (código $fileErr).");
            $msgTipo = "danger";
        } else {
            $tmp  = $firmaFile['tmp_name'];
            $info = @getimagesize($tmp);
            if ($info === false) {
                $msg = "El archivo no es una imagen válida.";
                $msgTipo = "danger";
            } else {
                [$anchoOrig, $altoOrig, $tipoImg] = $info;

                if ($anchoOrig * $altoOrig > 30_000_000) {
                    $msg = "La imagen es demasiado grande. Usá una foto de menor resolución.";
                    $msgTipo = "danger";
                } elseif ($tipoImg === IMAGETYPE_PNG) {
                    $pngSubido = @file_get_contents($tmp);
                    $firmaPng  = substr((string)$pngSubido, 0, 8);
                    $pngValido = ($pngSubido !== false && strlen($pngSubido) > 100 && $firmaPng === "\x89PNG\r\n\x1a\n");

                    if (!$pngValido) {
                        $msg = "El archivo PNG no es válido o está dañado.";
                        $msgTipo = "danger";
                    } else {
                        // Si hay GD disponible, se normaliza/redimensiona; si no, se guarda el PNG original.
                        $gdDisponible = function_exists('imagecreatetruecolor') && function_exists('imagepng') && function_exists('imagecreatefrompng');
                        if ($gdDisponible) {
                            $maxLado      = 1000;
                            $escala       = min(1, $maxLado / max($anchoOrig, $altoOrig));
                            $anchoDestino = max(1, (int)round($anchoOrig * $escala));
                            $altoDestino  = max(1, (int)round($altoOrig * $escala));

                            $src = @imagecreatefrompng($tmp);
                            if ($src === false) {
                                $msg = "No se pudo procesar el archivo PNG. Probá con otro archivo.";
                                $msgTipo = "danger";
                            } else {
                                $destino = imagecreatetruecolor($anchoDestino, $altoDestino);
                                imagealphablending($destino, false);
                                imagesavealpha($destino, true);
                                imagecopyresampled(
                                    $destino, $src,
                                    0, 0, 0, 0,
                                    $anchoDestino, $altoDestino, $anchoOrig, $altoOrig
                                );

                                ob_start();
                                imagepng($destino);
                                $pngProcesado = ob_get_clean();

                                unset($src, $destino);

                                if ($pngProcesado === false || strlen($pngProcesado) <= 100) {
                                    $msg = "No se pudo convertir la imagen de la firma a PNG.";
                                    $msgTipo = "danger";
                                } else {
                                    $nuevaFirma = $pngProcesado;
                                }
                            }
                        } else {
                            $nuevaFirma = $pngSubido;
                        }
                    }
                } elseif ($tipoImg === IMAGETYPE_JPEG) {
                    $gdDisponibleJpg = function_exists('imagecreatetruecolor') && function_exists('imagepng') && function_exists('imagecreatefromjpeg');
                    if (!$gdDisponibleJpg) {
                        $msg = "Para archivos JPG el servidor necesita GD habilitado. Subí la firma en formato PNG o dibujala en el recuadro.";
                        $msgTipo = "danger";
                    } else {
                        $maxLado      = 1000;
                        $escala       = min(1, $maxLado / max($anchoOrig, $altoOrig));
                        $anchoDestino = max(1, (int)round($anchoOrig * $escala));
                        $altoDestino  = max(1, (int)round($altoOrig * $escala));

                        $src = @imagecreatefromjpeg($tmp);
                        if ($src === false) {
                            $msg = "No se pudo procesar la imagen JPG. Probá con otro archivo.";
                            $msgTipo = "danger";
                        } else {
                            $destino = imagecreatetruecolor($anchoDestino, $altoDestino);
                            imagealphablending($destino, false);
                            imagesavealpha($destino, true);
                            $blanco = imagecolorallocatealpha($destino, 255, 255, 255, 127);
                            imagefill($destino, 0, 0, $blanco);
                            imagecopyresampled(
                                $destino, $src,
                                0, 0, 0, 0,
                                $anchoDestino, $altoDestino, $anchoOrig, $altoOrig
                            );

                            ob_start();
                            imagepng($destino);
                            $pngProcesado = ob_get_clean();

                            unset($src, $destino);

                            if ($pngProcesado === false || strlen($pngProcesado) <= 100) {
                                $msg = "No se pudo convertir la imagen de la firma a PNG.";
                                $msgTipo = "danger";
                            } else {
                                $nuevaFirma = $pngProcesado;
                            }
                        }
                    }
                } else {
                    $msg = "Formato de imagen no soportado. Usá PNG o JPG.";
                    $msgTipo = "danger";
                }
            }
        }
    }

    if ($apellido === '' || $nombre === '') {
        $msg = "Apellido y nombre son obligatorios.";
        $msgTipo = "danger";
    } elseif ($msgTipo === 'danger') {
        // Error previo (carga/procesado de firma): no continuar con guardado exitoso.
    } elseif ($id > 0) {
        if ($nuevaFirma !== null) {
            $archivoNuevo = fir_guardarImagen($firmasDir, $nuevaFirma);
            if ($archivoNuevo === '') {
                $msg = "No se pudo guardar la imagen de la firma. Verificá que la carpeta 'firmas/' exista y tenga permisos de escritura.";
                $msgTipo = "danger";
            } else {
                $st = $conn->prepare("
                    SELECT archivo FROM firmas_instructores WHERE id = ?
                ");
                $st->bind_param("i", $id);
                $st->execute();
                $anterior = $st->get_result()->fetch_assoc()['archivo'] ?? '';
                $st->close();

                $st = $conn->prepare("
                    UPDATE firmas_instructores
                    SET apellido = ?, nombre = ?, archivo = ?, usuario_id = ?, habilitado = ?
                    WHERE id = ?
                ");
                $st->bind_param("sssiii", $apellido, $nombre, $archivoNuevo, $usuarioId, $habilitado, $id);
                $st->execute();
                $st->close();

                if ($anterior !== '' && $anterior !== $archivoNuevo && file_exists($firmasDir . $anterior)) {
                    @unlink($firmasDir . $anterior);
                }

                auditar($conn, "INSTRUCTOR", "FIRMAS", "Instructor actualizado (id $id)");
                $msg = "Instructor actualizado correctamente.";
                $msgTipo = "success";
            }
        } else {
            $st = $conn->prepare("
                UPDATE firmas_instructores
                SET apellido = ?, nombre = ?, usuario_id = ?, habilitado = ?
                WHERE id = ?
            ");
            $st->bind_param("ssiii", $apellido, $nombre, $usuarioId, $habilitado, $id);
            $st->execute();
            $st->close();

            auditar($conn, "INSTRUCTOR", "FIRMAS", "Instructor actualizado (id $id)");
            $msg = "Instructor actualizado correctamente.";
            $msgTipo = "success";
        }
    } else {
        if ($nuevaFirma === null) {
            $msg = "Debe cargar una firma (dibujada o imagen).";
            $msgTipo = "danger";
        } else {
            $archivoNuevo = fir_guardarImagen($firmasDir, $nuevaFirma);
            if ($archivoNuevo === '') {
                $msg = "No se pudo guardar la imagen de la firma. Verificá que la carpeta 'firmas/' exista y tenga permisos de escritura.";
                $msgTipo = "danger";
            } else {
                $st = $conn->prepare("
                    INSERT INTO firmas_instructores (apellido, nombre, archivo, usuario_id, habilitado)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $st->bind_param("sssii", $apellido, $nombre, $archivoNuevo, $usuarioId, $habilitado);
                $st->execute();
                $st->close();

                auditar($conn, "INSTRUCTOR", "FIRMAS", "Instructor agregado: $apellido $nombre");
                $msg = "Instructor guardado correctamente.";
                $msgTipo = "success";
            }
        }
    }
}

$instructores = [];
$res = $conn->query("SELECT * FROM firmas_instructores ORDER BY apellido ASC, nombre ASC");
while ($r = $res->fetch_assoc()) {
    $r['existe'] = $r['archivo'] !== '' && file_exists($firmasDir . $r['archivo']);
    $instructores[] = $r;
}

$mapUsuarios = [];
$resU = $conn->query("SELECT id, nombre, usuario FROM usuarios ORDER BY nombre ASC");
if ($resU) {
    while ($u = $resU->fetch_assoc()) {
        $mapUsuarios[(int)$u['id']] = $u;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Configurar Instructores — CeCap</title>
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
    max-width: 860px;
    margin: 0 auto 24px;
}
.form-control, .form-select {
    background: rgba(0,0,0,.28) !important;
    border: 1px solid rgba(72, 187, 120, 0.45) !important;
    color: #F0EDF2 !important;
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
.form-select option {
    background: #3C313F;
    color: #F0EDF2;
}
.form-label { color: #C7E4CF; font-weight: 600; font-size: .88rem; }
.btn-guardar {
    background: linear-gradient(135deg, #DD4841, #5D5E70);
    border: none; border-radius: 14px; color: #fff;
    font-weight: 700; padding: 10px 20px;
}
.btn-volver {
    border: 2px solid rgba(199,228,207,.35);
    color: #F0EDF2;
    border-radius: 14px;
    font-weight: 700;
    padding: 8px 16px;
    text-decoration: none;
    background: rgba(255,255,255,.08);
    transition: background .15s ease, border-color .15s ease, color .15s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.btn-volver:hover {
    background: rgba(255,255,255,.16);
    border-color: rgba(199,228,207,.55);
    color: #fff;
}
.btn-danger-outline {
    border: 2px solid rgba(255,100,100,.55);
    color: #ffb3b3;
    border-radius: 14px;
    font-weight: 700;
    padding: 6px 14px;
    text-decoration: none;
    background: rgba(255,80,80,.10);
    transition: background .15s ease, border-color .15s ease, color .15s ease;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.btn-danger-outline:hover {
    background: rgba(255,80,80,.20);
    border-color: rgba(255,120,120,.75);
    color: #fff;
}
#badgeEditandoWrap {
    min-height: 0;
}
#badgeEditando {
    background: rgba(255, 209, 102, 0.20);
    color: #FFD166;
    border: 1px solid rgba(255, 209, 102, 0.45);
    padding: 8px 12px;
    font-weight: 700;
    white-space: normal;
    max-width: 100%;
    line-height: 1.3;
}
.table .btn-volver,
.table .btn-danger-outline {
    padding: 6px 10px;
    border-radius: 10px;
    font-size: .95rem;
    line-height: 1;
    min-width: 38px;
    justify-content: center;
}
.table .btn-editar {
    color: #7dd3fc;
    background: rgba(56, 189, 248, 0.14);
    border-color: rgba(56, 189, 248, 0.45);
}
.table .btn-editar:hover {
    color: #fff;
    background: rgba(56, 189, 248, 0.26);
    border-color: rgba(56, 189, 248, 0.70);
}
.table td img[alt="Firma"] {
    max-height: 44px;
    max-width: 160px;
    width: auto;
    height: auto;
    object-fit: contain;
    background: #fff;
    border-radius: 6px;
    padding: 2px;
}
.firma-canvas {
    border: 2px dashed rgba(255,255,255,.25);
    border-radius: 12px;
    background: #fff;
    width: 100%;
    height: 140px;
    touch-action: none;
    cursor: crosshair;
}
.hint { font-size: .78rem; color: rgba(199,228,207,.65); }
/* firmas en tabla: ver regla .table td img[alt="Firma"] */
</style>
</head>
<body>
<div class="glass">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="mb-0 fw-bold"><i class="bi bi-person-vcard-fill"></i> Configurar Instructores</h4>
        <div class="d-flex gap-2">
            <a href="enviar_certificados_email.php" class="btn-volver"><i class="bi bi-envelope-paper"></i> Enviar certificados</a>
            <a href="panel.php" class="btn-volver"><i class="bi bi-arrow-left"></i> Panel</a>
        </div>
    </div>

    <?php if ($msg !== ''): ?>
    <div class="alert alert-<?= $msgTipo ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="frmInstructor" class="mb-4">
        <input type="hidden" name="id" id="frmId" value="">
        <input type="hidden" name="firma_dataurl" id="firma_dataurl" value="">

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Apellido</label>
                <input type="text" name="apellido" id="frmApellido" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Nombre</label>
                <input type="text" name="nombre" id="frmNombre" class="form-control" required>
            </div>
            <div class="col-12 d-none" id="badgeEditandoWrap">
                <span id="badgeEditando" class="badge rounded-pill">Editando</span>
            </div>

            <div class="col-12">
                <label class="form-label">Cuenta de usuario dueña de la firma</label>
                <select name="usuario_id" id="frmUsuario" class="form-select">
                    <option value="0">— Sin vincular (selección manual siempre) —</option>
                    <?php foreach ($mapUsuarios as $uid => $u): ?>
                    <option value="<?= (int)$uid ?>">
                        <?= htmlspecialchars($u['nombre']) ?> (@<?= htmlspecialchars($u['usuario']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="hint mt-1">
                    Si se vincula a una cuenta, esa persona inicia sesión y sus certificados usan su firma automáticamente.
                </div>
            </div>

            <div class="col-12">
                <div class="form-check form-switch mt-2">
                    <input class="form-check-input" type="checkbox" id="frmHabilitado" name="habilitado" value="1" checked>
                    <label class="form-check-label form-label mb-0" for="frmHabilitado">
                        Firma digital habilitada
                    </label>
                </div>
                <div class="hint mt-1">
                    Si está deshabilitado, el certificado se genera sin firma ni nombre del instructor.
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label">Dibujar firma (mouse, lápiz o tableta)</label>
                <canvas id="firmaCanvas" class="firma-canvas" width="420" height="140"></canvas>
                <button type="button" id="btnLimpiar" class="btn-volver mt-2">
                    <i class="bi bi-eraser"></i> Limpiar
                </button>
            </div>
            <div class="col-md-6">
                <label class="form-label">O cargar imagen (PNG o JPG)</label>
                <input type="file" name="firma_img" id="firma_img" class="form-control" accept="image/png,image/jpeg">
                <div id="previewFile" class="mt-2 d-none">
                    <img id="previewImg" src="" alt="Vista previa" style="max-height:90px;background:#fff;border-radius:8px;padding:2px;">
                </div>
                <div class="hint mt-2">La imagen se convierte automáticamente a PNG.</div>
            </div>
        </div>

        <div class="d-flex gap-2 mt-4 flex-wrap">
            <button type="submit" class="btn-guardar"><i class="bi bi-save"></i> Guardar instructor</button>
            <button type="button" id="btnCancelarEdicion" class="btn-volver d-none">Cancelar edición</button>
        </div>
    </form>

    <h5 class="fw-bold mb-3"><i class="bi bi-person-lines-fill"></i> Instructores cargados (<?= count($instructores) ?>)</h5>

    <?php if (empty($instructores)): ?>
    <p class="text-muted">Aún no hay instructores cargados.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle text-white">
            <thead>
                <tr class="text-info">
                    <th>Apellido</th>
                    <th>Nombre</th>
                    <th>Firma</th>
                    <th>Cuenta vinculada</th>
                    <th>Estado</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($instructores as $ins): ?>
                <tr>
                    <td><?= htmlspecialchars($ins['apellido']) ?></td>
                    <td><?= htmlspecialchars($ins['nombre']) ?></td>
                    <td>
                        <?php if ($ins['existe']): ?>
                        <img src="firmas/<?= urlencode($ins['archivo']) ?>" alt="Firma">
                        <?php else: ?>
                        <span class="text-muted">Sin imagen</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($ins['usuario_id']) && isset($mapUsuarios[(int)$ins['usuario_id']])): ?>
                            <?= htmlspecialchars($mapUsuarios[(int)$ins['usuario_id']]['nombre']) ?>
                        <?php else: ?>
                            <span class="text-muted">Sin vincular</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($ins['habilitado'])): ?>
                            <a href="?toggle=<?= $ins['id'] ?>" class="badge rounded-pill bg-success text-decoration-none" title="Click para deshabilitar">
                                <i class="bi bi-check-circle-fill"></i> Habilitado
                            </a>
                        <?php else: ?>
                            <a href="?toggle=<?= $ins['id'] ?>" class="badge rounded-pill bg-danger text-decoration-none" title="Click para habilitar">
                                <i class="bi bi-x-circle-fill"></i> Deshabilitado
                            </a>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <button type="button" class="btn-volver btn-editar"
                                data-id="<?= $ins['id'] ?>"
                                data-apellido="<?= htmlspecialchars($ins['apellido']) ?>"
                                data-nombre="<?= htmlspecialchars($ins['nombre']) ?>"
                                data-usuario="<?= (int)($ins['usuario_id'] ?? 0) ?>"
                                data-habilitado="<?= (int)($ins['habilitado'] ?? 1) ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <a class="btn-danger-outline" href="configurar_instructores.php?del=<?= $ins['id'] ?>"
                           onclick="return confirm('¿Eliminar a <?= addslashes(htmlspecialchars($ins['apellido'] . ' ' . $ins['nombre'])) ?>?');">
                            <i class="bi bi-trash"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
const canvas = document.getElementById('firmaCanvas');
const ctx = canvas.getContext('2d');
const inputDataUrl = document.getElementById('firma_dataurl');
const inputApellido = document.getElementById('frmApellido');
const inputNombre = document.getElementById('frmNombre');
const inputId = document.getElementById('frmId');
const inputUsuario = document.getElementById('frmUsuario');
const inputHabilitado = document.getElementById('frmHabilitado');
const inputFile = document.getElementById('firma_img');
const badgeEditando = document.getElementById('badgeEditando');
const badgeEditandoWrap = document.getElementById('badgeEditandoWrap');
const btnCancelar = document.getElementById('btnCancelarEdicion');
const previewFile = document.getElementById('previewFile');
const previewImg = document.getElementById('previewImg');

let dibujando = false;
let firmado = false;
ctx.lineWidth = 2.5;
ctx.lineCap = 'round';
ctx.lineJoin = 'round';
ctx.strokeStyle = '#000';

function posEvento(e) {
    const r = canvas.getBoundingClientRect();
    return {
        x: (e.clientX - r.left) * (canvas.width / r.width),
        y: (e.clientY - r.top) * (canvas.height / r.height)
    };
}

canvas.addEventListener('mousedown', e => {
    dibujando = true;
    firmado = true;
    const p = posEvento(e);
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
});
canvas.addEventListener('mousemove', e => {
    if (!dibujando) return;
    const p = posEvento(e);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
});
window.addEventListener('mouseup', () => { dibujando = false; });

canvas.addEventListener('touchstart', e => {
    e.preventDefault();
    dibujando = true;
    firmado = true;
    const t = e.touches[0];
    const r = canvas.getBoundingClientRect();
    ctx.beginPath();
    ctx.moveTo((t.clientX - r.left) * (canvas.width / r.width),
               (t.clientY - r.top) * (canvas.height / r.height));
}, { passive: false });
canvas.addEventListener('touchmove', e => {
    e.preventDefault();
    if (!dibujando) return;
    const t = e.touches[0];
    const r = canvas.getBoundingClientRect();
    ctx.lineTo((t.clientX - r.left) * (canvas.width / r.width),
               (t.clientY - r.top) * (canvas.height / r.height));
    ctx.stroke();
}, { passive: false });
canvas.addEventListener('touchend', () => { dibujando = false; });

document.getElementById('btnLimpiar').addEventListener('click', () => {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    firmado = false;
    inputDataUrl.value = '';
});

inputFile.addEventListener('change', () => {
    if (inputFile.files && inputFile.files[0]) {
        const url = URL.createObjectURL(inputFile.files[0]);
        previewImg.src = url;
        previewFile.classList.remove('d-none');
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        firmado = false;
        inputDataUrl.value = '';
    } else {
        previewFile.classList.add('d-none');
    }
});

document.getElementById('frmInstructor').addEventListener('submit', () => {
    if (firmado) {
        inputDataUrl.value = canvas.toDataURL('image/png');
    }
});

document.querySelectorAll('.btn-editar').forEach(btn => {
    btn.addEventListener('click', () => {
        inputId.value = btn.dataset.id;
        inputApellido.value = btn.dataset.apellido;
        inputNombre.value = btn.dataset.nombre;
        inputUsuario.value = btn.dataset.usuario || '0';
        inputHabilitado.checked = (btn.dataset.habilitado === '1');
        badgeEditando.textContent = 'Editando: ' + btn.dataset.apellido + ' ' + btn.dataset.nombre;
        badgeEditandoWrap.classList.remove('d-none');
        btnCancelar.classList.remove('d-none');
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        firmado = false;
        inputDataUrl.value = '';
        inputFile.value = '';
        previewFile.classList.add('d-none');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
});

btnCancelar.addEventListener('click', () => {
    inputId.value = '';
    inputApellido.value = '';
    inputNombre.value = '';
    inputUsuario.value = '0';
    inputHabilitado.checked = true;
    badgeEditando.textContent = 'Editando';
    badgeEditandoWrap.classList.add('d-none');
    btnCancelar.classList.add('d-none');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    firmado = false;
    inputDataUrl.value = '';
    inputFile.value = '';
    previewFile.classList.add('d-none');
});
</script>
</body>
</html>
