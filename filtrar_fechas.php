<?php
session_start();
require_once __DIR__ . "/auth.php";

if (!permiso("certificados")) {
    die("No autorizado");
}

$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';
$turno = $_GET['turno'] ?? 'TODOS';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Filtrar por Fechas - Certificados</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/variables.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">
<style>
* { box-sizing: border-box; }
body {
    background: linear-gradient(135deg, #504254, #3C313F);
    background-attachment: fixed;
    font-family: 'Segoe UI', sans-serif;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    color: #F0EDF2;
    margin: 0;
    padding: 20px;
}
.card-fechas {
    background: rgba(255,255,255,.12);
    backdrop-filter: blur(16px);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 22px;
    padding: 32px 36px;
    box-shadow: 0 20px 50px rgba(0,0,0,.55);
    width: 100%;
    max-width: 420px;
    animation: fadeInUp .6s ease;
}
@keyframes fadeInUp {
    from { opacity:0; transform:translateY(24px); }
    to   { opacity:1; transform:translateY(0); }
}
h4 {
    color: #fff;
    font-weight: 800;
    margin-bottom: 24px;
    text-align: center;
    text-shadow: 0 2px 10px rgba(0,0,0,.5);
}
.form-label { font-weight: 600; color: #C7E4CF; font-size: .88rem; }
.form-control,
.form-select,
input.flatpickr-input[readonly] {
    background: rgba(255,255,255,.15) !important;
    border: 1px solid rgba(255,255,255,.30) !important;
    color: #F0EDF2 !important;
    border-radius: 12px !important;
    font-weight: 600;
    height: 44px;
    padding: 0 14px;
    width: 100%;
}
.form-control:focus,
.form-select:focus,
input.flatpickr-input[readonly]:focus {
    background: rgba(255,255,255,.25) !important;
    border-color: #5D5E70 !important;
    box-shadow: 0 0 0 2px rgba(93,94,112,.35) !important;
    outline: none !important;
}
input.flatpickr-input[readonly] { cursor: pointer; display: block; }
input.flatpickr-input[readonly]::placeholder { color: rgba(199,228,207,.55) !important; }
.form-select option { background: #1b2a3a; color: #fff; }
.btn-continuar {
    background: linear-gradient(135deg, #DD4841, #B03030);
    border: none; border-radius: 14px; color: #fff;
    font-weight: 700; padding: 12px; width: 100%;
    font-size: 1rem; transition: .25s; cursor: pointer;
}
.btn-continuar:hover { transform: translateY(-2px); filter: brightness(1.12); }
.btn-volver {
    display: block; width: 100%; text-align: center;
    background: transparent; border: 2px solid rgba(93,94,112,.6);
    color: #a4a4ac; font-weight: 700; border-radius: 14px;
    padding: 10px; margin-top: 12px; text-decoration: none; transition: .25s;
}
.btn-volver:hover { background: rgba(93,94,112,.15); color: #fff; transform: translateY(-2px); }
.flatpickr-calendar { border-radius: 14px !important; }
</style>
</head>
<body>
<div class="card-fechas">
    <h4>&#128197; Filtrar Certificados</h4>
    <form action="filtrar_fechas_listado.php" method="GET">
        <div class="mb-3">
            <label class="form-label">Desde</label>
            <input type="text" id="fpDesde" name="desde" class="form-control"
                   placeholder="Seleccionar fecha"
                   value="<?= htmlspecialchars($desde) ?>" readonly required>
        </div>
        <div class="mb-3">
            <label class="form-label">Hasta</label>
            <input type="text" id="fpHasta" name="hasta" class="form-control"
                   placeholder="Seleccionar fecha"
                   value="<?= htmlspecialchars($hasta) ?>" readonly required>
        </div>
        <div class="mb-4">
            <label class="form-label">Turno</label>
            <select name="turno" class="form-select">
                <option value="TODOS" <?= $turno === 'TODOS' ? 'selected' : '' ?>>Todos los turnos</option>
                <option value="1"     <?= $turno === '1'     ? 'selected' : '' ?>>1&#176; Turno &mdash; 13:30</option>
                <option value="2"     <?= $turno === '2'     ? 'selected' : '' ?>>2&#176; Turno &mdash; 20:00</option>
                <option value="3"     <?= $turno === '3'     ? 'selected' : '' ?>>3&#176; Turno &mdash; 8:30</option>
            </select>
        </div>
        <button type="submit" class="btn-continuar">Continuar &#8594;</button>
    </form>
    <a href="panel.php" class="btn-volver">&#8592; Volver al Panel</a>
</div>
<?php include __DIR__ . "/footer.php"; ?>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
<script>
const fpCfg = { locale:"es", dateFormat:"Y-m-d", allowInput:false };
const fpD = flatpickr("#fpDesde", {
    ...fpCfg,
    defaultDate: <?= $desde ? '"'.$desde.'"' : 'null' ?>,
    onChange: (s,str) => fpH.set('minDate', str),
});
const fpH = flatpickr("#fpHasta", {
    ...fpCfg,
    defaultDate: <?= $hasta ? '"'.$hasta.'"' : 'null' ?>,
    onChange: (s,str) => fpD.set('maxDate', str),
});
</script>
</body>
</html>