<?php

// ==========================================
// CONEXIÓN A BASE DE DATOS (ArgenCloud)
// InfinityFree quedó obsoleto.
// Las credenciales pueden definirse en un archivo .env
// (DB_HOST, DB_USER, DB_PASS, DB_NAME) para no quedar
// hardcodeadas en el código.
// ==========================================

$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val);
    }
}

$host = $_ENV['DB_HOST'] ?? '127.0.0.1'; // usar IP para evitar socket Unix faltante en Linux/contenedores
$user = $_ENV['DB_USER'] ?? 'cecap_user';           // usuario de la base
$pass = $_ENV['DB_PASS'] ?? 'YxBPF/EsJhEeM3GVQOU0eEOJ/JOfnk9A';           // contraseña
$db   = $_ENV['DB_NAME'] ?? 'cecap'; // nombre de la BD

// Crear conexión
$conn = new mysqli($host, $user, $pass, $db);
$conn->set_charset("utf8mb4");


// Verificar conexión
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}
// echo "Conexión exitosa"; // para probar
?>