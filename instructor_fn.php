<?php

/**
 * Helpers de instructores con firma digital.
 * Los instructores se cargan en la tabla firmas_instructores y sus
 * firmas (PNG) en la carpeta firmas/.
 */

function inst_cargarInstructores(mysqli $conn): array
{
    inst_asegurarTabla($conn);

    $firmasDir = __DIR__ . '/firmas/';
    $lista = [];
    $res = $conn->query(
        "SELECT id, apellido, nombre, archivo, usuario_id FROM firmas_instructores
         WHERE habilitado = 1
         ORDER BY apellido ASC, nombre ASC"
    );
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            if ($r['archivo'] !== '' && is_file($firmasDir . $r['archivo'])) {
                $lista[] = $r;
            }
        }
    }
    return $lista;
}

/**
 * Asegura que la tabla firmas_instructores exista y tenga la columna
 * usuario_id (cuenta de usuario dueña de la firma).
 */
function inst_asegurarTabla(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS firmas_instructores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            apellido VARCHAR(100) NOT NULL DEFAULT '',
            nombre VARCHAR(100) NOT NULL DEFAULT '',
            archivo VARCHAR(255) NOT NULL DEFAULT '',
            usuario_id INT NOT NULL DEFAULT 0,
            habilitado TINYINT(1) NOT NULL DEFAULT 1,
            fecha_alta DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $cols = [];
    $res = $conn->query("SHOW COLUMNS FROM firmas_instructores");
    if ($res) {
        while ($c = $res->fetch_assoc()) {
            $cols[] = $c['Field'];
        }
    }
    if (!in_array('usuario_id', $cols, true)) {
        $conn->query("ALTER TABLE firmas_instructores ADD COLUMN usuario_id INT NOT NULL DEFAULT 0 AFTER archivo");
    }
    if (!in_array('habilitado', $cols, true)) {
        $conn->query("ALTER TABLE firmas_instructores ADD COLUMN habilitado TINYINT(1) NOT NULL DEFAULT 1 AFTER usuario_id");
    }
}

/**
 * Devuelve el instructor (con firma) vinculado al usuario logueado,
 * o null si no tiene firma propia registrada.
 */
function inst_tieneFirmaPropia(mysqli $conn, int $usuarioId): ?array
{
    if ($usuarioId <= 0) {
        return null;
    }

    $st = $conn->prepare(
        "SELECT id, apellido, nombre, archivo, usuario_id FROM firmas_instructores
         WHERE usuario_id = ? AND habilitado = 1 LIMIT 1"
    );
    $st->bind_param("i", $usuarioId);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$r) {
        return null;
    }

    $firmasDir = __DIR__ . '/firmas/';
    if ($r['archivo'] === '' || !is_file($firmasDir . $r['archivo'])) {
        return null;
    }

    return $r;
}

function inst_obtener(mysqli $conn, int $id): ?array
{
    $st = $conn->prepare(
        "SELECT id, apellido, nombre, archivo, usuario_id, habilitado FROM firmas_instructores WHERE id = ? LIMIT 1"
    );
    $st->bind_param("i", $id);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$r) {
        return null;
    }

    $firmasDir = __DIR__ . '/firmas/';
    if ($r['archivo'] === '' || !is_file($firmasDir . $r['archivo'])) {
        $r['archivo'] = '';
    }

    return $r;
}

function inst_nombreCompleto(array $ins): string
{
    return strtoupper(trim(($ins['apellido'] ?? '') . ' ' . ($ins['nombre'] ?? '')));
}

/**
 * Resuelve el instructor a usar, en este orden:
 *  1. $instructorId explícito (selección manual, ej. admin).
 *  2. Firma vinculada al usuario logueado ($usuarioId).
 *  3. Fallback: nombre de sesión sin firma.
 * Devuelve [nombre, archivoFirma].
 */
function inst_resolver(mysqli $conn, int $instructorId, string $nombreSesion, int $usuarioId = 0): array
{
    if ($instructorId > 0) {
        $ins = inst_obtener($conn, $instructorId);
        if ($ins) {
            if (empty($ins['habilitado']) || $ins['archivo'] === '') {
                return ['', ''];
            }
            return [inst_nombreCompleto($ins), $ins['archivo']];
        }
    }

    if ($usuarioId > 0) {
        $auto = inst_tieneFirmaPropia($conn, $usuarioId);
        if ($auto) {
            return [inst_nombreCompleto($auto), $auto['archivo']];
        }
    }

    return [strtoupper(trim($nombreSesion)), ''];
}

/**
 * Devuelve un directorio temporal real y dentro de open_basedir.
 * sys_get_temp_dir() puede devolver una ruta (p.ej. /home/tmp en InfinityFree)
 * que el host no tiene incluida en open_basedir, lo que provoca warnings de
 * is_file()/file_exists(). Se prueban candidatos hasta encontrar uno permitido.
 */
function inst_tmpDir(): string
{
    $basedir = ini_get('open_basedir');
    $permitido = static function (string $ruta) use ($basedir): bool {
        if ($basedir === '' || $basedir === false) {
            return true;
        }
        foreach (explode(PATH_SEPARATOR, $basedir) as $base) {
            if ($base === '') {
                continue;
            }
            $base = rtrim($base, '/\\');
            if (strlen($ruta) >= strlen($base) && strncmp($ruta, $base, strlen($base)) === 0) {
                if (strlen($ruta) === strlen($base) || $ruta[strlen($base)] === '/' || $ruta[strlen($base)] === '\\') {
                    return true;
                }
            }
        }
        return false;
    };

    foreach ([sys_get_temp_dir(), '/tmp', __DIR__ . '/tmp'] as $cand) {
        $cand = rtrim($cand, '/\\');
        if ($permitido($cand) && is_dir($cand) && is_writable($cand)) {
            return $cand . DIRECTORY_SEPARATOR;
        }
    }

    $local = rtrim(__DIR__ . '/tmp', '/\\');
    @mkdir($local, 0775, true);
    return is_dir($local) ? $local . DIRECTORY_SEPARATOR : __DIR__ . DIRECTORY_SEPARATOR;
}

