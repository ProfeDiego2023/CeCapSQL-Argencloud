<?php
include("conexionform.php");

$mensaje = "";

// --- Zona horaria ---
date_default_timezone_set('America/Argentina/Buenos_Aires');

// --- Fecha y día actual ---
$diaSemana = date("N");  // 1=Lun ... 7=Dom
$fechaHoy  = date("Y-m-d");

// --- Lugar automático ---
$lugar = "OTRO";
if (in_array($diaSemana, [1,3,4])) $lugar = "PUNTO DIGITAL";
if ($diaSemana == 2) $lugar = "NIDO";

// --- Licencia automática según día ---
switch ($diaSemana) {
    case 1:
    case 4:
        $licencia = "MOTOVEHÍCULO";
        break;
    case 2:
        $licencia = "AUTOMÓVIL + MOTOVEHÍCULO";
        break;
    case 3:
        $licencia = "AUTOMÓVIL";
        break;
    default:
        $licencia = "SELECCIONAR…";
}

// --- Turno automático ---
$horaActual = date("H:i");
$turno = ($horaActual <= "17:00") ? "1° TURNO 13:30" : "2° TURNO 20:00";

/// ===== OVERRIDES (EVENTO por fecha/hora y MANUAL) =====
$override_evento = false;
$override_manual = false;
$evento_motivo = "";

// --- 1) Evento especial por fecha/hora (PRIORIDAD ALTA) ---
$hoy   = date("Y-m-d");
$ahora = date("H:i:s");

$sqlEvt = "
SELECT lugar, licencia, turno, motivo
FROM config_form_eventos
WHERE activo = 1
  AND ? BETWEEN fecha_desde AND fecha_hasta
  AND (
        (hora_desde IS NULL AND hora_hasta IS NULL)
        OR
        (? BETWEEN hora_desde AND hora_hasta)
      )
ORDER BY actualizado_en DESC, id DESC
LIMIT 1
";

if ($stEvt = $conn->prepare($sqlEvt)) {
    $stEvt->bind_param("ss", $hoy, $ahora);
    $stEvt->execute();
    $stEvt->store_result();

    if ($stEvt->num_rows > 0) {
    $stEvt->bind_result($evt_lugar, $evt_licencia, $evt_turno, $evt_motivo);

    if ($stEvt->fetch()) {
        $override_evento = true;

        $lugar    = strtoupper((string)$evt_lugar);
        $licencia = strtoupper((string)$evt_licencia);
        $turno    = strtoupper((string)$evt_turno);

        $evento_motivo = (string)($evt_motivo ?? "");
    }
}


    $stEvt->close();
}

// --- 2) Override manual (solo si NO hay evento activo) ---
if (!$override_evento) {
    $sqlMan = "SELECT activo, lugar_override, licencia_override, turno_override
               FROM config_formulario ORDER BY id DESC LIMIT 1";

    if ($resMan = $conn->query($sqlMan)) {
        if ($resMan->num_rows > 0) {
            $c = $resMan->fetch_assoc();
            if (!empty($c["activo"])) {
                $override_manual = true;

                if (!empty($c["lugar_override"]))    $lugar    = strtoupper($c["lugar_override"]);
                if (!empty($c["licencia_override"])) $licencia = strtoupper($c["licencia_override"]);
                if (!empty($c["turno_override"]))    $turno    = strtoupper($c["turno_override"]);
            }
        }
    }
}



