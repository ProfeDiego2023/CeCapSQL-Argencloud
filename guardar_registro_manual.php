<?php
session_start();
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/auth.php";
proteger();

$conn->set_charset("utf8mb4");

header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["ok" => false, "msg" => "Solicitud inválida."]);
    exit;
}

/* ==============================
   HELPERS
============================== */
function limpiar(string $v): string {
    return mb_strtoupper(trim($v), 'UTF-8');
}

/* ==============================
   ASEGURAR COLUMNAS EN TABLA
============================== */
$cols = [];
$resCols = $conn->query("SHOW COLUMNS FROM asistencias");
if ($resCols) {
    while ($c = $resCols->fetch_assoc()) {
        $cols[] = $c['Field'];
    }
}
$hasEsEscuela = in_array('es_escuela', $cols, true);
if (!$hasEsEscuela) {
    @$conn->query("ALTER TABLE asistencias ADD COLUMN es_escuela TINYINT(1) NOT NULL DEFAULT 0");
    $hasEsEscuela = true;
}
$hasInstructor = in_array('instructor', $cols, true);
if (!$hasInstructor) {
    @$conn->query("ALTER TABLE asistencias ADD COLUMN instructor VARCHAR(150) NULL DEFAULT ''");
    $hasInstructor = true;
}

/* ==============================
   LEER DATOS
============================== */
$id         = (int)($_POST['id'] ?? 0);
$es_escuela = !empty($_POST['es_escuela']) ? 1 : 0;
$fecha      = trim($_POST['fecha'] ?? '');
$apellidos  = limpiar($_POST['apellidos'] ?? '');
$nombres    = limpiar($_POST['nombres'] ?? '');
$dni        = preg_replace('/\D/', '', $_POST['dni'] ?? '');
$edad       = (int)($_POST['edad'] ?? 0);
$genero     = limpiar($_POST['genero'] ?? '');
$email      = strtolower(trim($_POST['email'] ?? ''));
$licencia   = limpiar($_POST['licencia'] ?? '');
$instructor = limpiar($_POST['escuela_instructor'] ?? ($_POST['instructor'] ?? ''));

if ($es_escuela) {
    // Si es modo escuela, asignar la escuela como lugar y el horario de la sesión
    $escuelaNom = limpiar($_POST['escuela_nombre'] ?? '');
    if ($escuelaNom !== '') {
        $lugar = !preg_match('/^ESC(UELA)?\b/i', $escuelaNom)
            ? 'ESCUELA - ' . $escuelaNom
            : $escuelaNom;
    } else {
        $lugar = limpiar((!empty($_POST['lugar_manual'])) ? $_POST['lugar_manual'] : ($_POST['lugar'] ?? ''));
    }

    $horarioEsc = limpiar($_POST['escuela_horario'] ?? '');
    if ($horarioEsc !== '') {
        $turno = $horarioEsc;
    } else {
        $turnoRaw = (!empty($_POST['turno_manual'])) ? $_POST['turno_manual'] : ($_POST['turno'] ?? '');
        $turno = limpiar($turnoRaw);
    }

    if (empty($licencia)) {
        $licencia = 'ESCUELAS/COLEGIOS';
    }
} else {
    // Modo estándar sin escuela
    $lugar = limpiar(
        (!empty($_POST['lugar_manual']))
            ? $_POST['lugar_manual']
            : ($_POST['lugar'] ?? '')
    );

    $turnoRaw = (!empty($_POST['turno_manual']))
        ? $_POST['turno_manual']
        : ($_POST['turno'] ?? '');
    $turno = limpiar($turnoRaw);
}

/* ==============================
   VALIDACIÓN
============================== */
if ($fecha === '' || $dni === '') {
    echo json_encode(["ok" => false, "msg" => "Fecha y DNI son obligatorios."]);
    exit;
}

if ($apellidos === '' || $nombres === '') {
    echo json_encode(["ok" => false, "msg" => "Apellidos y nombres son obligatorios."]);
    exit;
}

