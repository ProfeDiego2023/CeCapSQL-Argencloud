<?php
session_start();

require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/conexion.php";

proteger();

if (!permiso("editar")) {
    die("Acceso denegado");
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0) {
    die("ID inválido");
}

/* ==============================
   LISTAS
================================ */
$licencias = [
    'AUTOMÓVIL',
    'MOTOVEHÍCULO',
    'AUTOMÓVIL + MOTOVEHÍCULO',
    'PROFESIONAL',
    'RCP'
];

$turnos = [
    '1° TURNO 13:30',
    '2° TURNO 20:00',
    '3° TURNO 8:30',
    'ESPECIAL 8:00'
];

/* ==============================
   NORMALIZADOR UTF-8
================================ */
function normalizar(string $txt): string {
    return trim($txt);
}

/* ==============================
   GUARDAR
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $apellidos = normalizar($_POST['apellidos'] ?? '');
    $nombres   = normalizar($_POST['nombres'] ?? '');
    $dni       = preg_replace('/\D/', '', $_POST['dni'] ?? '');
    $edad      = (int)($_POST['edad'] ?? 0);
    $genero    = normalizar($_POST['genero'] ?? '');
    $email     = strtolower(trim($_POST['email'] ?? ''));
    $licencia = trim($_POST['licencia'] ?? '');
    $lugar     = normalizar($_POST['lugar'] ?? '');
    $turno    = trim($_POST['turno'] ?? '');
    $fecha_dia = trim($_POST['fecha_dia'] ?? '');

    if ($dni === '' || $fecha_dia === '') {

        $_SESSION['edit_error'] = "DNI y fecha son obligatorios.";

        header("Location: editar_asistentes.php?id=$id");
        exit;
    }

    if (!in_array($licencia, $licencias, true)) {

        $_SESSION['edit_error'] = "Debe seleccionar un tipo de licencia válido.";

        header("Location: editar_asistentes.php?id=$id");
        exit;
    }

    if (!in_array($turno, $turnos, true)) {

        $_SESSION['edit_error'] = "Debe seleccionar un turno válido.";

        header("Location: editar_asistentes.php?id=$id");
        exit;
    }

    $fechaDt = DateTime::createFromFormat("Y-m-d", $fecha_dia);

    if (!$fechaDt) {

        $_SESSION['edit_error'] = "Formato de fecha inválido.";

        header("Location: editar_asistentes.php?id=$id");
        exit;
    }

    $fecha_sql = $fechaDt->format("Y-m-d") . " 00:00:00";

    /* ==============================
       VERIFICAR DUPLICADO
    ================================= */
    $check = $conn->prepare("
        SELECT id
        FROM asistencias
        WHERE dni = ?
        AND fecha_dia = ?
        AND id != ?
        LIMIT 1
    ");

    $check->bind_param("ssi", $dni, $fecha_dia, $id);

    $check->execute();

    $check->store_result();

    if ($check->num_rows > 0) {

        $_SESSION['edit_error'] =
            "Ya existe un registro para ese DNI en esa fecha.";

        header("Location: editar_asistentes.php?id=$id");
        exit;
    }

    $check->close();

    /* ==============================
       UPDATE
    ================================= */
    $stmt = $conn->prepare("
        UPDATE asistencias SET
            fecha = ?,
            fecha_dia = ?,
            apellidos = ?,
            nombres = ?,
            dni = ?,
            edad = ?,
            genero = ?,
            email = ?,
            licencia = ?,
            lugar = ?,
            turno = ?
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "sssssisssssi",
        $fecha_sql,
        $fecha_dia,
        $apellidos,
        $nombres,
        $dni,
        $edad,
        $genero,
        $email,
        $licencia,
        $lugar,
        $turno,
        $id
    );

    if ($stmt->execute()) {

        $_SESSION['edit_ok'] =
            "Registro actualizado correctamente.";

        header("Location: editar_asistentes.php?id=$id&ok=1");

    } else {

        $_SESSION['edit_error'] =
            "Error al guardar: " . $stmt->error;

        header("Location: editar_asistentes.php?id=$id");
    }

    $stmt->close();
    exit;
}

