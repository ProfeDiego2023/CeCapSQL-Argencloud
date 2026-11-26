<?php
session_start();

/* ================= ERRORES ================= */
error_reporting(E_ALL);
ini_set('display_errors', 1);


require_once __DIR__ . "/conexion.php";

/* 🔒 SEGURIDAD */
if (!isset($_SESSION['id_instru'])) {
    header("Location: login.php");
    exit;
}

/* 📊 ÚLTIMOS 10 REGISTROS */
$sql = "SELECT 
            id, fecha, fecha_dia, apellidos, nombres, dni,
            edad, genero, lugar, email, licencia, turno
        FROM asistencias
        ORDER BY id DESC
        LIMIT 10";
$res = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Registro Manual de Asistencias</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/variables.css">

<style>
body{
    min-height:100vh;
    margin:0;
    font-family:'Segoe UI', sans-serif;
    background: linear-gradient(135deg, #504254, #3C313F);
    background-attachment: fixed;
}

/* CONTENEDOR */
.page-wrap{
    max-width:1400px;
    margin:auto;
    padding:40px 20px;
}

/* GLASS */
.glass{
    background:rgba(255,255,255,.10);
    backdrop-filter:blur(14px);
    border:1px solid rgba(255,255,255,.18);
    border-radius:22px;
    box-shadow:0 25px 55px rgba(0,0,0,.55);
    color:#fff;
    padding:28px;
}

/* FORM */
.form-control,.form-select{
    background:rgba(0,0,0,.35);
    border:1px solid rgba(255,255,255,.25);
    color:#fff;
    border-radius:14px;
}
.form-control::placeholder{color:rgba(255,255,255,.6)}
.form-control:focus,.form-select:focus{
    background:rgba(0,0,0,.45);
    border-color:#5D5E70;
    box-shadow:none;
}

/* BOTÓN */
.btn-main{
    height:52px;
    border-radius:16px;
    font-weight:700;
    background:linear-gradient(135deg,#DD4841,#B03030);
    border:none;
}

/* TABLA */
.table{
    --bs-table-bg: transparent;
    --bs-table-color:#fff;
}
.table thead th{
    font-size:.8rem;
    text-transform:uppercase;
    color:#C7E4CF;
}
.table tbody tr:hover{
    background:rgba(255,255,255,.08);
}
.ultimo-registro{
    background:linear-gradient(135deg,rgba(199,228,207,.30),rgba(80,66,84,.30));
    font-weight:700;
}

/* TABLA ANCHA + SCROLL */
.tabla-ancha{
    max-height:70vh;
    overflow-y:auto;
}
</style>
</head>

<body>

<div class="page-wrap">
    <div class="container-fluid px-3">
        <div class="row g-4 align-items-start">

            <!-- FORMULARIO -->
            <div class="col-lg-3">
                <div class="glass">
                    <h4 class="text-center mb-3">Registro Manual</h4>
                    <form action="guardar_registro_manual.php" method="POST">
                        <input name="apellidos" class="form-control mb-2" placeholder="Apellidos" required>
                        <input name="nombres" class="form-control mb-2" placeholder="Nombres" required>
                        <input name="dni" class="form-control mb-2" placeholder="DNI" required>
                        <input type="number" name="edad" class="form-control mb-2" placeholder="Edad" required>

                        <select name="genero" class="form-select mb-2" required>
                            <option value="">Género</option>
                            <option>Masculino</option>
                            <option>Femenino</option>
                            <option>Otro</option>
                        </select>

                        <input name="lugar" class="form-control mb-2" placeholder="Lugar" required>
                        <input type="email" name="email" class="form-control mb-2" placeholder="Email" required>
                        <input name="licencia" class="form-control mb-2" placeholder="Licencia" required>

                        <select name="turno" class="form-select mb-3" required>
                            <option value="">Turno</option>
                            <option>13:30</option>
                            <option>20:00</option>
                            <option>Otro</option>
                        </select>

                        <button class="btn btn-main w-100">Guardar Asistencia</button>
                    </form>
                </div>
            </div>

            <!-- TABLA -->
            <div class="col-lg-9">
                <div class="glass tabla-ancha">
                    <h4 class="text-center mb-3">Últimos 10 Registros</h4>

                    <div class="d-flex justify-content-between mb-2">
                        <input id="filtro" class="form-control form-control-sm w-50" placeholder="🔍 Filtrar registros">
                        <small class="text-info">Auto-refresh activo</small>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-borderless align-middle" id="tablaAsistencias">
                            <thead>
                                <tr>
                                    <th>ID</th><th>Fecha</th><th>Día</th>
                                    <th>Apellido</th><th>Nombre</th>
                                    <th>DNI</th><th>Edad</th><th>Género</th>
                                    <th>Lugar</th><th>Email</th>
                                    <th>Licencia</th><th>Turno</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $primero = true;
                            if ($res && $res->num_rows):
                                while ($r = $res->fetch_assoc()):
                            ?>
                                <tr class="<?= $primero ? 'ultimo-registro' : '' ?>" 
                                    onclick="editarRegistro(<?= $r['id'] ?>)" 
                                    style="cursor:pointer">
                                    <td><?= $r['id'] ?></td>
                                    <td><?= date("d/m/Y H:i",strtotime($r['fecha'])) ?></td>
                                    <td><?= $r['fecha_dia'] ?></td>
                                    <td><?= htmlspecialchars($r['apellidos']) ?></td>
                                    <td><?= htmlspecialchars($r['nombres']) ?></td>
                                    <td><?= htmlspecialchars($r['dni']) ?></td>
                                    <td><?= htmlspecialchars($r['edad']) ?></td>
                                    <td><?= htmlspecialchars($r['genero']) ?></td>
                                    <td><?= htmlspecialchars($r['lugar']) ?></td>
                                    <td><?= htmlspecialchars($r['email']) ?></td>
                                    <td><?= htmlspecialchars($r['licencia']) ?></td>
                                    <td><?= htmlspecialchars($r['turno']) ?></td>
                                </tr>
                            <?php
                                $primero = false;
                                endwhile;
                            else:
                            ?>
                                <tr>
                                    <td colspan="12" class="text-center">Sin registros</td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>

        </div>
    </div>
</div>

<!-- ================= JS ================= -->
<script>
/* AUTO REFRESH SOLO TABLA */
function refrescarTabla(){
    fetch("tabla_asistencias.php") // este archivo devuelve solo los <tr>
        .then(res => res.text())
        .then(html => {
            const tbody = document.querySelector("#tablaAsistencias tbody");
            if(tbody){
                tbody.innerHTML = html;
            }
        });
}
setInterval(refrescarTabla, 8000);

/* FILTRO */
document.getElementById("filtro").addEventListener("keyup", function(){
    const v = this.value.toLowerCase();
    document.querySelectorAll("#tablaAsistencias tbody tr").forEach(tr => {
        tr.style.display = tr.innerText.toLowerCase().includes(v) ? "" : "none";
    });
});

/* EDITAR */
function editarRegistro(id){
    location.href = "editar_asistencia.php?id=" + id;
}
</script>

</body>
</html>
