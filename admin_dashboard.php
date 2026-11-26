<?php
error_reporting(E_ALL);
ini_set('display_errors',1);

session_start();
include("conexion.php");
include("auth.php");
require_once("auditar.php");



// seguridad
if (!isset($_SESSION['id_instru']) || !permiso("admin")) {
    header("Location: panel.php");
    exit();
}

// actualizar actividad
$id=(int)$_SESSION['id_instru'];
$conn->query("UPDATE usuarios SET last_activity=NOW() WHERE id=$id");


// ============================
// MÉTRICAS
// ============================

// total usuarios
$totalUsuarios = $conn->query("
SELECT COUNT(*) c 
FROM usuarios 
WHERE eliminado=0
")->fetch_assoc()['c'];

// activos
$totalActivos = $conn->query("
SELECT COUNT(*) c 
FROM usuarios 
WHERE activo=1 AND eliminado=0
")->fetch_assoc()['c'];

// online (sin contar duplicados)
$online = $conn->query("
SELECT COUNT(*) c 
FROM usuarios 
WHERE last_activity > (NOW() - INTERVAL 5 MINUTE)
AND eliminado=0
")->fetch_assoc()['c'];

// por rol
$roles = $conn->query("
SELECT rol, COUNT(*) c 
FROM usuarios 
WHERE eliminado=0 
GROUP BY rol
");

// últimos accesos
$ultimos = $conn->query("
SELECT usuario,nombre,rol,last_activity
FROM usuarios
WHERE eliminado=0
ORDER BY last_activity DESC
LIMIT 10
");

// usuarios online lista
$onlineList = $conn->query("
SELECT usuario,nombre,rol 
FROM usuarios
WHERE last_activity > (NOW() - INTERVAL 5 MINUTE)
AND eliminado=0
");
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Dashboard Admin</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/variables.css">

<style>
body{background:#3C313F;color:#F0EDF2;font-family:Segoe UI}
.card{background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.10)}
.stat{font-size:28px;font-weight:bold}
.card:hover{border-color:#5D5E70}
</style>
</head>

<body class="container-fluid py-4">

<h2 class="mb-4">📊 Dashboard Administración</h2>

<div class="row g-3 mb-4">

<div class="col-md-3">
<div class="card p-3 text-center">
<div>Total usuarios</div>
<div class="stat"><?= $totalUsuarios ?></div>
</div>
</div>

<div class="col-md-3">
<div class="card p-3 text-center">
<div>Activos</div>
<div class="stat"><?= $totalActivos ?></div>
</div>
</div>

<div class="col-md-3">
<div class="card p-3 text-center">
<div>Online</div>
<div class="stat text-success" id="onlineCount"><?= $online ?></div>
</div>
</div>

<div class="col-md-3">
<div class="card p-3 text-center">
<div>Tu sesión</div>
<div class="stat badge bg-info"><?= $_SESSION['usuario'] ?></div>
</div>
</div>

</div>


<!-- USUARIOS ONLINE -->
<div class="card p-3 mb-4">
<h5>🟢 Usuarios conectados ahora</h5>

<div class="row">
<?php while($o=$onlineList->fetch_assoc()): ?>
<div class="col-md-2">
<div class="card p-2 text-center">
<div><?= $o['usuario'] ?></div>
<div class="small text-info"><?= $o['rol'] ?></div>
</div>
</div>
<?php endwhile; ?>
</div>
</div>

<!-- ROLES -->
<div class="card p-3 mb-4">
<h5>Usuarios por rol</h5>
<div class="row">
<?php while($r=$roles->fetch_assoc()): ?>
<div class="col-md-2">
<div class="card p-2 text-center">
<div><?= $r['rol'] ?></div>
<div class="stat"><?= $r['c'] ?></div>
</div>
</div>
<?php endwhile; ?>
</div>
</div>


<!-- ÚLTIMOS ACCESOS -->
<div class="card p-3">
<h5>Últimos accesos</h5>

<table class="table table-dark table-striped">
<thead>
<tr>
<th>Usuario</th>
<th>Nombre</th>
<th>Rol</th>
<th>Actividad</th>
</tr>
</thead>

<tbody>
<?php while($u=$ultimos->fetch_assoc()): ?>
<tr>
<td><?= $u['usuario'] ?></td>
<td><?= $u['nombre'] ?></td>
<td><?= $u['rol'] ?></td>
<td><?= $u['last_activity'] ?></td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
</div>


<script>
// refrescar contador online
setInterval(()=>{
 fetch("dashboard_online.php")
 .then(r=>r.text())
 .then(t=>document.getElementById("onlineCount").innerHTML=t);
},8000);

// keep alive
setInterval(()=>{
 fetch("keep_alive.php");
},30000);
</script>

</body>
</html>
