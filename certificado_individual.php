<?php
session_start();

require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/instructor_fn.php";

if (!isset($_SESSION['id_instru'])) {
    die("No autorizado");
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    die("ID inválido");
}

/* ================= BUSCAR SOLO ESE REGISTRO ================= */
$sql = "
SELECT dni, estado_certificado
FROM asistencias
WHERE id = ?
AND oculto = 0
LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    die("Registro no encontrado");
}

$r = $res->fetch_assoc();
$stmt->close();

$instructores = inst_cargarInstructores($conn);
$instAuto = inst_tieneFirmaPropia($conn, (int)($_SESSION['id_instru'] ?? 0));

/* ================= SELECCIÓN CONFIRMADA → MARCAR Y REDIRIGIR ================= */
$instructorFinal = 0;
$listo = false;

if (($_POST['seleccionar'] ?? '') === '1') {
    $instructorFinal = (int)($_POST['instructor_id'] ?? 0);
    $listo = true;
} elseif ($instAuto) {
    $instructorFinal = (int)$instAuto['id'];
    $listo = true;
}

if ($listo) {
    $dni    = preg_replace('/\D/', '', $r['dni']);
    $estado = strtoupper($r['estado_certificado'] ?? 'ORIGINAL');

    $url = "certificados.php?dni=" . urlencode($dni) . "&solo_id=" . $id;

    // si ya no es ORIGINAL → forzar tipo_dup
    if ($estado !== 'ORIGINAL') {
        $url .= "&tipo_dup=" . $estado;
    }

    // si es ORIGINAL pero ya fue impreso → marcar como DUPLICADO
    if ($estado === 'ORIGINAL') {
        $conn->query("UPDATE asistencias SET estado_certificado='DUPLICADO' WHERE id=$id");
        $url .= "&tipo_dup=DUPLICADO";
    }

    if ($estado === "DUPLICADO" || $estado === "TRIPLICADO") {
        $url .= "&tipo_dup=" . $estado;
    }

    if ($instructorFinal > 0) {
        $url .= "&instructor_id=" . $instructorFinal;
    }

    header("Location: " . $url);
    exit;
}

/* ================= PANTALLA DE SELECCIÓN DE INSTRUCTOR ================= */
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Certificado — CeCap</title>
<style>
html, body { height: 100%; margin: 0; }
body {
    font-family: 'Segoe UI', sans-serif;
    background: linear-gradient(135deg, #504254, #3C313F);
    background-attachment: fixed;
    color: #F0EDF2;
    display: flex; align-items: center; justify-content: center;
}
.cargando {
    text-align: center; font-size: 1.1rem; font-weight: 600;
    color: #C7E4CF;
}
.cargando .spinner {
    width: 46px; height: 46px; margin: 0 auto 16px;
    border: 5px solid rgba(93,94,112,.25); border-top-color: #5D5E70;
    border-radius: 50%; animation: girar 1s linear infinite;
}
@keyframes girar { to { transform: rotate(360deg); } }
</style>
</head>
<body>

<div class="cargando">
    <div class="spinner"></div>
    Preparando certificado...
</div>

<?= inst_renderModal($instructores) ?>

<form id="fSel" method="POST">
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <input type="hidden" name="seleccionar" value="1">
    <input type="hidden" name="instructor_id" id="selInstructorId" value="0">
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    abrirSeleccionInstructor('¿Quién firma este certificado?', (ins) => {
        if (ins) {
            document.getElementById('selInstructorId').value = ins.id;
        }
        document.getElementById('fSel').submit();
    });
});
</script>
</body>
</html>
