<?php
date_default_timezone_set("America/Argentina/Buenos_Aires");

require_once __DIR__ . "/conexion.php";
$auto = require __DIR__."/motor_eventos.php";

$lugar = $auto['lugar'];
$turno = $auto['turno'];
$categ = $auto['categoria'];



/* ================= OBTENER EVENTO ACTIVO ================= */

$hoy  = date("Y-m-d");
$hora = date("H:i:s");

$sql = "
SELECT * FROM config_form_eventos
WHERE activo = 1
AND fecha_desde <= ?
AND fecha_hasta >= ?
ORDER BY id DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss",$hoy,$hoy);
$stmt->execute();
$res = $stmt->get_result();

$evento = $res->fetch_assoc();
$lugar  = $evento['lugar']  ?? '';
$turno  = $evento['turno']  ?? '';
$categ  = $evento['licencia'] ?? '';
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Registro</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/variables.css">

<style>
body{
    background: linear-gradient(135deg, #504254, #3C313F);
    background-attachment: fixed;
    color:#F0EDF2;
    font-family:Segoe UI;
}

.card-glass{
    background:rgba(255,255,255,0.08);
    border-radius:22px;
    padding:25px;
    backdrop-filter:blur(12px);
    max-width:900px;
    margin:auto;
    margin-top:20px;
}

.logo{
    text-align:center;
    margin-bottom:15px;
}

.logo img{
    max-height:70px;
}

input, select{
    background:#1b2a3a !important;
    border:none !important;
    color:white !important;
}

.info-auto{
    background:rgba(0,0,0,0.4);
    padding:12px;
    border-radius:10px;
    margin-bottom:15px;
}
</style>
</head>

<body>

<div class="container">
<div class="card-glass">

<div class="logo">
<img src="img/logo_evento.png">
</div>

<h4 class="text-center mb-3">Registro de Asistencia</h4>

<div class="info-auto">
📍 Lugar: <b><?= $lugar ?></b><br>
🕒 Turno: <b><?= $turno ?></b><br>
🎓 Categoría: <b><?= $categ ?></b>
</div>

<form method="POST" action="guardar_asistencia.php">

<div class="row">

<div class="col-md-6">
<label>Apellidos</label>
<input name="apellidos" class="form-control" required>
</div>

<div class="col-md-6">
<label>Nombres</label>
<input name="nombres" class="form-control" required>
</div>

<div class="col-md-6">
<label>DNI</label>
<input name="dni" class="form-control" required>
</div>

<div class="col-md-6">
<label>Sexo</label>
<select name="sexo" class="form-control">
<option value="M">M</option>
<option value="F">F</option>
</select>
</div>

<input type="hidden" name="lugar" value="<?= $lugar ?>">
<input type="hidden" name="turno" value="<?= $turno ?>">
<input type="hidden" name="licencia" value="<?= $categ ?>">

</div>

<button class="btn btn-success w-100 mt-3">
Guardar
</button>

</form>

</div>
</div>

</body>
</html>
