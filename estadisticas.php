<?php
session_start();
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/conexion.php";

proteger();

if (!permiso("ver_todos")) {
    die("Acceso denegado");
}
// ==============================
// FILTRO POR FECHAS
// ==============================

$desde = $_GET['desde'] ?? null;
$hasta = $_GET['hasta'] ?? null;
$filtro_rapido = $_GET['filtro'] ?? null;

if ($filtro_rapido) {

    switch ($filtro_rapido) {

        case 'hoy':
            $desde = $hasta = date('Y-m-d');
        break;

        case 'semana':
            $desde = date('Y-m-d', strtotime('monday this week'));
            $hasta = date('Y-m-d');
        break;

        case 'mes':
            $desde = date('Y-m-01');
            $hasta = date('Y-m-d');
        break;

        case 'limpiar':
            $desde = null;
            $hasta = null;
        break;
    }
}

$filtro_fecha = "AND oculto = 0";

if ($desde && $hasta) {

    $desde_val = date('Y-m-d', strtotime($desde));
    $hasta_val = date('Y-m-d', strtotime($hasta));

    $filtro_fecha .= " AND fecha_dia BETWEEN '$desde_val' AND '$hasta_val'";
}
/* ==============================
   CONSULTAS
================================ */

// Total general
$total = $conn->query("
    SELECT COUNT(*) as total 
    FROM asistencias 
    WHERE 1=1
    $filtro_fecha
")->fetch_assoc()['total'];

// ==============================
// KPI ADICIONALES
// ==============================

// Promedio por día
$promedio_dia = 0;

$consulta_promedio = $conn->query("
    SELECT COUNT(*) / COUNT(DISTINCT fecha_dia) as promedio
    FROM asistencias
    WHERE 1=1
    $filtro_fecha
");

if ($fila = $consulta_promedio->fetch_assoc()) {
    $promedio_dia = round($fila['promedio'] ?? 0, 2);
}

// Día con mayor asistencia
$dia_top = "-";
$cantidad_top = 0;

$consulta_top = $conn->query("
    SELECT fecha_dia, COUNT(*) as cantidad
    FROM asistencias
    WHERE 1=1
    $filtro_fecha
    GROUP BY fecha_dia
    ORDER BY cantidad DESC
    LIMIT 1
");

if ($fila = $consulta_top->fetch_assoc()) {
    $dia_top = date("d/m/Y", strtotime($fila['fecha_dia']));
    $cantidad_top = $fila['cantidad'];
}

// Turno más concurrido
$turno_top = "-";
$turno_cantidad = 0;

$consulta_turno_top = $conn->query("
    SELECT turno, COUNT(*) as cantidad
    FROM asistencias
    WHERE 1=1
    $filtro_fecha
    GROUP BY turno
    ORDER BY cantidad DESC
    LIMIT 1
");

if ($fila = $consulta_turno_top->fetch_assoc()) {
    $turno_top = htmlspecialchars($fila['turno']);
    $turno_cantidad = $fila['cantidad'];
}

// Por fecha
$por_fecha = $conn->query("
    SELECT fecha_dia, COUNT(*) as cantidad
    FROM asistencias
    WHERE 1=1
    $filtro_fecha
    GROUP BY fecha_dia
    ORDER BY fecha_dia DESC
");

// Por turno
$por_turno = $conn->query("
    SELECT turno, COUNT(*) as cantidad
    FROM asistencias
    WHERE 1=1
    $filtro_fecha
    GROUP BY turno
    ORDER BY cantidad DESC
");

// Por lugar
$por_lugar = $conn->query("
    SELECT lugar, COUNT(*) as cantidad
    FROM asistencias
    WHERE 1=1
    $filtro_fecha
    GROUP BY lugar
    ORDER BY cantidad DESC
");

// Por estado certificado
$por_estado = $conn->query("
    SELECT COALESCE(estado_certificado,'SIN DEFINIR') as estado_certificado,
           COUNT(*) as cantidad
    FROM asistencias
    WHERE 1=1
    $filtro_fecha
    GROUP BY estado_certificado
");
?>
<?php

$datos_fecha = [];
$datos_turno = [];
$datos_lugar = [];
$datos_estado = [];

// Fecha
while($row = $por_fecha->fetch_assoc()){
    $datos_fecha[] = $row;
}

// Turno
while($row = $por_turno->fetch_assoc()){
    $datos_turno[] = $row;
}

// Lugar
while($row = $por_lugar->fetch_assoc()){
    $datos_lugar[] = $row;
}

// Estado
while($row = $por_estado->fetch_assoc()){
    $datos_estado[] = $row;
}

// ==============================
// PREPARAR PARA GRÁFICOS
// ==============================

$labels_fecha = [];
$data_fecha = [];

foreach($datos_fecha as $row){
    $labels_fecha[] = date("d/m/Y", strtotime($row['fecha_dia']));
    $data_fecha[] = $row['cantidad'];
}

$labels_turno = [];
$data_turno = [];

foreach($datos_turno as $row){
    $labels_turno[] = htmlspecialchars($row['turno']);
    $data_turno[] = $row['cantidad'];
}

$labels_lugar = [];
$data_lugar = [];

foreach($datos_lugar as $row){
    $labels_lugar[] = $row['lugar'];
    $data_lugar[] = $row['cantidad'];
}

$labels_estado = [];
$data_estado = [];

foreach($datos_estado as $row){
    $labels_estado[] = $row['estado_certificado'] ?? 'SIN DEFINIR';
    $data_estado[] = $row['cantidad'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Estadísticas</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/variables.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>   
</head>

<body class="bg-dark text-light">
<div class="container mt-4">

    <!-- HEADER -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3>📊 Estadísticas Generales</h3>
        <a href="panel.php" class="btn btn-outline-light">⬅ Volver</a>
    </div>
    
    <div class="row mb-4">

    <div class="col-md-3">
        <div class="card bg-success text-center p-3">
            <h6>Total Asistentes</h6>
            <h3><?= $total ?></h3>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card bg-info text-center p-3">
            <h6>Promedio por Día</h6>
            <h3><?= $promedio_dia ?></h3>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card bg-warning text-center p-3">
            <h6>Día con Más Asistencia</h6>
            <small><?= $dia_top ?></small>
            <h5><?= $cantidad_top ?> personas</h5>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card bg-primary text-center p-3">
            <h6>Turno Más Concurrido</h6>
            <small><?= $turno_top ?></small>
            <h5><?= $turno_cantidad ?> personas</h5>
        </div>
    </div>

    </div>

    <form method="GET" class="card bg-secondary p-3 mb-4">
        <div class="row align-items-end">
            
            <div class="col-md-4">
                <label class="form-label">Desde</label>
                <input type="date" name="desde" class="form-control"
                    value="<?= htmlspecialchars($desde ?? '') ?>">
            </div>

            <div class="col-md-4">
                <label class="form-label">Hasta</label>
                <input type="date" name="hasta" class="form-control"
                    value="<?= htmlspecialchars($hasta ?? '') ?>">
            </div>

            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100">
                    🔎 Filtrar
                </button>
            </div>

        </div>

        <div class="col-12 mt-3 text-center">

            <a href="?filtro=hoy" class="btn btn-outline-light btn-sm">📅 Hoy</a>

            <a href="?filtro=semana" class="btn btn-outline-light btn-sm">📆 Esta semana</a>

            <a href="?filtro=mes" class="btn btn-outline-light btn-sm">🗓 Este mes</a>

            <a href="?filtro=limpiar" class="btn btn-outline-danger btn-sm">🔄 Limpiar</a>

        </div>
    </form>

    <?php if ($desde && $hasta): ?>
    <div class="alert alert-info">
        Mostrando estadísticas desde 
        <strong><?= date("d/m/Y", strtotime($desde)) ?></strong> 
        hasta 
        <strong><?= date("d/m/Y", strtotime($hasta)) ?></strong>
    </div>
    <?php endif; ?>

    <!-- TOTAL -->
    <div class="card bg-secondary mb-4">
        <div class="card-body text-center">
            <h4>Total de asistentes</h4>
            <h2 class="text-success"><?= $total ?></h2>
        </div>
    </div>

    <!-- GRÁFICOS -->
    <div class="row">

        <div class="col-md-6 mb-4">
            <div class="card bg-secondary">
                <div class="card-header">📅 Asistentes por Fecha</div>
                <div class="card-body">
                    <canvas id="graficoFecha"></canvas>
                </div>
            </div>
        </div>

        <div class="col-md-6 mb-4">
            <div class="card bg-secondary">
                <div class="card-header">🕐 Por Turno</div>
                <div class="card-body">
                    <canvas id="graficoTurno"></canvas>
                </div>
            </div>
        </div>

        <div class="col-md-6 mb-4">
            <div class="card bg-secondary">
                <div class="card-header">📍 Por Lugar</div>
                <div class="card-body">
                    <canvas id="graficoLugar"></canvas>
                </div>
            </div>
        </div>

        <div class="col-md-6 mb-4">
            <div class="card bg-secondary">
                <div class="card-header">🧾 Estado Certificados</div>
                <div class="card-body">
                    <canvas id="graficoEstado"></canvas>
                </div>
            </div>
        </div>

    </div>
    <!-- TABLAS -->
    <div class="row">

        <!-- FECHA -->
        <div class="col-md-6 mb-4">
            <div class="card bg-secondary">
                <div class="card-header">📅 Por Fecha</div>
                <div class="card-body table-responsive">
                    <table class="table table-dark table-sm">
                        <?php foreach($datos_fecha as $row): ?>
                        <tr>
                            <td><?= date("d/m/Y", strtotime($row['fecha_dia'])) ?></td>
                            <td class="text-end"><?= $row['cantidad'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- POR TURNO -->
        <div class="col-md-6 mb-4">
            <div class="card bg-secondary">
                <div class="card-header">🕐 Por Turno</div>
                <div class="card-body table-responsive">
                    <table class="table table-dark table-sm">
                        <?php foreach($datos_turno as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['turno']) ?></td>
                                <td class="text-end"><?= $row['cantidad'] ?></td>
                                </tr>
                        <?php endforeach; ?>
                     </table>
                </div>
            </div>
        </div>

                <!-- POR LUGAR -->
                <div class="col-md-6 mb-4">
                    <div class="card bg-secondary">
                        <div class="card-header">📍 Por Lugar</div>
                        <div class="card-body table-responsive">
                            <table class="table table-dark table-sm">
                                <?php foreach($datos_lugar as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['lugar']) ?></td>
                                        <td class="text-end"><?= $row['cantidad'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- POR ESTADO -->
                <div class="col-md-6 mb-4">
                    <div class="card bg-secondary">
                        <div class="card-header">🧾 Estado de Certificados</div>
                        <div class="card-body table-responsive">
                            <table class="table table-dark table-sm">
                                <?php foreach($datos_estado as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['estado_certificado'] ?? 'SIN DEFINIR') ?></td>
                                        <td class="text-end"><?= $row['cantidad'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    </div>
                </div>

            </div>

    </div>
        <script>
        const opciones = {
            responsive: true,
            plugins: {
                legend: {
                    labels: { color: "white" }
                }
            },
            scales: {
                x: { ticks: { color: "white" } },
                y: { ticks: { color: "white" } }
            }
        };

        // Fecha
        new Chart(document.getElementById("graficoFecha"), {
            type: "bar",
            data: {
                labels: <?= json_encode($labels_fecha) ?>,
                datasets: [{
                    label: "Asistentes",
                    data: <?= json_encode($data_fecha) ?>,
                    backgroundColor: "#DD4841"
                }]
            },
            options: opciones
        });

        // Turno
        new Chart(document.getElementById("graficoTurno"), {
            type: "pie",
            data: {
                labels: <?= json_encode($labels_turno) ?>,
                datasets: [{
                    data: <?= json_encode($data_turno) ?>,
                    backgroundColor: ["#198754","#ffc107","#dc3545","#5D5E70","#6f42c1"]
                }]
            }
        });

        // Lugar
        new Chart(document.getElementById("graficoLugar"), {
            type: "bar",
            data: {
                labels: <?= json_encode($labels_lugar) ?>,
                datasets: [{
                    label: "Asistentes",
                    data: <?= json_encode($data_lugar) ?>,
                    backgroundColor: "#C7E4CF"
                }]
            },
            options: opciones
        });

        // Estado
        new Chart(document.getElementById("graficoEstado"), {
            type: "doughnut",
            data: {
                labels: <?= json_encode($labels_estado) ?>,
                datasets: [{
                    data: <?= json_encode($data_estado) ?>,
                    backgroundColor: ["#198754","#ffc107","#dc3545","#6c757d"]
                }]
            }
        });
        </script>
    </div>
</body>
</html>