/* ==============================
   CARGAR REGISTRO
================================ */
$stmt = $conn->prepare("
    SELECT
        id,
        fecha_dia,
        apellidos,
        nombres,
        dni,
        edad,
        genero,
        email,
        licencia,
        lugar,
        turno
    FROM asistencias
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param("i", $id);

$stmt->execute();

$res = $stmt->get_result();

if ($res->num_rows === 0) {
    die("Registro no encontrado");
}

$r = $res->fetch_assoc();

$stmt->close();

/* ==============================
   HELPER SELECT
================================ */
function selOpt(array $opts, string $val): string {

    $out = '';

    $val = trim($val);

    foreach ($opts as $o) {

        $sel = (trim($o) == $val)
            ? ' selected'
            : '';

        $out .= '
            <option value="' . htmlspecialchars($o, ENT_QUOTES, "UTF-8") . '"' . $sel . '>
                ' . htmlspecialchars($o, ENT_QUOTES, "UTF-8") . '
            </option>
        ';
    }

    return $out;
}
?>
<!DOCTYPE html>
<html lang="es">

<head>

<meta charset="UTF-8">

<title>Editar Asistente — CeCap</title>

<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/variables.css">

<style>

body{
    min-height:100vh;
    margin:0;
    font-family:'Segoe UI',sans-serif;
    background: linear-gradient(135deg, #504254, #3C313F);
    background-attachment: fixed;
    color:#F0EDF2;
    padding:30px 16px;
}

.card-glass{
    background:rgba(255,255,255,0.10);
    border:1px solid rgba(255,255,255,0.2);
    border-radius:20px;
    padding:28px;
    backdrop-filter:blur(10px);
    max-width:680px;
    margin:auto;
    box-shadow:0 20px 50px rgba(0,0,0,.5);
}

.form-control,
.form-select{
    background:rgba(0,0,0,.35)!important;
    border:1px solid rgba(255,255,255,.25)!important;
    border-radius:12px!important;
    color:#F0EDF2!important;
    caret-color:#5D5E70;
}

.form-control:focus,
.form-select:focus{
    background:rgba(0,0,0,.45)!important;
    border-color:#5D5E70!important;
    box-shadow:0 0 0 2px rgba(93,94,112,.35)!important;
    color:#e9fbff!important;
}

.form-control::placeholder{
    color:rgba(199,228,207,.5)!important;
}

.form-select option{
    background:#fff;
    color:#000;
}

.form-label{
    color:#C7E4CF;
    font-size:.85rem;
    font-weight:600;
    margin-bottom:3px;
}

input:-webkit-autofill{
    -webkit-text-fill-color:#F0EDF2!important;
    box-shadow:0 0 0 1000px rgba(0,0,0,.35) inset!important;
}

.mayusculas{
    text-transform:uppercase;
}

</style>

</head>

<body>

<div class="card-glass">

    <h3 class="text-center mb-4">
        ✏️ Editar Asistente
    </h3>

    <?php if (!empty($_SESSION['edit_ok'])): ?>

        <div class="alert alert-success">
            ✅ <?= htmlspecialchars($_SESSION['edit_ok']) ?>
        </div>

    <?php unset($_SESSION['edit_ok']); endif; ?>

    <?php if (!empty($_SESSION['edit_error'])): ?>

        <div class="alert alert-danger">
            ❌ <?= htmlspecialchars($_SESSION['edit_error']) ?>
        </div>

    <?php unset($_SESSION['edit_error']); endif; ?>

    <form method="POST">

        <input type="hidden" name="id" value="<?= $id ?>">

        <div class="row g-3">

            <div class="col-md-6">

                <label class="form-label">
                    Fecha
                </label>

                <input
                    type="date"
                    name="fecha_dia"
                    class="form-control"
                    value="<?= htmlspecialchars($r['fecha_dia']) ?>"
                    required>

            </div>

            <div class="col-md-6">

                <label class="form-label">
                    DNI
                </label>

                <input
                    name="dni"
                    class="form-control"
                    value="<?= htmlspecialchars($r['dni']) ?>"
                    required>

            </div>

            <div class="col-md-6">

                <label class="form-label">
                    Apellidos
                </label>

                <input
                    name="apellidos"
                    class="form-control mayusculas"
                    value="<?= htmlspecialchars($r['apellidos']) ?>"
                    required>

            </div>

            <div class="col-md-6">

                <label class="form-label">
                    Nombres
                </label>

                <input
                    name="nombres"
                    class="form-control mayusculas"
                    value="<?= htmlspecialchars($r['nombres']) ?>"
                    required>

            </div>

            <div class="col-md-3">

                <label class="form-label">
                    Edad
                </label>

                <input
                    type="number"
                    name="edad"
                    class="form-control"
                    value="<?= htmlspecialchars($r['edad']) ?>"
                    min="1"
                    max="120">

            </div>

            <div class="col-md-4">

                <label class="form-label">
                    Género
                </label>

                <select name="genero" class="form-select">

                    <option value="">—</option>

                    <?= selOpt(
                        ['MASCULINO','FEMENINO','OTRO'],
                        $r['genero']
                    ) ?>

                </select>

            </div>

            <div class="col-md-5">

                <label class="form-label">
                    Email
                </label>

                <input
                    type="email"
                    name="email"
                    class="form-control"
                    value="<?= htmlspecialchars(strtolower($r['email'])) ?>"
                    placeholder="correo@ejemplo.com">

            </div>

            <div class="col-md-6">

                <label class="form-label">
                    Licencia
                </label>

                <select
                    name="licencia"
                    class="form-select"
                    required>

                    <option value="">
                        — Seleccionar —
                    </option>

                    <?= selOpt($licencias, $r['licencia']) ?>

                </select>

            </div>

            <div class="col-md-6">

                <label class="form-label">
                    Lugar
                </label>

                <select
                    name="lugar"
                    class="form-select"
                    required>

                    <option value="">
                        — Seleccionar —
                    </option>

                    <?= selOpt(
                        [
                            'LEHMANN 1530',
                            'S. IRIONDO 5870',
                            'SARMIENTO 2551',
                            'PUNTO DIGITAL',
                            'NIDO'
                        ],
                        $r['lugar']
                    ) ?>

                </select>

            </div>

            <div class="col-md-6">

                <label class="form-label">
                    Turno
                </label>

                <select
                    name="turno"
                    class="form-select"
                    required>

                    <option value="">
                        — Seleccionar —
                    </option>

                    <?= selOpt($turnos, $r['turno']) ?>

                </select>

            </div>

        </div>

        <div class="d-flex gap-3 justify-content-center mt-4">

            <button
                type="submit"
                class="btn btn-success px-4">

                💾 Guardar

            </button>

            <a
                href="buscar_asistentes.php"
                class="btn btn-secondary px-4">

                ← Volver

            </a>

        </div>

    </form>

</div>

<script>

document.addEventListener("input", e => {

    if (e.target.classList.contains("mayusculas")) {

        e.target.value = e.target.value.toUpperCase();

    }

});

</script>

</body>
</html>