/**
 * Devuelve la ruta de la firma lista para insertar en el PDF.
 * Convierte el PNG (con canal alfa, típico del canvas) a JPEG sobre fondo
 * blanco porque FPDF renderiza mal/transparente los PNG con alfa.
 * Si GD no está disponible o falla la conversión, devuelve la ruta original.
 */
function inst_firmaRutaJpeg(string $archivo): string
{
    $dir = __DIR__ . '/firmas/';
    $src = $dir . $archivo;
    if ($archivo === '' || !is_file($src)) {
        return '';
    }
    if (!function_exists('imagecreatefrompng')) {
        return $src;
    }

    $tmp = inst_tmpDir();
    $jpg = $tmp . 'firma_' . md5($archivo . @filemtime($src)) . '.jpg';
    if (is_file($jpg)) {
        return $jpg;
    }

    $img = @imagecreatefrompng($src);
    if (!$img) {
        return $src;
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $out = @imagecreatetruecolor($w, $h);
    if (!$out) {
        return $src;
    }
    $blanco = imagecolorallocate($out, 255, 255, 255);
    imagefill($out, 0, 0, $blanco);
    imagecopy($out, $img, 0, 0, 0, 0, $w, $h);
    $ok = @imagejpeg($out, $jpg, 95);

    // Sin imagedestroy(): deprecado desde PHP 8.5, el GC libera los
    // recursos GdImage automáticamente al salir de la función.
    unset($img, $out);

    return ($ok && is_file($jpg)) ? $jpg : $src;
}

/**
 * HTML del modal de selección de instructor (overlay propio, sin dependencias).
 * Expone: window.INSTRUCTORES y la función global abrirSeleccionInstructor(titulo, callback).
 */
function inst_renderModal(array $instructores): string
{
    $json = json_encode($instructores, JSON_UNESCAPED_UNICODE);

    return <<<HTML
<div id="instOverlay" class="inst-overlay" style="display:none">
    <div class="inst-caja">
        <h5 id="instTitulo">Seleccionar instructor</h5>
        <p class="inst-sub">¿Quién firma los certificados?</p>
        <select id="selInstructor" class="inst-select"></select>
        <div class="inst-botones">
            <button type="button" id="btnInstCancelar" class="inst-btn inst-btn-cancelar">Cancelar</button>
            <button type="button" id="btnInstConfirmar" class="inst-btn inst-btn-ok">Confirmar</button>
        </div>
    </div>
</div>
<style>
.inst-overlay{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.72);backdrop-filter:blur(4px)}
.inst-caja{background:rgba(12,24,40,.97);border:1px solid rgba(255,255,255,.2);border-radius:18px;padding:26px;width:min(420px,92vw);box-shadow:0 25px 60px rgba(0,0,0,.6);color:#F0EDF2;font-family:'Segoe UI',sans-serif}
.inst-caja h5{color:#5D5E70;font-weight:800;margin:0 0 4px}
.inst-sub{font-size:.82rem;color:rgba(199,228,207,.65);margin:0 0 16px}
.inst-select{width:100%;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.25);background:rgba(0,0,0,.4);color:#fff;font-size:1rem}
.inst-select option{background:#12283e;color:#fff}
.inst-botones{display:flex;gap:10px;justify-content:flex-end;margin-top:20px}
.inst-btn{border:none;border-radius:12px;padding:10px 18px;font-weight:700;cursor:pointer;font-size:.9rem}
.inst-btn-ok{background:linear-gradient(135deg,#DD4841,#5D5E70);color:#fff}
.inst-btn-cancelar{background:rgba(255,255,255,.12);color:#F0EDF2}
</style>
<script>
window.INSTRUCTORES = $json;
let instCallback = null;
function instEsc(s){
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function abrirSeleccionInstructor(titulo, callback){
    const lista = window.INSTRUCTORES || [];
    if (lista.length === 0) { callback(null); return; }
    document.getElementById('instTitulo').textContent = titulo;
    const sel = document.getElementById('selInstructor');
    sel.innerHTML = lista.map(i => '<option value="'+i.id+'">'+instEsc(i.apellido)+' '+instEsc(i.nombre)+'</option>').join('');
    sel.selectedIndex = 0;
    instCallback = callback;
    document.getElementById('instOverlay').style.display = 'flex';
}
document.addEventListener('DOMContentLoaded', function(){
    const overlay = document.getElementById('instOverlay');
    if (!overlay) return;
    const cerrar = function(){ overlay.style.display = 'none'; instCallback = null; };
    document.getElementById('btnInstCancelar').addEventListener('click', cerrar);
    document.getElementById('btnInstConfirmar').addEventListener('click', function(){
        const id  = parseInt(document.getElementById('selInstructor').value, 10);
        const ins = (window.INSTRUCTORES || []).find(i => i.id === id) || null;
        const cb  = instCallback;
        cerrar();
        if (cb) cb(ins);
    });
});
</script>
HTML;
}