/* ==============================
   FECHA
============================== */
$fechaDt = DateTime::createFromFormat("Y-m-d", $fecha);
if (!$fechaDt) {
    echo json_encode(["ok" => false, "msg" => "Formato de fecha inválido."]);
    exit;
}

$fecha_dia = $fechaDt->format("Y-m-d");
$fecha_sql = $fecha_dia . " 00:00:00";

$turno_num = 0;
if (preg_match('/(\d{1,2}):(\d{2})/', $turno, $m)) {
    $turno_num = (int)$m[1];
}

/* ==============================
   CONTROL DUPLICADO (no contempla registros eliminados)
============================== */
$check = $conn->prepare("
    SELECT id, oculto FROM asistencias
    WHERE dni = ? AND fecha_dia = ? AND id != ?
    LIMIT 1
");
$check->bind_param("ssi", $dni, $fecha_dia, $id);
$check->execute();
$resCheck = $check->get_result();
if ($rExist = $resCheck->fetch_assoc()) {
    if ((int)$rExist['oculto'] === 0) {
        echo json_encode(["ok" => false, "msg" => "El asistente con DNI $dni ya tiene un registro activo en esa fecha."]);
        exit;
    } else {
        // Estaba previamente eliminado/oculto: se reutiliza y reactiva con los nuevos datos
        $id = (int)$rExist['id'];
    }
}
$check->close();

/* ==============================
   INSERT o UPDATE según id
============================== */
if ($id > 0) {

    // ── EDICIÓN / REACTIVACIÓN ──
    $stmt = $conn->prepare("
        UPDATE asistencias SET
            fecha      = ?,
            fecha_dia  = ?,
            apellidos  = ?,
            nombres    = ?,
            dni        = ?,
            edad       = ?,
            genero     = ?,
            lugar      = ?,
            email      = ?,
            licencia   = ?,
            turno      = ?,
            turno_num  = ?,
            es_escuela = ?,
            instructor = ?,
            oculto     = 0
        WHERE id = ?
    ");

    if (!$stmt) {
        echo json_encode(["ok" => false, "msg" => "Error interno: " . $conn->error]);
        exit;
    }

    $stmt->bind_param(
        "sssssisssssiisi",
        $fecha_sql, $fecha_dia,
        $apellidos, $nombres, $dni,
        $edad,
        $genero, $lugar, $email, $licencia, $turno,
        $turno_num, $es_escuela, $instructor,
        $id
    );

    if ($stmt->execute()) {
        $msgOk = $es_escuela
            ? "Asistente $apellidos, $nombres registrado correctamente en sesión de escuela."
            : "Registro guardado correctamente.";
        echo json_encode(["ok" => true, "msg" => $msgOk, "id" => $id]);
    } else {
        echo json_encode(["ok" => false, "msg" => "Error al actualizar: " . $stmt->error]);
    }

} else {

    // ── NUEVO REGISTRO ──
    $stmt = $conn->prepare("
        INSERT INTO asistencias
            (fecha, fecha_dia, apellidos, nombres, dni, edad, genero, lugar, email, licencia, turno, turno_num, es_escuela, instructor, oculto)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
    ");

    if (!$stmt) {
        echo json_encode(["ok" => false, "msg" => "Error interno: " . $conn->error]);
        exit;
    }

    $stmt->bind_param(
        "sssssisssssiis",
        $fecha_sql, $fecha_dia,
        $apellidos, $nombres, $dni,
        $edad,
        $genero, $lugar, $email, $licencia, $turno,
        $turno_num, $es_escuela, $instructor
    );

    if ($stmt->execute()) {
        $msgOk = $es_escuela
            ? "Asistente $apellidos, $nombres registrado correctamente en sesión de escuela."
            : "Registro guardado correctamente.";
        echo json_encode(["ok" => true, "msg" => $msgOk, "id" => $conn->insert_id]);
    } else {
        echo json_encode(["ok" => false, "msg" => "Error al guardar: " . $stmt->error]);
    }

}

$stmt->close();
$conn->close();
