<?php
/**
 * diag_phpmailer.php
 * -------------------------------------------------------------
 * Script de diagnóstico TEMPORAL.
 * Subilo a la raíz de htdocs, visitalo desde el navegador,
 * copiame el resultado, y después BORRALO (no dejar en producción).
 * -------------------------------------------------------------
 */

header("Content-Type: text/html; charset=UTF-8");
echo "<pre style='font-family:monospace;font-size:13px;background:#111;color:#0f0;padding:16px'>";

function chk(string $ruta): void {
    $existe = file_exists($ruta);
    $tipo   = is_dir($ruta) ? "DIR " : (is_file($ruta) ? "FILE" : "??? ");
    $legible = is_readable($ruta) ? "legible" : "NO legible";
    echo ($existe ? "✅" : "❌") . " [$tipo] " . ($existe ? $legible : "no existe") . "  →  $ruta\n";
}

echo "=== INFO BÁSICA ===\n";
echo "__DIR__            : " . __DIR__ . "\n";
echo "DOCUMENT_ROOT       : " . ($_SERVER['DOCUMENT_ROOT'] ?? '(no definido)') . "\n";
echo "PHP version          : " . PHP_VERSION . "\n\n";

$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/\\');

echo "=== CHEQUEO DE RUTAS CLAVE ===\n";
chk($docRoot . '/vendor');
chk($docRoot . '/vendor/autoload.php');
chk($docRoot . '/vendor/composer');
chk($docRoot . '/vendor/composer/ClassLoader.php');
chk($docRoot . '/vendor/composer/autoload_real.php');
chk($docRoot . '/vendor/phpmailer');
chk($docRoot . '/vendor/phpmailer/phpmailer');
chk($docRoot . '/vendor/phpmailer/phpmailer/src');
chk($docRoot . '/vendor/phpmailer/phpmailer/src/PHPMailer.php');
chk($docRoot . '/vendor/phpmailer/phpmailer/src/SMTP.php');
chk($docRoot . '/vendor/phpmailer/phpmailer/src/Exception.php');

echo "\n=== LISTADO REAL de /vendor (1 nivel) ===\n";
if (is_dir($docRoot . '/vendor')) {
    foreach (scandir($docRoot . '/vendor') as $item) {
        if ($item === '.' || $item === '..') continue;
        echo (is_dir($docRoot . '/vendor/' . $item) ? "[DIR]  " : "[FILE] ") . $item . "\n";
    }
} else {
    echo "(la carpeta vendor/ no existe en $docRoot)\n";
}

echo "\n=== LISTADO REAL de /vendor/phpmailer (si existe) ===\n";
$pmDir = $docRoot . '/vendor/phpmailer';
if (is_dir($pmDir)) {
    foreach (scandir($pmDir) as $item) {
        if ($item === '.' || $item === '..') continue;
        echo (is_dir($pmDir . '/' . $item) ? "[DIR]  " : "[FILE] ") . $item . "\n";
    }
} else {
    echo "(no existe $pmDir)\n";
}

echo "\n=== INTENTO DE CARGA REAL ===\n";
$autoload = $docRoot . '/vendor/autoload.php';
if (file_exists($autoload)) {
    try {
        require_once $autoload;
        echo "autoload.php se incluyó sin errores fatales.\n";
        echo "class_exists(PHPMailer\\PHPMailer\\PHPMailer): "
            . (class_exists('PHPMailer\\PHPMailer\\PHPMailer') ? "SI ✅" : "NO ❌") . "\n";
    } catch (Throwable $e) {
        echo "❌ Error al incluir autoload.php: " . $e->getMessage() . "\n";
    }
} else {
    echo "No se probó autoload.php porque no existe.\n";

    // Intento directo de los 3 archivos fuente
    $src = $docRoot . '/vendor/phpmailer/phpmailer/src';
    if (is_dir($src)) {
        try {
            require_once $src . '/Exception.php';
            require_once $src . '/PHPMailer.php';
            require_once $src . '/SMTP.php';
            echo "Carga directa de src/*.php: "
                . (class_exists('PHPMailer\\PHPMailer\\PHPMailer') ? "SI ✅" : "NO ❌") . "\n";
        } catch (Throwable $e) {
            echo "❌ Error al incluir directamente: " . $e->getMessage() . "\n";
        }
    } else {
        echo "Tampoco existe $src\n";
    }
}

echo "\n=== FIN DEL DIAGNÓSTICO ===\n";
echo "</pre>";
