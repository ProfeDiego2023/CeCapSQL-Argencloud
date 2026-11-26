<?php
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

header("Content-Type: application/json");

try {

if (!permiso("cert_email")) {
    http_response_code(403);
    echo json_encode(["ok" => false, "msg" => "No autorizado", "enviados" => 0, "errores" => 0, "sinEmail" => []]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["ok" => false, "msg" => "Método no permitido", "enviados" => 0, "errores" => 0, "sinEmail" => []]);
    exit;
}

/* ── Leer registros seleccionados (formato dni|fecha, igual que certificados.php) ── */
$registros = $_POST['registros'] ?? [];
if (!is_array($registros) || empty($registros)) {
    echo json_encode(["ok" => false, "msg" => "No se seleccionaron registros.", "enviados" => 0, "errores" => 0, "sinEmail" => []]);
    exit;
}

$pares = [];
foreach ($registros as $val) {
    $partes = explode('|', $val);
    if (count($partes) === 2) {
        $dni   = preg_replace('/\D/', '', trim($partes[0]));
        $fecha = trim($partes[1]);
        if ($dni !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $pares[] = [$dni, $fecha];
        }
    }
}

if (empty($pares)) {
    echo json_encode(["ok" => false, "msg" => "Registros inválidos.", "enviados" => 0, "errores" => 0, "sinEmail" => []]);
    exit;
}

email_asegurarTablas($conn);
$cfgEmail = email_cargarConfig($conn);
if (!email_configurado($cfgEmail)) {
    echo json_encode(["ok" => false, "msg" => "El envío por email no está configurado.", "enviados" => 0, "errores" => 0, "sinEmail" => []]);
    exit;
}

$instructorId = (int)($_POST['instructor_id'] ?? 0);
$nombreSesion = trim((string)($_SESSION['nombre'] ?? ''));
[$instructor, $firmaArchivo] = inst_resolver($conn, $instructorId, $nombreSesion, (int)($_SESSION['id_instru'] ?? 0));

/* ── Consultar registros por (dni, fecha_dia) ── */
$condiciones = implode(' OR ', array_fill(0, count($pares), '(dni=? AND fecha_dia=?)'));
$sql = "
    SELECT id, apellidos, nombres, dni, licencia, turno, fecha_dia, email, codigo_seguridad
    FROM asistencias
    WHERE oculto = 0 AND ($condiciones)
    ORDER BY apellidos, nombres
";
$stmt  = $conn->prepare($sql);
$tipos = str_repeat('ss', count($pares));
$flat  = array_merge(...array_map(fn($p) => [$p[0], $p[1]], $pares));
$stmt->bind_param($tipos, ...$flat);
$stmt->execute();
$res = $stmt->get_result();

/* ── Carpeta segura para los PDF ── */
$dir = __DIR__ . '/cert_pdfs';
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}

$enviados = 0;
$errores  = 0;
$sinEmail = [];
$detalle  = [];

while ($reg = $res->fetch_assoc()) {
    $nombre  = trim($reg['apellidos'] . ' ' . $reg['nombres']);
    $destino = strtolower(trim($reg['email'] ?? ''));

    if ($destino === '' || !filter_var($destino, FILTER_VALIDATE_EMAIL)) {
        $sinEmail[] = [
            'id'         => (int)$reg['id'],
            'dni'        => $reg['dni'],
            'fecha_dia'  => $reg['fecha_dia'],
            'apellidos'  => $reg['apellidos'],
            'nombres'    => $reg['nombres'],
        ];
        continue;
    }

    $token   = bin2hex(random_bytes(16));
    $archivo = $token . '.pdf';
    $ruta    = $dir . '/' . $archivo;

    $pdf = cert_generarPdfIndividual($conn, $reg, $instructor, false, '', $firmaArchivo);
    if (@file_put_contents($ruta, $pdf) === false) {
        $errores++;
        $detalle[] = "ERROR: {$nombre} — no se pudo generar el certificado.";
        continue;
    }

    $expira = date('Y-m-d H:i:s', time() + 7 * 86400);
    $st = $conn->prepare("
        INSERT INTO cert_descargas
            (token, id_asistencia, dni, fecha_dia, email, archivo, usado, fecha_creado, fecha_expira)
        VALUES (?, ?, ?, ?, ?, ?, 0, NOW(), ?)
    ");
    $st->bind_param("sisssss", $token, $reg['id'], $reg['dni'], $reg['fecha_dia'], $destino, $archivo, $expira);
    $st->execute();
    $st->close();

    $link   = email_urlBase() . 'descargar_certificado.php?t=' . $token;
    $result = email_enviarCertificadoLink($conn, $reg, $instructor, $link);

    if ($result['ok']) {
        $enviados++;
        $detalle[] = "OK: {$nombre} — {$result['msg']}";
    } else {
        $errores++;
        $detalle[] = "ERROR: {$nombre} — {$result['msg']}";
        @unlink($ruta);
        $stD = $conn->prepare("DELETE FROM cert_descargas WHERE token = ?");
        $stD->bind_param("s", $token);
        $stD->execute();
        $stD->close();
    }
}
$stmt->close();

auditar(
    $conn,
    "ENVIO_CERT_LINK",
    "CERTIFICADOS",
    "Links enviados: {$enviados}, Errores: {$errores}, Sin email: " . count($sinEmail)
);

echo json_encode([
    "ok"       => $errores === 0,
    "enviados" => $enviados,
    "errores"  => $errores,
    "sinEmail" => $sinEmail,
    "detalle"  => $detalle,
], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok"       => false,
        "msg"      => "Error interno: " . $e->getMessage(),
        "enviados" => 0,
        "errores"  => 0,
        "sinEmail" => [],
        "detalle"  => [],
    ]);
}
