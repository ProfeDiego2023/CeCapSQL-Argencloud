<?php
require_once "auth.php";
require_once "conexion.php";

proteger();

if (!permiso('admin')) {
    die("Acceso denegado");
}

if (!isset($_GET['id'])) {
    die("ID inválido");
}

$id = (int)$_GET['id'];

// 🔐 Generar contraseña temporal
function generarPassword($largo = 10): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    return substr(str_shuffle($chars), 0, $largo);
}

$nuevaPass = generarPassword();
$hash = password_hash($nuevaPass, PASSWORD_DEFAULT);

// 🔄 Actualizar DB
$sql = "UPDATE usuarios SET pass = ?, debe_cambiar_pass = 1 WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("si", $hash, $id);
$stmt->execute();

if ($stmt->affected_rows === 0) {
    die("No se pudo actualizar la contraseña");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Contraseña restablecida</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-light">

<div class="container mt-5">
  <div class="alert alert-success shadow-lg">
    <h4 class="mb-3">🔐 Contraseña restablecida</h4>
    <p>La nueva contraseña temporal es:</p>

    <div class="fs-3 fw-bold text-center bg-black p-3 rounded">
      <?= htmlspecialchars($nuevaPass, ENT_QUOTES, 'UTF-8') ?>
    </div>

    <p class="mt-3 mb-0 text-warning">
      ⚠️ Entregar esta contraseña al usuario y pedirle que la cambie al ingresar.
    </p>

    <a href="usuarios.php" class="btn btn-primary mt-3">Volver</a>
  </div>
</div>

</body>
</html>