// --- Guardar datos ---
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $fecha     = date("Y-m-d H:i:s");
    $apellidos = strtoupper(trim($_POST["apellidos"] ?? ""));
    $nombres   = strtoupper(trim($_POST["nombres"] ?? ""));
    $dni       = trim($_POST["dni"] ?? "");
    $edad      = trim($_POST["edad"] ?? "");
    $genero    = trim($_POST["genero"] ?? "");
    $email     = trim($_POST["email"] ?? "");

    // --- VALIDACIÓN: evitar duplicado del mismo día ---
    $stmtVal = $conn->prepare("
        SELECT id FROM asistencias
        WHERE dni = ? AND DATE(fecha) = ?
        LIMIT 1
    ");
    $stmtVal->bind_param("ss", $dni, $fechaHoy);
    $stmtVal->execute();
    $stmtVal->store_result();

    if ($stmtVal->num_rows > 0) {
        $mensaje = "
        <div class='alert alert-warning text-center mt-3 mb-0'>
            ⚠️ El DNI <strong>$dni</strong> ya registró asistencia hoy.
        </div>";
    } else {

        $stmt = $conn->prepare("
            INSERT INTO asistencias 
            (fecha, apellidos, nombres, dni, edad, genero, lugar, email, licencia, turno)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param("ssssisssss",
            $fecha, $apellidos, $nombres, $dni, $edad,
            $genero, $lugar, $email, $licencia, $turno
        );

        if ($stmt->execute()) {
            $mensaje = "
            <div class='alert alert-success text-center mt-3 mb-0'>
                ✔ Registro guardado correctamente
            </div>";
        } else {
            $mensaje = "
            <div class='alert alert-danger text-center mt-3 mb-0'>
                ❌ Error al guardar: " . $stmt->error . "
            </div>";
        }
        $stmt->close();
    }

    $stmtVal->close();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Registro de Asistencia</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/variables.css">

<style>
:root{
  --glass: rgba(10, 18, 32, 0.78);
  --glass2: rgba(10, 18, 32, 0.62);
  --border: rgba(255,255,255,0.14);
  --text: #F0EDF2;
  --muted: rgba(199,228,207,0.75);
  --accent1: #DD4841;
  --accent2: #B03030;
  --warn: #FFD166;
}

*{ box-sizing:border-box; }

body{
  min-height: 100vh;
  margin: 0;
  padding: 24px 14px;
  display:flex;
  align-items:center;
  justify-content:center;
  color: var(--text);
  font-family: 'Segoe UI', sans-serif;

  background: linear-gradient(135deg, #504254, #3C313F);
  background-attachment: fixed;
}

/* CARD PRINCIPAL */
.form-card{
  width: 100%;
  max-width: 520px;
  padding: 20px 18px 18px;
  border-radius: 22px;
  background: var(--glass);
  border: 1px solid var(--border);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  box-shadow: 0 18px 55px rgba(0,0,0,0.60);
}

/* HEADER */
.header-premium{
  width:100%;
  text-align:center;
  padding: 16px 12px 14px;
  margin-bottom: 14px;

  background: linear-gradient(135deg, rgba(255,255,255,0.10), rgba(255,255,255,0.04));
  border: 1px solid rgba(255,255,255,0.16);
  border-radius: 18px;
  box-shadow: 0 12px 28px rgba(0,0,0,0.45);

  display:flex;
  flex-direction: column;
  align-items:center;
  gap: 6px;

  animation: fadeDown 0.7s ease-out;
}

.header-logo{
  width: 280px;
  max-width: 92%;
  display:block;
  filter: drop-shadow(0 6px 16px rgba(255,140,0,0.55));
  transition: transform .25s ease, filter .25s ease;
}
.header-logo:hover{
  transform: scale(1.04);
  filter: drop-shadow(0 10px 22px rgba(255,140,0,0.90));
}

.header-title{
  margin: 2px 0 0;
  color: #fff;
  font-size: 1.22rem;
  font-weight: 800;
  letter-spacing: .3px;
  text-shadow: 0 2px 5px rgba(0,0,0,0.55);
}

/* Línea luminosa animada */
.header-line{
  width: 78%;
  height: 4px;
  border-radius: 10px;
  background: linear-gradient(90deg, #ff7a00, #ffbb00, #ffaa00, #ff7a00);
  background-size: 300% 100%;
  animation: lineMove 3s linear infinite;
  box-shadow: 0 0 12px rgba(255,160,0,0.85);
  margin-top: 6px;
}

/* TARJETAS INFO */
.info-card{
  background: var(--glass2);
  border: 1px solid rgba(255,255,255,0.12);
  border-radius: 14px;
  padding: 12px 12px;
  color: var(--text);
  margin-top: 10px;
  text-align: center;
  font-size: 0.98rem;
  font-weight: 700;
  box-shadow: 0 10px 24px rgba(0,0,0,0.28);
}
.info-card b{ color: #C7E4CF; }

/* FORM */
label{
  font-size: 0.98rem;
  font-weight: 700;
  color: #C7E4CF;
  margin-bottom: 6px;
}

.form-control, .form-select{
  border-radius: 12px !important;
  padding: 14px !important;
  font-size: 1rem !important;

  background: rgba(0,0,0,0.35) !important;
  border: 1px solid rgba(255,255,255,0.14) !important;
  color: #fff !important;

  box-shadow: inset 0 1px 0 rgba(255,255,255,0.06);
}

.form-control::placeholder{
  color: rgba(255,255,255,0.55) !important;
}

.form-control:focus, .form-select:focus{
  border-color: rgba(221,72,65,0.65) !important;
  box-shadow: 0 0 0 .25rem rgba(221,72,65,0.18) !important;
}

input[type="email"]{
  text-transform: none !important;
}

/* botón */
.btn-modern{
  margin-top: 14px;
  width: 100%;
  padding: 15px;
  border: none;
  border-radius: 14px;
  color: #fff;
  font-size: 1.15rem;
  font-weight: 900;
  letter-spacing: .2px;

  background: linear-gradient(135deg, var(--accent1), var(--accent2));
  box-shadow: 0 14px 28px rgba(221,72,65,0.35);
  transition: transform .12s ease, box-shadow .18s ease, filter .18s ease;
}
.btn-modern:hover{
  transform: translateY(-1px);
  filter: brightness(1.03);
  box-shadow: 0 18px 34px rgba(221,72,65,0.55);
}

/* Animaciones */
@keyframes lineMove {
  0% { background-position: 0% 0%; }
  100% { background-position: 300% 0%; }
}
@keyframes fadeDown {
  from { opacity: 0; transform: translateY(-12px); }
  to { opacity: 1; transform: translateY(0); }
}

@media (max-width: 420px){
  .form-card{ padding: 18px 14px 14px; border-radius: 18px; }
  .header-title{ font-size: 1.1rem; }
  .header-logo{ width: 250px; }
}
</style>
</head>
<body>

<div class="form-card">

  <div class="header-premium">
    <img src="img/LogoCapVialNaranja.png" class="header-logo" alt="Logo">
    <h2 class="header-title">📋 Registro de Asistencia</h2>
    <div class="header-line"></div>
  </div>

        <?php if ($override_evento): ?>
    <div class="alert alert-info text-center mb-3">
        🛠 CAPACITACIÓN ESPECIAL ACTIVA<?= $evento_motivo ? " • ".htmlspecialchars($evento_motivo, ENT_QUOTES,'UTF-8') : "" ?>
    </div>
    <?php elseif ($override_manual): ?>
    <div class="alert alert-info text-center mb-3">
        🛠 MODO MANUAL ACTIVADO (configurado desde el panel)
    </div>
    <?php endif; ?>

  <?= $mensaje ?>

  <div class="info-card">📍 <b>Lugar de Charla:</b> <?= $lugar ?></div>
  <div class="info-card">🏍️ <b>Licencia Tipo:</b> <?= $licencia ?></div>
  <div class="info-card">🕒 <b>Turno asignado:</b> <?= $turno ?></div>

  <form method="post" class="mt-3">

    <div class="mb-3">
      <label>Apellidos</label>
      <input type="text" name="apellidos" class="form-control" required placeholder="Ej: PÉREZ">
    </div>

    <div class="mb-3">
      <label>Nombres</label>
      <input type="text" name="nombres" class="form-control" required placeholder="Ej: JUAN CARLOS">
    </div>

    <div class="mb-3">
      <label>DNI</label>
      <input type="number" name="dni" class="form-control" required placeholder="Ej: 30111222">
    </div>

    <div class="mb-3">
      <label>Edad</label>
      <input type="number" name="edad" class="form-control" required placeholder="Ej: 32">
    </div>

    <div class="mb-3">
      <label>Género</label>
      <select name="genero" class="form-select" required>
        <option value="">SELECCIONAR…</option>
        <option>MASCULINO</option>
        <option>FEMENINO</option>
        <option>OTRO</option>
      </select>
    </div>

    <div class="mb-3">
      <label>Email</label>
      <input type="email" name="email" class="form-control" required placeholder="ejemplo@correo.com">
    </div>

    <button class="btn-modern">Registrar Asistencia</button>
  </form>

</div>

</body>
</html>
