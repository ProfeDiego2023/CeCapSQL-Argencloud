<?php
require_once __DIR__ . "/conexion.php";

// ⚠️ CAMBIÁ SOLO ESTO
$usuarioAdmin = 'admin';
$nuevaClave   = 'ADMIN1234'; // contraseña temporal

$hash = password_hash($nuevaClave, PASSWORD_DEFAULT);

$sql = "UPDATE usuarios SET pass = ? WHERE usuario = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $hash, $usuarioAdmin);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    echo "✔ Contraseña del ADMIN restablecida<br>";
    echo "👉 Usuario: <b>$usuarioAdmin</b><br>";
    echo "👉 Nueva clave: <b>$nuevaClave</b><br>";
    echo "<br>⚠️ BORRÁ ESTE ARCHIVO AHORA MISMO";
} else {
    echo "❌ No se pudo actualizar (¿existe el usuario admin?)";
}
