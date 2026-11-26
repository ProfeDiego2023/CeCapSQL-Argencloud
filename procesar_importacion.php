<?php
session_start();
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/auditoria_fn.php";

/* ==============================
   ACCESO
================================ */
if (empty($_SESSION['id_instru'])) {
    http_response_code(403);
    echo json_encode(["error" => "Acceso no autorizado"]);
    exit;
}

/* ==============================
   LEER JSON DEL BODY
   (enviado por SheetJS via fetch)
================================ */
$body = file_get_contents("php://input");
$data = json_decode($body, true);

if (empty($data['filas']) || !is_array($data['filas'])) {
    http_response_code(400);
    echo json_encode(["error" => "No se recibieron filas válidas"]);
    exit;
}

$filas = $data['filas'];

/* ==============================
   ASEGURAR ESTRUCTURA DE TABLA
================================ */
$conn->query("
    CREATE UNIQUE INDEX IF NOT EXISTS unico_dni_fecha
    ON asistencias (dni, fecha_dia)
");

$check = $conn->query("SHOW COLUMNS FROM asistencias LIKE 'oculto'");
if ($check->num_rows == 0) {
    $conn->query("ALTER TABLE asistencias ADD oculto TINYINT(1) DEFAULT 0");
}

$check2 = $conn->query("SHOW COLUMNS FROM asistencias LIKE 'codigo_seguridad'");
if ($check2->num_rows == 0) {
    $conn->query("ALTER TABLE asistencias ADD codigo_seguridad VARCHAR(8) NULL");
}

/* ==============================
   MAPEO CABECERAS -> COLUMNAS BD

   Cubre ambas hojas:
   - Hoja 1 (formulario): Marca temporal, Direccion de correo, Apellidos,
                          Nombres, DNI, Edad, Genero, Licencia, Turno, Lugar
   - Hoja 2 (manual):     Marca temporal, Apellidos, Nombres, DNI, Edad,
                          Genero, Lugar, Turno, Direccion de correo, Licencia
================================ */
$mapa = [
    "marca temporal"                  => ["fecha", "fecha_dia"],
    "apellidos"                       => "apellidos",
    "nombres"                         => "nombres",
    "dni"                             => "dni",
    "edad"                            => "edad",
    "genero"                          => "genero",
    "lugar"                           => "lugar",
    "turno"                           => "turno",
    "direccion de correo electronico" => "email",
    "licencia"                        => "licencia",
];

/* Emails genericos de la hoja 2 -> vacío */
$emailsPlaceholder = ['sd@gmail.com', 's@gmail.com', 'sd@sd.com', 'na@gmail.com'];

/* ==============================
   FUNCIONES
================================ */

function normalizar(string $texto): string {
    $texto = trim(mb_strtolower($texto, 'UTF-8'));
    $texto = str_replace(
        ['á','é','í','ó','ú','ü','ñ'],
        ['a','e','i','o','u','u','n'],
        $texto
    );
    return $texto;
}

/**
 * Parsea la fecha que viene de SheetJS.
 *
 * SheetJS con raw:false entrega fechas como string.
 * Según el locale puede ser "M/D/YYYY HH:MM:SS" o "DD/MM/YYYY HH:MM:SS".
 * Cubrimos todos los casos posibles incluyendo formatos originales del CSV.
 */
function parsearFecha(string $valor): array {
    $valor = trim($valor);
    if ($valor === '') return [null, null];

    $formatos = [
        // Locale español: día/mes/año
        "j/n/Y H:i:s",
        "j/m/Y H:i:s",
        "d/m/Y H:i:s",
        "d/n/Y H:i:s",
        "j/n/Y G:i:s",
        "j/m/Y G:i:s",
        "d/m/Y G:i:s",
        "d/n/Y G:i:s",
        "j/n/Y H:i",
        "j/m/Y H:i",
        "d/m/Y H:i",
        "d/n/Y H:i",

        // Locale inglés: mes/día/año (SheetJS puede usar este)
        "n/j/Y H:i:s",
        "m/d/Y H:i:s",
        "n/j/Y G:i:s",
        "m/d/Y G:i:s",
        "n/j/Y H:i",
        "m/d/Y H:i",

        // ISO
        "Y-m-d H:i:s",
        "Y-m-d",
    ];

    foreach ($formatos as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $valor);
        if ($dt !== false) {
            // PHP 8.3 devuelve false en getLastErrors() cuando no hay errores
            $errors = DateTime::getLastErrors();
            $warnings = ($errors === false) ? 0 : ($errors['warning_count'] ?? 0);
            $errCount = ($errors === false) ? 0 : ($errors['error_count']   ?? 0);
            if ($warnings === 0 && $errCount === 0) {
                return [
                    $dt->format("Y-m-d H:i:s"),
                    $dt->format("Y-m-d"),
                ];
            }
        }
    }

    return [null, null];
}

function limpiarDni(string $valor): string {
    return preg_replace('/[\.\s\-]/', '', trim($valor));
}

function normalizarLicencia(string $valor): string {
    $valor = preg_replace('/\s*\+\s*/', ' + ', trim($valor));
    return mb_strtoupper(iconv('UTF-8', 'UTF-8//IGNORE', $valor), 'UTF-8');
}

