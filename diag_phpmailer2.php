<?php
/**
 * diag_phpmailer2.php
 * Lista TODO lo que hay dentro de vendor/phpmailer (recursivo)
 * y el tamaño total de vendor/, para confirmar si la subida
 * quedó incompleta. Subir a la raíz de htdocs, visitar, pegar
 * el resultado, y BORRAR después.
 */

header("Content-Type: text/html; charset=UTF-8");
echo "<pre style='font-family:monospace;font-size:13px;background:#111;color:#0f0;padding:16px'>";

$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/\\');

function listarRecursivo(string $ruta, int $nivel = 0): void {
    if (!is_dir($ruta)) {
        echo str_repeat("  ", $nivel) . "(no es una carpeta o no existe: $ruta)\n";
        return;
    }
    $items = scandir($ruta);
    if ($items === false) {
        echo str_repeat("  ", $nivel) . "(no se pudo leer el directorio)\n";
        return;
    }
    $items = array_diff($items, ['.', '..']);
    if (empty($items)) {
        echo str_repeat("  ", $nivel) . "(carpeta vacía)\n";
        return;
    }
    foreach ($items as $item) {
        $full = $ruta . '/' . $item;
        if (is_dir($full)) {
            echo str_repeat("  ", $nivel) . "[DIR]  $item\n";
            listarRecursivo($full, $nivel + 1);
        } else {
            echo str_repeat("  ", $nivel) . "[FILE] $item (" . filesize($full) . " bytes)\n";
        }
    }
}

echo "=== CONTENIDO COMPLETO de vendor/phpmailer ===\n";
listarRecursivo($docRoot . '/vendor/phpmailer');

echo "\n=== CONTENIDO COMPLETO de vendor/composer ===\n";
listarRecursivo($docRoot . '/vendor/composer');

echo "\n=== CONTENIDO COMPLETO de vendor/setasign (fpdf/fpdi) ===\n";
listarRecursivo($docRoot . '/vendor/setasign');

echo "\n=== FIN ===\n";
echo "</pre>";
