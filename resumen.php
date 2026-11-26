<?php
session_start();
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/auth.php";
proteger();

/* ==============================
   FILTROS
================================ */
$desde = trim($_GET['desde'] ?? '');
$hasta = trim($_GET['hasta'] ?? '');

/* ==============================
   MÉTRICAS RÁPIDAS (sin filtro de fecha)
================================ */
$stmt = $conn->prepare("SELECT COUNT(*) FROM asistencias WHERE oculto = 0 AND fecha_dia = CURDATE()");
$stmt->execute(); $stmt->bind_result($hoy); $stmt->fetch(); $stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) FROM asistencias WHERE oculto = 0
    AND MONTH(fecha_dia)=MONTH(CURDATE()) AND YEAR(fecha_dia)=YEAR(CURDATE())");
$stmt->execute(); $stmt->bind_result($esteMes); $stmt->fetch(); $stmt->close();

$turnoTop = '—'; $turnoTopCant = 0;
$stmt = $conn->prepare("SELECT turno, COUNT(*) c FROM asistencias
    WHERE oculto=0 AND turno!='' GROUP BY turno ORDER BY c DESC LIMIT 1");
$stmt->execute(); $stmt->bind_result($turnoTop, $turnoTopCant); $stmt->fetch(); $stmt->close();

$turnoTopCorto = $turnoTop;
if      (str_contains(strtolower($turnoTop),'13:30') || str_contains($turnoTop,'1°')) $turnoTopCorto = '1° Turno 13:30';
elseif  (str_contains(strtolower($turnoTop),'20:00') || str_contains($turnoTop,'2°')) $turnoTopCorto = '2° Turno 20:00';
elseif  (str_contains(strtolower($turnoTop),'8:30')  || str_contains($turnoTop,'3°')) $turnoTopCorto = '3° Turno 8:30';

/* ==============================
   DATOS PARA PROYECCIÓN
   Tendencia mes a mes (últimos 24 meses)
================================ */
$mesActual   = (int)date('m');
$anioActual  = (int)date('Y');
$diaActual   = (int)date('j');
$diasEnMes   = (int)date('t'); // días en el mes actual

// Datos mes a mes (últimos 24 meses)
$stmt = $conn->prepare("
    SELECT YEAR(fecha_dia) anio, MONTH(fecha_dia) mes, COUNT(*) total
    FROM asistencias
    WHERE oculto = 0
      AND fecha_dia >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
    GROUP BY YEAR(fecha_dia), MONTH(fecha_dia)
    ORDER BY anio ASC, mes ASC
");
$stmt->execute();
$resMeses = $stmt->get_result();
$datosMeses = [];
while ($r = $resMeses->fetch_assoc()) {
    $datosMeses[] = $r;
}
$stmt->close();

// Total acumulado en lo que va del año actual
$stmt = $conn->prepare("SELECT COUNT(*) FROM asistencias
    WHERE oculto=0 AND YEAR(fecha_dia)=?");
$stmt->bind_param("i", $anioActual);
$stmt->execute(); $stmt->bind_result($totalAnioActual); $stmt->fetch(); $stmt->close();

// Promedio mensual basado en meses completos del año actual
// (excluye el mes en curso si no terminó)
$stmt = $conn->prepare("
    SELECT AVG(total) FROM (
        SELECT COUNT(*) total
        FROM asistencias
        WHERE oculto=0
          AND YEAR(fecha_dia) = ?
          AND MONTH(fecha_dia) < ?
        GROUP BY MONTH(fecha_dia)
    ) t
");
$stmt->bind_param("ii", $anioActual, $mesActual);
$stmt->execute(); $stmt->bind_result($promedioMensual); $stmt->fetch(); $stmt->close();

// Si no hay meses completos este año, usar promedio del año anterior
if (!$promedioMensual) {
    $anioAnt = $anioActual - 1;
    $stmt = $conn->prepare("
        SELECT AVG(total) FROM (
            SELECT COUNT(*) total FROM asistencias
            WHERE oculto=0 AND YEAR(fecha_dia)=?
            GROUP BY MONTH(fecha_dia)
        ) t
    ");
    $stmt->bind_param("i", $anioAnt);
    $stmt->execute(); $stmt->bind_result($promedioMensual); $stmt->fetch(); $stmt->close();
}

$promedioMensual = round($promedioMensual ?? 0);

// Proyección mes actual: prorrateo de lo que llevas
$proyeccionMesActual = $diasEnMes > 0
    ? round(($esteMes / max($diaActual, 1)) * $diasEnMes)
    : $esteMes;

// Meses restantes (sin contar el actual)
$mesesRestantes = 12 - $mesActual;

// Proyección fin de año = acumulado año - lo que va del mes actual + proyección mes actual + promedio * meses restantes
$proyeccionAnual = ($totalAnioActual - $esteMes)
                 + $proyeccionMesActual
                 + ($promedioMensual * $mesesRestantes);

// Porcentaje del año transcurrido
$diaDelAnio    = (int)date('z') + 1;
$diasEnAnio    = date('L') ? 366 : 365;
$pctAnio       = round($diaDelAnio / $diasEnAnio * 100);

// Preparar datos para gráfico tendencia
$labelesMeses  = [];
$valoresMeses  = [];
$mesesES = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
foreach ($datosMeses as $d) {
    $labelesMeses[] = $mesesES[(int)$d['mes']] . ' ' . substr($d['anio'], 2);
    $valoresMeses[] = (int)$d['total'];
}

// Mes con más y menos asistentes (de los datos disponibles)
$mejorMes = $peorMes = null;
if (!empty($datosMeses)) {
    usort($datosMeses, fn($a,$b) => $b['total'] - $a['total']);
    $mejorMes = $datosMeses[0];
    $peorMes  = end($datosMeses);
}

/* ==============================
   HELPERS
================================ */
function fetchGroup(mysqli $conn, string $sql, string $types, array $params): array {
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res  = $stmt->get_result();
    $data = [];
    while ($r = $res->fetch_assoc()) {
        $k = trim($r['k'] !== '' ? $r['k'] : 'No especificado');
        $data[$k] = (int)$r['v'];
    }
    $stmt->close();
    return $data;
}

function diaES(string $dia): string {
    return ['Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles',
            'Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sábado','Sunday'=>'Domingo'][$dia] ?? $dia;
}

/* ==============================
   WHERE CON FILTROS
================================ */
$where  = ["oculto = 0"];
$params = [];
$types  = "";
if ($desde !== '') { $where[] = "fecha_dia >= ?"; $params[] = $desde; $types .= "s"; }
if ($hasta !== '') { $where[] = "fecha_dia <= ?"; $params[] = $hasta; $types .= "s"; }
$whereSQL = "WHERE " . implode(" AND ", $where);

$stmt = $conn->prepare("SELECT COUNT(*) FROM asistencias $whereSQL");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute(); $stmt->bind_result($total); $stmt->fetch(); $stmt->close();

$porGenero = fetchGroup($conn,
    "SELECT genero k, COUNT(*) v FROM asistencias $whereSQL GROUP BY genero ORDER BY v DESC",
    $types, $params);

$tmpTurnos = fetchGroup($conn,
    "SELECT turno k, COUNT(*) v FROM asistencias $whereSQL GROUP BY turno ORDER BY v DESC",
    $types, $params);
$porTurno = [];
foreach ($tmpTurnos as $k => $v) {
    $kLow = strtolower($k);
    if      (str_contains($kLow,'13:30')||str_contains($kLow,'1°')) $clave='1° Turno 13:30';
    elseif  (str_contains($kLow,'20:00')||str_contains($kLow,'2°')) $clave='2° Turno 20:00';
    elseif  (str_contains($kLow,'8:30') ||str_contains($kLow,'3°')) $clave='3° Turno 8:30';
    elseif  (str_contains($kLow,'prof'))                             $clave='Profesional';
    else                                                              $clave=$k?:'No especificado';
    $porTurno[$clave] = ($porTurno[$clave] ?? 0) + $v;
}
arsort($porTurno);

$tmpLugares = fetchGroup($conn,
    "SELECT lugar k, COUNT(*) v FROM asistencias $whereSQL GROUP BY lugar ORDER BY v DESC",
    $types, $params);
$porLugar = [];
$porEscuela = [];
$totalEscuelas = 0;

foreach ($tmpLugares as $k => $v) {
    $kUp = strtoupper($k);
    if (preg_match('/\bESC(UELA)?\b/i', $k)) {
        // Extraer y formatear nombre de la escuela para discriminación
        $nombreLimpio = trim(preg_replace('/^ESCUELA\s*[-:]?\s*/i', '', $k));
        if ($nombreLimpio === '') $nombreLimpio = 'ESCUELA (GENERAL)';
        else $nombreLimpio = 'ESC. ' . $nombreLimpio;

        $porEscuela[$nombreLimpio] = ($porEscuela[$nombreLimpio] ?? 0) + $v;
        $totalEscuelas += $v;
        $clave = 'ESCUELAS';
    } elseif (str_contains($kUp, 'LEHMAN') || $k === 'No especificado') {
        $clave = 'LEHMANN 1530';
    } elseif (str_contains($kUp, 'IRIONDO') || str_contains($kUp, 'NIDO')) {
        $clave = 'S.IRIONDO 5870';
    } else {
        $clave = $k;
    }
    $porLugar[$clave] = ($porLugar[$clave] ?? 0) + $v;
}
arsort($porLugar);
arsort($porEscuela);

// Lugares con escuelas discriminadas individualmente
$porLugarDiscriminado = [];
foreach ($porLugar as $k => $v) {
    if ($k === 'ESCUELAS') {
        foreach ($porEscuela as $esc => $cant) {
            $porLugarDiscriminado[$esc] = $cant;
        }
    } else {
        $porLugarDiscriminado[$k] = $v;
    }
}
arsort($porLugarDiscriminado);

$porAnio = fetchGroup($conn,
    "SELECT YEAR(fecha_dia) k, COUNT(*) v FROM asistencias $whereSQL
     GROUP BY YEAR(fecha_dia) ORDER BY k ASC", $types, $params);

$tmpDias = fetchGroup($conn,
    "SELECT DAYNAME(fecha_dia) k, COUNT(*) v FROM asistencias $whereSQL
     GROUP BY DAYNAME(fecha_dia), DAYOFWEEK(fecha_dia) ORDER BY DAYOFWEEK(fecha_dia)", $types, $params);
$porDia = [];
foreach (['Lunes','Martes','Miércoles','Jueves','Sábado'] as $dia) {
    foreach ($tmpDias as $diaEN => $cantidad) {
        if (diaES($diaEN) === $dia) $porDia[$dia] = $cantidad;
    }
}
$porDia = array_filter($porDia, fn($v) => $v > 0);

$porCategoria = ['Automóvil'=>0,'Motovehículos'=>0,'Automóvil + Motovehículos'=>0,'Profesional'=>0,'RCP'=>0];
$stmt = $conn->prepare("SELECT licencia FROM asistencias $whereSQL");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $l = strtolower(trim($r['licencia'] ?? ''));
    if      (str_contains($l,'auto')&&str_contains($l,'moto')) $porCategoria['Automóvil + Motovehículos']++;
    elseif  (str_contains($l,'auto'))  $porCategoria['Automóvil']++;
    elseif  (str_contains($l,'moto'))  $porCategoria['Motovehículos']++;
    elseif  (str_contains($l,'prof'))  $porCategoria['Profesional']++;
    elseif  (str_contains($l,'rcp'))   $porCategoria['RCP']++;
}
$stmt->close();
$porCategoria = array_filter($porCategoria, fn($v) => $v > 0);

$mesesNombres = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
                 'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Resumen Estadístico — CeCap</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/variables.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
* { box-sizing: border-box; }
body {
    min-height: 100vh;
    margin: 0;
    font-family: 'Segoe UI', sans-serif;
    background: linear-gradient(135deg, #504254, #3C313F);
    background-attachment: fixed;
    color: #F0EDF2;
    padding: 0 0 48px;
}

/* ── Topbar ── */
.topbar {
    background: rgba(255,255,255,.08);
    backdrop-filter: blur(16px);
    border-bottom: 1px solid rgba(255,255,255,.13);
    padding: 12px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    position: sticky;
    top: 0;
    z-index: 100;
}
.topbar h3 {
    margin: 0;
    font-size: 1.2rem;
    font-weight: 700;
    color: #fff;
    text-shadow: 0 2px 8px rgba(0,0,0,.4);
}

/* ── Filtros ── */
.filtros-bar {
    background: rgba(0,0,0,.25);
    border-bottom: 1px solid rgba(255,255,255,.08);
    padding: 10px 20px;
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.filtros-bar .form-control {
    background: rgba(255,255,255,.12) !important;
    border: 1px solid rgba(255,255,255,.35) !important;
    color: #fff !important;
    border-radius: 10px !important;
    height: 36px;
    font-size: .88rem;
    width: 150px;
    cursor: pointer;
}
.filtros-bar .form-control::placeholder { color: rgba(255,255,255,.6) !important; }
.filtros-bar .form-control:focus {
    border-color: #5D5E70 !important;
    box-shadow: 0 0 0 2px rgba(93,94,112,.2) !important;
    outline: none;
}

/* ── Métricas rápidas ── */
.metricas {
    display: flex;
    gap: 12px;
    justify-content: center;
    flex-wrap: wrap;
    padding: 18px 20px 6px;
}
.metrica-card {
    background: rgba(255,255,255,.09);
    border: 1px solid rgba(255,255,255,.15);
    backdrop-filter: blur(12px);
    border-radius: 16px;
    padding: 14px 22px;
    text-align: center;
    flex: 1;
    min-width: 150px;
    max-width: 210px;
    transition: transform .15s, box-shadow .15s;
}
.metrica-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,.3); }
.metrica-card .mc-label {
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #C7E4CF;
    font-weight: 700;
    margin-bottom: 4px;
}
.metrica-card .mc-valor {
    font-size: 2rem;
    font-weight: 800;
    color: #fff;
    line-height: 1.1;
}
.metrica-card .mc-sub { font-size: .75rem; color: rgba(199,228,207,.55); margin-top: 3px; }
.metrica-card.hoy   { border-color: rgba(93,94,112,.45); }
.metrica-card.mes   { border-color: rgba(99,179,237,.45); }
.metrica-card.turno { border-color: rgba(154,117,234,.45); }
.metrica-card.total-card {
    border-color: rgba(52,211,153,.5);
    cursor: pointer;
    background: rgba(6,78,59,.35);
}
.metrica-card.total-card:hover { box-shadow: 0 8px 28px rgba(52,211,153,.25); }
.metrica-card.total-card .mc-valor { color: #6ee7b7; font-size: 2.2rem; }
.metrica-card.total-card .mc-label { color: #6ee7b7; }

/* ── Panel gráficos ── */
.panel-cristal {
    background: rgba(255,255,255,.04);
    backdrop-filter: blur(8px);
    border-radius: 1.5rem;
    padding: 1.5rem;
    margin: 14px auto;
    max-width: 1200px;
}
.tarjeta {
    background: rgba(70,85,110,.35);
    backdrop-filter: blur(10px);
    border-radius: 1.2rem;
    padding: 1.2rem;
    min-height: 280px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 25px rgba(0,0,0,.25);
    border: 1px solid rgba(255,255,255,.08);
}
.tarjeta h4 {
    text-align: center;
    color: #ffe082;
    font-weight: 700;
    margin-bottom: .6rem;
    font-size: .95rem;
    text-transform: uppercase;
    letter-spacing: .05em;
}
canvas { max-height: 200px !important; max-width: 100%; }

/* ── Modal proyección ── */
.modal-content {
    background: rgba(8,18,32,.97);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255,255,255,.15);
    border-radius: 22px;
    color: #F0EDF2;
}
.modal-header, .modal-footer { border-color: rgba(255,255,255,.10) !important; }
.modal-title { font-weight: 700; }

.proy-card {
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 14px;
    padding: 16px;
    text-align: center;
}
.proy-card .proy-label {
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #C7E4CF;
    font-weight: 700;
    margin-bottom: 6px;
}
.proy-card .proy-val {
    font-size: 2.2rem;
    font-weight: 800;
    color: #6ee7b7;
}
.proy-card .proy-sub { font-size: .78rem; color: rgba(199,228,207,.5); margin-top: 4px; }

.progress-anio {
    height: 8px;
    border-radius: 4px;
    background: rgba(255,255,255,.1);
    overflow: hidden;
    margin-top: 6px;
}
.progress-anio-fill {
    height: 100%;
    border-radius: 4px;
    background: linear-gradient(90deg, #5D5E70, #6ee7b7);
    transition: width 1s ease;
}

/* Flatpickr override */
.flatpickr-calendar { border-radius: 14px !important; }

/* El altInput es el que se ve realmente en pantalla */
.flatpickr-input.flatpickr-mobile,
input.flatpickr-input[readonly] {
    background: rgba(255,255,255,.15) !important;
    border: 1px solid rgba(255,255,255,.45) !important;
    color: #fff !important;
    border-radius: 10px !important;
    height: 36px !important;
    font-size: .88rem !important;
    padding: 0 12px !important;
    width: 150px !important;
    cursor: pointer !important;
}
input.flatpickr-input[readonly]::placeholder {
    color: rgba(255,255,255,.65) !important;
}
input.flatpickr-input[readonly]:focus {
    border-color: #5D5E70 !important;
    box-shadow: 0 0 0 2px rgba(93,94,112,.2) !important;
    outline: none !important;
}
</style>
</head>

<body>

<!-- ── Topbar ── -->
<div class="topbar">
    <a href="panel.php" class="btn btn-sm btn-secondary">← Panel</a>
    <h3>📊 Resumen Estadístico</h3>
    <form action="resumen_pdf.php" method="get" class="m-0">
        <input type="hidden" name="desde" value="<?= htmlspecialchars($desde) ?>">
        <input type="hidden" name="hasta" value="<?= htmlspecialchars($hasta) ?>">
        <button class="btn btn-sm btn-danger">🖨 PDF</button>
    </form>
</div>

<!-- ── Filtros con Flatpickr ── -->
<form class="filtros-bar" method="get" id="formFiltro">
    <input type="text" id="fpDesde" name="desde"
           value="<?= htmlspecialchars($desde) ?>"
           class="form-control" placeholder="Desde" readonly>
    <input type="text" id="fpHasta" name="hasta"
           value="<?= htmlspecialchars($hasta) ?>"
           class="form-control" placeholder="Hasta" readonly>
    <button type="submit" class="btn btn-primary btn-sm px-3">Filtrar</button>
    <a href="resumen.php" class="btn btn-secondary btn-sm px-3">Restablecer</a>
</form>

<!-- ── Métricas rápidas ── -->
<div class="metricas">

    <!-- Total — clickeable → proyección -->
    <div class="metrica-card total-card" onclick="abrirProyeccion()" title="Ver proyección anual">
        <div class="mc-label">📈 Total General</div>
        <div class="mc-valor"><?= number_format($total, 0, ',', '.') ?></div>
        <div class="mc-sub">Clic para proyección →</div>
    </div>

    <div class="metrica-card hoy">
        <div class="mc-label">Hoy</div>
        <div class="mc-valor"><?= number_format($hoy, 0, ',', '.') ?></div>
        <div class="mc-sub"><?= date("d/m/Y") ?></div>
    </div>

    <div class="metrica-card mes">
        <div class="mc-label">Este mes</div>
        <div class="mc-valor"><?= number_format($esteMes, 0, ',', '.') ?></div>
        <div class="mc-sub"><?= $mesesNombres[$mesActual] ?> <?= $anioActual ?></div>
    </div>

    <div class="metrica-card turno">
        <div class="mc-label">Turno más activo</div>
        <div class="mc-valor" style="font-size:1.1rem;padding-top:2px">
            <?= htmlspecialchars($turnoTopCorto) ?>
        </div>
        <div class="mc-sub"><?= number_format($turnoTopCant, 0, ',', '.') ?> asistentes</div>
    </div>

</div>

<!-- ── Gráficos ── -->
<div class="container px-3">
<div class="panel-cristal">
<div class="row g-4 justify-content-center">

    <div class="col-md-4"><div class="tarjeta">
        <h4>Por Año</h4>
        <?php if (!empty($porAnio)): ?>
        <canvas id="anio"></canvas>
        <?php else: ?><p class="text-muted small">Sin datos</p><?php endif; ?>
    </div></div>

    <div class="col-md-4"><div class="tarjeta">
        <h4>Género</h4>
        <?php if (!empty($porGenero)): ?>
        <canvas id="genero"></canvas>
        <?php else: ?><p class="text-muted small">Sin datos</p><?php endif; ?>
    </div></div>

    <div class="col-md-4"><div class="tarjeta">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h4 class="mb-0">Lugar</h4>
            <?php if (!empty($porEscuela)): ?>
            <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-outline-warning btn-sm py-0 px-2 active" id="btnLugarGeneral" onclick="cambiarVistaLugar('general')">General</button>
                <button type="button" class="btn btn-outline-warning btn-sm py-0 px-2" id="btnLugarEscuelas" onclick="cambiarVistaLugar('escuelas')" title="Discriminar cada escuela con sus valores">🏫 Escuelas</button>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($porLugar)): ?>
        <canvas id="lugar"></canvas>

        <?php if (!empty($porEscuela)): ?>
        <div id="detalleEscuelasCard" class="mt-2 pt-2 border-top border-secondary border-opacity-25" style="display:none;">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="fw-bold text-warning" style="font-size:0.8rem;">🏫 Detalle Escuelas</span>
                <span class="badge bg-warning text-dark" style="font-size:0.75rem;">Total: <?= number_format($totalEscuelas, 0, ',', '.') ?></span>
            </div>
            <div style="max-height:130px; overflow-y:auto; font-size:0.75rem;">
                <?php foreach ($porEscuela as $escNom => $escCant):
                    $pct = $totalEscuelas > 0 ? round(($escCant / $totalEscuelas) * 100, 1) : 0;
                ?>
                <div class="d-flex justify-content-between align-items-center py-1 border-bottom border-light border-opacity-10">
                    <span class="text-truncate text-light" style="max-width:180px;" title="<?= htmlspecialchars($escNom) ?>"><?= htmlspecialchars($escNom) ?></span>
                    <div>
                        <span class="text-white-50 me-1">(<?= $pct ?>%)</span>
                        <span class="badge bg-secondary"><?= number_format($escCant, 0, ',', '.') ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php else: ?><p class="text-muted small">Sin datos</p><?php endif; ?>
    </div></div>

    <div class="col-md-4"><div class="tarjeta">
        <h4>Categorías</h4>
        <?php if (!empty($porCategoria)): ?>
        <canvas id="categoria"></canvas>
        <?php else: ?><p class="text-muted small">Sin datos</p><?php endif; ?>
    </div></div>

    <div class="col-md-4"><div class="tarjeta">
        <h4>Días</h4>
        <?php if (!empty($porDia)): ?>
        <canvas id="dia"></canvas>
        <?php else: ?><p class="text-muted small">Sin datos</p><?php endif; ?>
    </div></div>

    <div class="col-md-4"><div class="tarjeta">
        <h4>Turno</h4>
        <?php if (!empty($porTurno)): ?>
        <canvas id="turno"></canvas>
        <?php else: ?><p class="text-muted small">Sin datos</p><?php endif; ?>
    </div></div>

</div>
</div>
</div>

<!-- ══════════════════════════════════════════
     MODAL PROYECCIÓN
══════════════════════════════════════════ -->
<div class="modal fade" id="modalProyeccion" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content">

    <div class="modal-header">
        <h5 class="modal-title">📈 Proyección <?= $anioActual ?></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>

    <div class="modal-body">

        <!-- Progreso del año -->
        <div class="mb-4">
            <div class="d-flex justify-content-between mb-1" style="font-size:.8rem;color:#C7E4CF">
                <span>Año transcurrido</span>
                <span><?= $pctAnio ?>% — día <?= $diaDelAnio ?> de <?= $diasEnAnio ?></span>
            </div>
            <div class="progress-anio">
                <div class="progress-anio-fill" style="width:<?= $pctAnio ?>%"></div>
            </div>
        </div>

        <!-- Cards de proyección -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="proy-card">
                    <div class="proy-label">Acumulado <?= $anioActual ?></div>
                    <div class="proy-val"><?= number_format($totalAnioActual, 0, ',', '.') ?></div>
                    <div class="proy-sub">hasta hoy</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="proy-card">
                    <div class="proy-label">Promedio mensual</div>
                    <div class="proy-val"><?= number_format($promedioMensual, 0, ',', '.') ?></div>
                    <div class="proy-sub">últimos meses completos</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="proy-card" style="border-color:rgba(93,94,112,.4)">
                    <div class="proy-label">Este mes (proy.)</div>
                    <div class="proy-val" style="color:#5D5E70">
                        <?= number_format($proyeccionMesActual, 0, ',', '.') ?>
                    </div>
                    <div class="proy-sub">
                        <?= $mesesNombres[$mesActual] ?> · día <?= $diaActual ?>/<?= $diasEnMes ?>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="proy-card" style="border-color:rgba(52,211,153,.5);background:rgba(6,78,59,.35)">
                    <div class="proy-label" style="color:#6ee7b7">Proyección fin de año</div>
                    <div class="proy-val"><?= number_format($proyeccionAnual, 0, ',', '.') ?></div>
                    <div class="proy-sub">
                        +<?= number_format($proyeccionAnual - $totalAnioActual, 0, ',', '.') ?> estimados
                        · <?= $mesesRestantes ?> mes<?= $mesesRestantes !== 1 ? 'es' : '' ?> restante<?= $mesesRestantes !== 1 ? 's' : '' ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Nota metodológica -->
        <div class="mb-4 px-1" style="font-size:.75rem;color:rgba(199,228,207,.45)">
            * Proyección calculada en base al promedio de meses completos del año en curso
            (o del año anterior si no hay suficientes datos).
            El mes actual se pondera proporcionalmente al día transcurrido.
        </div>

        <!-- Gráfico tendencia mes a mes -->
        <div style="background:rgba(255,255,255,.04);border-radius:14px;padding:16px">
            <div style="font-size:.8rem;color:#C7E4CF;font-weight:700;text-transform:uppercase;
                        letter-spacing:.05em;margin-bottom:10px">
                Tendencia mensual — últimos 24 meses
            </div>
            <canvas id="chartTendencia" style="max-height:220px !important"></canvas>
        </div>

    </div>

    <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
    </div>

</div>
</div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>

<script>
/* ── Flatpickr ── */
const fpConfig = {
    locale: "es",
    dateFormat: "Y-m-d",
    altInput: true,
    altFormat: "d/m/Y",
    allowInput: false,
};
flatpickr("#fpDesde", { ...fpConfig,
    defaultDate: <?= $desde ? '"'.$desde.'"' : 'null' ?>,
    onChange: function(sel, str) {
        fpHasta.set('minDate', str);
    }
});
const fpHasta = flatpickr("#fpHasta", { ...fpConfig,
    defaultDate: <?= $hasta ? '"'.$hasta.'"' : 'null' ?>,
    onChange: function(sel, str) {
        fpDesde.set('maxDate', str);
    }
});
const fpDesde = flatpickr("#fpDesde", { ...fpConfig,
    defaultDate: <?= $desde ? '"'.$desde.'"' : 'null' ?>,
});

/* ── Gráficos principales ── */
const colores = [
    'rgba(48,142,219,.75)','rgba(255,160,80,.75)','rgba(100,220,160,.75)',
    'rgba(220,100,180,.75)','rgba(160,130,220,.75)','rgba(255,220,60,.75)',
    'rgba(80,200,220,.75)',
];
function makeBar(id, data) {
    const keys = Object.keys(data);
    const vals = Object.values(data);
    new Chart(document.getElementById(id), {
        type: 'bar',
        data: {
            labels: keys,
            datasets: [{
                data: vals,
                backgroundColor: keys.map((_,i) => colores[i % colores.length]),
                borderColor: 'rgba(255,255,255,.2)',
                borderWidth: 1,
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ` ${ctx.parsed.y.toLocaleString('es-AR')}` } }
            },
            scales: {
                x: { ticks: { color:'#C7E4CF', font:{size:11} }, grid:{color:'rgba(255,255,255,.05)'} },
                y: { ticks: { color:'#C7E4CF' }, grid:{color:'rgba(255,255,255,.05)'}, beginAtZero:true }
            }
        }
    });
}

<?php if (!empty($porAnio)):     ?> makeBar('anio',      <?= json_encode($porAnio,      JSON_UNESCAPED_UNICODE) ?>); <?php endif; ?>
<?php if (!empty($porGenero)):   ?> makeBar('genero',    <?= json_encode($porGenero,    JSON_UNESCAPED_UNICODE) ?>); <?php endif; ?>
const datosLugarGeneral      = <?= json_encode($porLugar, JSON_UNESCAPED_UNICODE) ?>;
const datosLugarDiscriminado = <?= json_encode($porLugarDiscriminado, JSON_UNESCAPED_UNICODE) ?>;
let chartLugarInstance = null;

function renderChartLugar(data) {
    if (chartLugarInstance) {
        chartLugarInstance.destroy();
    }
    const keys = Object.keys(data);
    const vals = Object.values(data);
    chartLugarInstance = new Chart(document.getElementById('lugar'), {
        type: 'bar',
        data: {
            labels: keys,
            datasets: [{
                data: vals,
                backgroundColor: keys.map((_, i) => colores[i % colores.length]),
                borderColor: 'rgba(255,255,255,.2)',
                borderWidth: 1,
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ` ${ctx.parsed.y.toLocaleString('es-AR')}` } }
            },
            scales: {
                x: { ticks: { color: '#C7E4CF', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,.05)' } },
                y: { ticks: { color: '#C7E4CF' }, grid: { color: 'rgba(255,255,255,.05)' }, beginAtZero: true }
            }
        }
    });
}

function cambiarVistaLugar(modo) {
    const btnGen = document.getElementById('btnLugarGeneral');
    const btnDet = document.getElementById('btnLugarEscuelas');
    const boxDet = document.getElementById('detalleEscuelasCard');

    if (modo === 'escuelas') {
        btnGen?.classList.remove('active');
        btnDet?.classList.add('active');
        if (boxDet) boxDet.style.display = 'block';
        renderChartLugar(datosLugarDiscriminado);
    } else {
        btnDet?.classList.remove('active');
        btnGen?.classList.add('active');
        if (boxDet) boxDet.style.display = 'none';
        renderChartLugar(datosLugarGeneral);
    }
}

<?php if (!empty($porLugar)): ?>
renderChartLugar(datosLugarGeneral);
<?php endif; ?>
<?php if (!empty($porCategoria)):?> makeBar('categoria', <?= json_encode($porCategoria, JSON_UNESCAPED_UNICODE) ?>); <?php endif; ?>
<?php if (!empty($porDia)):      ?> makeBar('dia',       <?= json_encode($porDia,       JSON_UNESCAPED_UNICODE) ?>); <?php endif; ?>
<?php if (!empty($porTurno)):    ?> makeBar('turno',     <?= json_encode($porTurno,     JSON_UNESCAPED_UNICODE) ?>); <?php endif; ?>

/* ── Modal proyección ── */
let chartTendencia = null;
const bsModal = new bootstrap.Modal(document.getElementById('modalProyeccion'));

function abrirProyeccion() {
    bsModal.show();
}

document.getElementById('modalProyeccion').addEventListener('shown.bs.modal', () => {
    if (chartTendencia) { chartTendencia.destroy(); chartTendencia = null; }

    const labels = <?= json_encode($labelesMeses, JSON_UNESCAPED_UNICODE) ?>;
    const valores = <?= json_encode($valoresMeses) ?>;
    const pctAnio = <?= $pctAnio ?>;

    // Línea de tendencia simple (regresión lineal)
    const n = valores.length;
    let sumX=0, sumY=0, sumXY=0, sumX2=0;
    valores.forEach((y,x) => { sumX+=x; sumY+=y; sumXY+=x*y; sumX2+=x*x; });
    const slope  = n ? (n*sumXY - sumX*sumY) / (n*sumX2 - sumX*sumX) : 0;
    const interc = n ? (sumY - slope*sumX) / n : 0;
    const tendencia = valores.map((_,x) => Math.round(interc + slope*x));

    chartTendencia = new Chart(document.getElementById('chartTendencia'), {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'Asistentes',
                    data: valores,
                    borderColor: '#5D5E70',
                    backgroundColor: 'rgba(93,94,112,.12)',
                    borderWidth: 2,
                    pointRadius: 4,
                    pointBackgroundColor: '#5D5E70',
                    fill: true,
                    tension: .35,
                },
                {
                    label: 'Tendencia',
                    data: tendencia,
                    borderColor: 'rgba(110,231,183,.6)',
                    borderWidth: 2,
                    borderDash: [6,4],
                    pointRadius: 0,
                    fill: false,
                    tension: 0,
                }
            ]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    labels: { color: '#C7E4CF', font: { size: 11 } }
                },
                tooltip: {
                    callbacks: {
                        label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.y.toLocaleString('es-AR')}`
                    }
                }
            },
            scales: {
                x: { ticks:{color:'#C7E4CF',font:{size:10},maxRotation:45}, grid:{color:'rgba(255,255,255,.05)'} },
                y: { ticks:{color:'#C7E4CF'}, grid:{color:'rgba(255,255,255,.05)'}, beginAtZero:true }
            }
        }
    });
});
</script>

</body>
</html>