function generarCodigoUnico(mysqli $conn): string {
    do {
        $codigo = strtoupper(bin2hex(random_bytes(6))); // 12 chars
        $st     = $conn->prepare(
            "SELECT id FROM asistencias WHERE codigo_seguridad = ? LIMIT 1"
        );
        $st->bind_param("s", $codigo);
        $st->execute();
        $st->store_result();
    } while ($st->num_rows > 0);
    return $codigo;
}

/* ==============================
   PROCESAR FILAS
================================ */
$insertados = 0;
$omitidos   = 0;
$errores    = 0;

$conn->begin_transaction();

try {

    foreach ($filas as $fila) {

        // Ignorar filas completamente vacías
        $noVacios = array_filter($fila, fn($v) => trim((string)$v) !== '');
        if (count($noVacios) === 0) continue;

        $campos  = [];
        $valores = [];
        $tipos   = "";

        foreach ($fila as $cabecera => $valorRaw) {

            $key = normalizar((string)$cabecera);
            if (!isset($mapa[$key])) continue;

            $destino = $mapa[$key];
            $valor   = trim((string)$valorRaw);
            $valor   = iconv('UTF-8', 'UTF-8//IGNORE', $valor) ?: $valor;

            // ── Fecha ──────────────────────────────────────────
            if (is_array($destino)) {

                [$fechaCompleta, $fechaDia] = parsearFecha($valor);

                $campos[]  = "fecha";
                $valores[] = $fechaCompleta;
                $tipos    .= "s";

                $campos[]  = "fecha_dia";
                $valores[] = $fechaDia;
                $tipos    .= "s";

            } else {

                switch ($destino) {

                    case "edad":
                        $edadNum   = (int) preg_replace('/[^0-9]/', '', $valor);
                        $campos[]  = "edad";
                        $valores[] = ($edadNum > 0 && $edadNum < 120) ? $edadNum : 0;
                        $tipos    .= "i";
                        break;

                    case "dni":
                        $campos[]  = "dni";
                        $valores[] = limpiarDni($valor);
                        $tipos    .= "s";
                        break;

                    case "email":
                        $emailLimpio = mb_strtolower($valor, 'UTF-8');
                        if (in_array($emailLimpio, $GLOBALS['emailsPlaceholder'], true)) {
                            $emailLimpio = "";
                        }
                        $campos[]  = "email";
                        $valores[] = $emailLimpio;
                        $tipos    .= "s";
                        break;

                    case "licencia":
                        $campos[]  = "licencia";
                        $valores[] = normalizarLicencia($valor);
                        $tipos    .= "s";
                        break;

                    case "lugar":
                        $campos[]  = "lugar";
                        $valores[] = mb_strtoupper($valor, 'UTF-8');
                        $tipos    .= "s";
                        break;

                    default:
                        $campos[]  = $destino;
                        $valores[] = mb_strtoupper($valor, 'UTF-8');
                        $tipos    .= "s";
                }
            }
        }

        if (count($campos) === 0) continue;

        // Validacion minima: necesitamos DNI y fecha_dia
        $idxDni      = array_search("dni",       $campos);
        $idxFechaDia = array_search("fecha_dia", $campos);

        if ($idxDni === false || $idxFechaDia === false)          continue;
        if ($valores[$idxDni] === "" || $valores[$idxFechaDia] === null) continue;

        // INSERT IGNORE: duplicados se omiten sin modificar
        $placeholders = implode(",", array_fill(0, count($valores), "?"));
        $cols         = implode(",", $campos);
        $sql          = "INSERT IGNORE INTO asistencias ($cols) VALUES ($placeholders)";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $errores++;
            continue;
        }

        $stmt->bind_param($tipos, ...$valores);

        if (!$stmt->execute()) {
            $errores++;
            $stmt->close();
            continue;
        }

        if ($stmt->affected_rows === 1) {
            $insertados++;
        } else {
            $omitidos++;
        }

        $stmt->close();
    }

    $conn->commit();

} catch (Throwable $e) {
    $conn->rollback();
    header("Content-Type: application/json");
    echo json_encode(["error" => "Error inesperado: " . $e->getMessage()]);
    exit;
}

/* ==============================
   ASIGNAR CODIGOS DE SEGURIDAD
================================ */
$sinCodigo = $conn->query("
    SELECT id FROM asistencias
    WHERE codigo_seguridad IS NULL OR codigo_seguridad = ''
");

if ($sinCodigo && $sinCodigo->num_rows > 0) {
    $stmtCod = $conn->prepare(
        "UPDATE asistencias SET codigo_seguridad = ? WHERE id = ?"
    );
    while ($row = $sinCodigo->fetch_assoc()) {
        $codigo = generarCodigoUnico($conn);
        $stmtCod->bind_param("si", $codigo, $row['id']);
        $stmtCod->execute();
    }
    $stmtCod->close();
}

/* ==============================
   AUDITORIA
================================ */
if ($insertados > 0 || $errores > 0) {
    auditar($conn,
        "IMPORTACION_XLSX",
        "Insertados: $insertados | Omitidos: $omitidos | Errores: $errores"
    );
}

/* ==============================
   RESPUESTA JSON
================================ */
header("Content-Type: application/json");
echo json_encode([
    "insertados" => $insertados,
    "omitidos"   => $omitidos,
    "errores"    => $errores,
]);