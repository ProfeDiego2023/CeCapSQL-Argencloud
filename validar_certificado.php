<?php
require_once __DIR__ . "/conexion.php";

$codigo = $_GET['codigo'] ?? '';

if(!$codigo){
    die("Código inválido");
}

$stmt = $conn->prepare("
    SELECT apellidos, nombres, dni, licencia, fecha_dia,
           estado_certificado, impresiones, oculto
    FROM asistencias
    WHERE codigo_seguridad = ?
");
$stmt->bind_param("s", $codigo);
$stmt->execute();
$res = $stmt->get_result();

if($res->num_rows == 0){
    die("Certificado no encontrado");
}

$row = $res->fetch_assoc();

if($row['oculto'] == 1){
    die("Certificado revocado");
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Validación de Certificado</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-light d-flex align-items-center justify-content-center" style="min-height:100vh;">

<div class="card p-4 shadow-lg" style="max-width:500px;">
    <h4 class="text-center mb-3 text-success">
        ✔ Certificado Válido
    </h4>

    <p><strong>Apellido:</strong> <?= htmlspecialchars($row['apellidos']) ?></p>
    <p><strong>Nombre:</strong> <?= htmlspecialchars($row['nombres']) ?></p>
    <p><strong>DNI:</strong> <?= $row['dni'] ?></p>
    <p><strong>Licencia:</strong> <?= htmlspecialchars($row['licencia']) ?></p>
    <p><strong>Fecha:</strong> <?= date("d/m/Y", strtotime($row['fecha_dia'])) ?></p>
    <p><strong>Emisiones:</strong> <?= $row['impresiones'] ?></p>

    <?php if($row['impresiones'] > 1): ?>
        <div class="alert alert-warning text-center">
            Este certificado fue reimpreso.
        </div>
    <?php endif; ?>

</div>

</body>
</html>