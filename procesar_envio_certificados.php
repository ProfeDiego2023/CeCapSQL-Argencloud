<?php
ob_start();
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
set_time_limit(300);

session_start();
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/auditar.php";
require_once __DIR__ . "/certificado_pdf_fn.php";
require_once __DIR__ . "/email_certificado_fn.php";
require_once __DIR__ . "/instructor_fn.php";

header("Content-Type: application/json; charset=utf-8");

try {

if (!permiso("cert_email") && !permiso("certificados")) {
    ob_clean();
    http_response_code(403);
    echo json_encode(["ok" => false, "msg" => "No tenés permisos para enviar certificados por email.", "enviados" => 0, "errores" => 0], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode(["ok" => false, "msg" => "Método no permitido.", "enviados" => 0, "errores" => 0], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$ids     = $payload['ids'] ?? [];
$instructorId = (int)($payload['instructor_id'] ?? 0);

if (!is_array($ids) || empty($ids)) {
    ob_clean();
    echo json_encode(["ok" => false, "msg" => "No se seleccionaron registros.", "enviados" => 0, "errores" => 0], JSON_UNESCAPED_UNICODE);
    exit;
}

$ids = array_values(array_filter(array_map('intval', $ids), fn($id) => $id > 0));
if (empty($ids)) {
    ob_clean();
    echo json_encode(["ok" => false, "msg" => "IDs inválidos.", "enviados" => 0, "errores" => 0], JSON_UNESCAPED_UNICODE);
    exit;
}

email_asegurarTablas($conn);
$cfgEmail = email_cargarConfig($conn);
if (!email_configurado($cfgEmail)) {
    ob_clean();
    echo json_encode(["ok" => false, "msg" => "El envío por email no está configurado.", "enviados" => 0, "errores" => 0], JSON_UNESCAPED_UNICODE);
    exit;
}

$nombreSesion = trim((string)($_SESSION['nombre'] ?? ''));
[$instructor, $firmaArchivo] = inst_resolver($conn, $instructorId, $nombreSesion, (int)($_SESSION['id_instru'] ?? 0));

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$sql = "
    SELECT id, apellidos, nombres, dni, licencia, turno, fecha_dia, email, codigo_seguridad
    FROM asistencias
    WHERE oculto = 0 AND id IN ($placeholders)
";
$stmt = $conn->prepare($sql);
$types = str_repeat('i', count($ids));
$stmt->bind_param($types, ...$ids);
$stmt->execute();
$res = $stmt->get_result();

$enviados = 0;
$errores  = 0;
$detalle  = [];

while ($registro = $res->fetch_assoc()) {
    $nombre = trim($registro['apellidos'] . ' ' . $registro['nombres']);
    try {
        $pdf    = cert_generarPdfIndividual($conn, $registro, $instructor, false, '', $firmaArchivo);
        $result = email_enviarCertificado($conn, $registro, $pdf, $instructor);

        if ($result['ok']) {
            $enviados++;
            $detalle[] = "OK: {$nombre} — {$result['msg']}";
        } else {
            $errores++;
            $detalle[] = "ERROR: {$nombre} — {$result['msg']}";
        }
    } catch (Throwable $te) {
        $errores++;
        $detalle[] = "ERROR: {$nombre} — Exception: " . $te->getMessage();
    }
}
$stmt->close();

auditar(
    $conn,
    "ENVIO_CERT_EMAIL",
    "CERTIFICADOS",
    "Enviados: {$enviados}, Errores: {$errores}"
);

ob_clean();
echo json_encode([
    "ok"       => $errores === 0,
    "enviados" => $enviados,
    "errores"  => $errores,
    "detalle"  => $detalle,
], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        "ok"       => false,
        "msg"      => "Error interno: " . $e->getMessage(),
        "enviados" => 0,
        "errores"  => 0,
        "detalle"  => [],
    ], JSON_UNESCAPED_UNICODE);
}
