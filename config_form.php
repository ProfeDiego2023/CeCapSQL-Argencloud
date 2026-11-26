<?php
session_start();
if (!isset($_SESSION['id_instru'])) { header("Location: login.php"); exit(); }

// (opcional) restringir solo admin:
// if (empty($_SESSION['permiso']['admin'])) { die("No autorizado"); }

require_once __DIR__ . "/conexion.php";

$msg = "";

// leer config (tomamos la última fila)
$cfg = [
  "activo" => 0, "lugar_override" => "", "licencia_override" => "", "turno_override" => "", "motivo" => ""
];
$res = $conn->query("SELECT * FROM config_formulario ORDER BY id DESC LIMIT 1");
if ($res && $res->num_rows > 0) $cfg = $res->fetch_assoc();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $activo = !empty($_POST["activo"]) ? 1 : 0;

  $lugar   = trim($_POST["lugar_override"] ?? "");
  $licencia= trim($_POST["licencia_override"] ?? "");
  $turno   = trim($_POST["turno_override"] ?? "");
  $motivo  = trim($_POST["motivo"] ?? "");

  // si activo=1, obligamos valores
  if ($activo === 1) {
    if ($lugar === "" || $licencia === "" || $turno === "") {
      $msg = "<div class='alert alert-danger'>Completá Lugar, Licencia y Turno para activar modo manual.</div>";
    }
  }

  if ($msg === "") {
    $st = $conn->prepare("
      INSERT INTO config_formulario (activo, lugar_override, licencia_override, turno_override, motivo)
      VALUES (?, ?, ?, ?, ?)
    ");
    $st->bind_param("issss", $activo, $lugar, $licencia, $turno, $motivo);

    if ($st->execute()) {
      $msg = "<div class='alert alert-success'>Configuración guardada.</div>";
      // recargar cfg
      $res = $conn->query("SELECT * FROM config_formulario ORDER BY id DESC LIMIT 1");
      if ($res && $res->num_rows > 0) $cfg = $res->fetch_assoc();
    } else {
      $msg = "<div class='alert alert-danger'>Error: ".$st->error."</div>";
    }
    $st->close();
  }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Configurar Formulario</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/variables.css">
<style>
body{
  min-height:100vh;
  margin:0;
  background: linear-gradient(135deg, #504254, #3C313F);
  background-attachment: fixed;
  color:#fff; display:flex; align-items:center; justify-content:center; padding:18px;
}
.cardx{
  width:100%; max-width:640px;
  background: rgba(10,18,32,.78);
  border: 1px solid rgba(255,255,255,.14);
  border-radius: 22px;
  backdrop-filter: blur(14px);
  padding: 22px;
  box-shadow: 0 18px 55px rgba(0,0,0,0.60);
}
.form-control, .form-select{
  background: rgba(0,0,0,.35) !important;
  border: 1px solid rgba(255,255,255,.14) !important;
  color:#fff !important;
}
.form-control::placeholder{ color: rgba(255,255,255,.55) !important; }
</style>
</head>
<body>

<div class="cardx">
  <h3 class="mb-2">🛠 Configurar Formulario (Modo Manual)</h3>
  <p class="mb-3" style="opacity:.8">
    Si activás modo manual, el formulario usará estos valores en vez de los automáticos por día/hora.
  </p>

  <?= $msg ?>

  <form method="post">
    <div class="form-check form-switch mb-3">
      <input class="form-check-input" type="checkbox" id="activo" name="activo" <?= !empty($cfg["activo"]) ? "checked" : "" ?>>
      <label class="form-check-label" for="activo"><b>Activar Modo Manual</b></label>
    </div>

    <div class="row g-3">
      <div class="col-12 col-md-6">
        <label class="form-label">Lugar (override)</label>
        <input class="form-control" name="lugar_override" placeholder="Ej: SALÓN VECINAL / ESCUELA..." value="<?= htmlspecialchars($cfg["lugar_override"] ?? "", ENT_QUOTES,'UTF-8') ?>">
      </div>

      <div class="col-12 col-md-6">
        <label class="form-label">Turno (override)</label>
        <input class="form-control" name="turno_override" placeholder="Ej: 09:00 / 15:00 / 1° TURNO..." value="<?= htmlspecialchars($cfg["turno_override"] ?? "", ENT_QUOTES,'UTF-8') ?>">
      </div>

      <div class="col-12">
        <label class="form-label">Licencia (override)</label>
        <input class="form-control" name="licencia_override" placeholder="Ej: AUTOMÓVIL / MOTOVEHÍCULO / AMBAS..." value="<?= htmlspecialchars($cfg["licencia_override"] ?? "", ENT_QUOTES,'UTF-8') ?>">
      </div>

      <div class="col-12">
        <label class="form-label">Motivo / Nota</label>
        <input class="form-control" name="motivo" placeholder="Ej: capacitación especial fuera de cronograma" value="<?= htmlspecialchars($cfg["motivo"] ?? "", ENT_QUOTES,'UTF-8') ?>">
      </div>
    </div>

    <div class="d-flex gap-2 mt-4">
      <button class="btn btn-primary w-100">Guardar</button>
      <a href="panel.php" class="btn btn-outline-light w-100">Volver</a>
    </div>
  </form>

  <div class="mt-3" style="opacity:.75; font-size:.9rem">
    Última actualización: <b><?= htmlspecialchars($cfg["actualizado_en"] ?? "-", ENT_QUOTES,'UTF-8') ?></b>
  </div>
</div>

</body>
</html>
