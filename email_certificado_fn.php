<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

function email_cargarPHPMailer(): bool
{
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer') || class_exists('PHPMailer')) {
        if (class_exists('PHPMailer') && !class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            @class_alias('PHPMailer', 'PHPMailer\\PHPMailer\\PHPMailer');
        }
        if (!class_exists('PHPMailer\\PHPMailer\\Exception')) {
            @class_alias('Exception', 'PHPMailer\\PHPMailer\\Exception');
        }
        return true;
    }

    $dir = __DIR__;
    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');

    $roots = array_unique(array_filter([
        $dir,
        $docRoot,
        $docRoot !== '' ? $docRoot . '/CeCapSQL' : '',
        $docRoot !== '' ? $docRoot . '/CeCap' : '',
    ]));

    // 1. Probar autoload.php en cada raíz permitida
    foreach ($roots as $root) {
        $auto = $root . '/vendor/autoload.php';
        if (@file_exists($auto)) {
            @require_once $auto;
            if (class_exists('PHPMailer\\PHPMailer\\PHPMailer') || class_exists('PHPMailer')) {
                if (class_exists('PHPMailer') && !class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                    @class_alias('PHPMailer', 'PHPMailer\\PHPMailer\\PHPMailer');
                }
                if (!class_exists('PHPMailer\\PHPMailer\\Exception')) {
                    @class_alias('Exception', 'PHPMailer\\PHPMailer\\Exception');
                }
                return true;
            }
        }
    }

    // 2. Probar rutas directas de clases
    $subpaths = [
        '/vendor/phpmailer/phpmailer/src',
        '/vendor/PHPMailer/PHPMailer/src',
        '/vendor/phpmailer/phpmailer',
        '/vendor/PHPMailer/PHPMailer',
        '/vendor/phpmailer',
        '/vendor/PHPMailer',
        '/libs/PHPMailer/src',
        '/libs/PHPMailer',
        '/libs/phpmailer',
        '/PHPMailer/src',
        '/PHPMailer',
        '/phpmailer',
    ];

    foreach ($roots as $root) {
        foreach ($subpaths as $sub) {
            $targetDir = $root . $sub;
            if (!@is_dir($targetDir)) continue;

            foreach (['Exception.php', 'exception.php', 'class.phpmailer-exception.php'] as $exc) {
                if (@file_exists($targetDir . '/' . $exc)) {
                    @require_once $targetDir . '/' . $exc;
                    break;
                }
            }

            foreach (['PHPMailer.php', 'phpmailer.php', 'class.phpmailer.php'] as $pm) {
                if (@file_exists($targetDir . '/' . $pm)) {
                    @require_once $targetDir . '/' . $pm;
                    break;
                }
            }

            foreach (['SMTP.php', 'smtp.php', 'class.smtp.php'] as $smtp) {
                if (@file_exists($targetDir . '/' . $smtp)) {
                    @require_once $targetDir . '/' . $smtp;
                    break;
                }
            }

            if (class_exists('PHPMailer\\PHPMailer\\PHPMailer') || class_exists('PHPMailer')) {
                break;
            }
        }
    }

    if (class_exists('PHPMailer') && !class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        @class_alias('PHPMailer', 'PHPMailer\\PHPMailer\\PHPMailer');
    }

    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer') && !class_exists('PHPMailer\\PHPMailer\\Exception')) {
        @class_alias('Exception', 'PHPMailer\\PHPMailer\\Exception');
    }

    return class_exists('PHPMailer\\PHPMailer\\PHPMailer') || class_exists('PHPMailer');
}

email_cargarPHPMailer();

use PHPMailer\PHPMailer\PHPMailer;

function email_asegurarTablas(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS config_email (
            id INT PRIMARY KEY DEFAULT 1,
            activo TINYINT(1) NOT NULL DEFAULT 0,
            smtp_host VARCHAR(255) NOT NULL DEFAULT '',
            smtp_port INT NOT NULL DEFAULT 587,
            smtp_user VARCHAR(255) NOT NULL DEFAULT '',
            smtp_pass VARCHAR(255) NOT NULL DEFAULT '',
            smtp_secure VARCHAR(10) NOT NULL DEFAULT 'tls',
            from_email VARCHAR(255) NOT NULL DEFAULT '',
            from_name VARCHAR(255) NOT NULL DEFAULT 'CeCap - Municipalidad de Esperanza',
            asunto VARCHAR(255) NOT NULL DEFAULT 'Tu certificado de asistencia - CeCap',
            cuerpo TEXT NULL
        )
    ");

    $cols = $conn->query("SHOW COLUMNS FROM asistencias LIKE 'cert_email_enviado'");
    if ($cols && $cols->num_rows === 0) {
        $conn->query("ALTER TABLE asistencias ADD COLUMN cert_email_enviado TINYINT(1) NOT NULL DEFAULT 0");
        $conn->query("ALTER TABLE asistencias ADD COLUMN cert_email_fecha DATETIME NULL DEFAULT NULL");
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS cert_descargas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(64) NOT NULL UNIQUE,
            id_asistencia INT NOT NULL,
            dni VARCHAR(20) NOT NULL DEFAULT '',
            fecha_dia DATE NULL,
            email VARCHAR(255) NOT NULL DEFAULT '',
            archivo VARCHAR(255) NOT NULL DEFAULT '',
            usado TINYINT(1) NOT NULL DEFAULT 0,
            fecha_creado DATETIME NOT NULL,
            fecha_expira DATETIME NOT NULL,
            fecha_descarga DATETIME NULL
        )
    ");
}

function email_urlBase(): string
{
    $https   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $scheme  = $https ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir     = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir . '/';
}

function email_cargarConfig(mysqli $conn): array
{
    email_asegurarTablas($conn);

    $cfg = [
        'activo'     => 0,
        'smtp_host'  => '',
        'smtp_port'  => 587,
        'smtp_user'  => '',
        'smtp_pass'  => '',
        'smtp_secure'=> 'tls',
        'from_email' => '',
        'from_name'  => 'CeCap - Municipalidad de Esperanza',
        'asunto'     => 'Tu certificado de asistencia - CeCap',
        'cuerpo'     => '',
    ];

    $st = $conn->prepare("
        SELECT activo, smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure,
               from_email, from_name, asunto, cuerpo
        FROM config_email
        WHERE id = 1
        LIMIT 1
    ");
    if (!$st) {
        return $cfg;
    }

    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) {
        return $cfg;
    }

    return array_merge($cfg, $row);
}

function email_configurado(array $cfg): bool
{
    return !empty($cfg['activo'])
        && trim($cfg['smtp_host'] ?? '') !== ''
        && trim($cfg['from_email'] ?? '') !== '';
}

function email_crearMailer(array $cfg): PHPMailer
{
    if (!email_cargarPHPMailer()) {
        $doc = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $dir = __DIR__;
        throw new \Exception("PHPMailer no se encuentra instalado o no fue detectado. Buscado en: DIR ($dir) y DOCUMENT_ROOT ($doc). Verificá la presencia de la carpeta vendor/ o libs/PHPMailer/");
    }

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = trim($cfg['smtp_host'] ?? '');
    $mail->SMTPAuth   = trim($cfg['smtp_user'] ?? '') !== '';
    $mail->Username   = trim($cfg['smtp_user'] ?? '');
    $mail->Password   = $cfg['smtp_pass'] ?? '';
    $mail->Port       = (int)($cfg['smtp_port'] ?? 587);
    $mail->Timeout    = 15;
    $mail->CharSet    = 'UTF-8';

    $secure = strtolower(trim($cfg['smtp_secure'] ?? 'tls'));
    if ($secure === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($secure === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $mail->SMTPSecure = false;
        $mail->SMTPAutoTLS = false;
    }

    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ];

    return $mail;
}

function email_probarConexion(array $cfg): array
{
    try {
        $mail = email_crearMailer($cfg);
        $mail->SMTPDebug  = 2;
        $debug = '';
        $mail->Debugoutput = function ($str) use (&$debug) { $debug .= $str . "\n"; };

        if ($mail->smtpConnect()) {
            $mail->smtpClose();
            return ['ok' => true, 'debug' => $debug];
        }
        return ['ok' => false, 'debug' => $debug];
    } catch (\Throwable $e) {
        return ['ok' => false, 'debug' => ($debug ?? '') . "\n" . $e->getMessage()];
    }
}

function email_renderCuerpo(array $registro, array $cfg, string $link = ''): string
{
    $nombre = trim(($registro['apellidos'] ?? '') . ' ' . ($registro['nombres'] ?? ''));
    $plantilla = trim($cfg['cuerpo'] ?? '');

    if ($plantilla === '') {
        if ($link !== '') {
            $plantilla = "Estimado/a {NOMBRE}:\n\n"
                . "Te enviamos el enlace para descargar tu certificado de asistencia al curso de formación vial.\n\n"
                . "El enlace es de un solo uso y vence a los 7 días de emitido.\n\n"
                . "LINK\n\n"
                . "Municipalidad de Esperanza\n"
                . "CeCap - Centro de Capacitación";
        } else {
            $plantilla = "Estimado/a {NOMBRE}:\n\n"
                . "Adjuntamos tu certificado de asistencia al curso de formación vial.\n\n"
                . "Municipalidad de Esperanza\n"
                . "CeCap - Centro de Capacitación";
        }
    }

    $cuerpo = str_replace(
        ['{NOMBRE}', '{DNI}', '{LICENCIA}', '{FECHA}', '{LINK}', 'LINK'],
        [
            $nombre,
            $registro['dni'] ?? '',
            $registro['licencia'] ?? '',
            !empty($registro['fecha_dia']) ? date('d/m/Y', strtotime($registro['fecha_dia'])) : '',
            $link,
            $link,
        ],
        $plantilla
    );

    // Garantizar que el enlace siempre aparezca si se envió uno.
    if ($link !== '' && strpos($cuerpo, $link) === false) {
        $cuerpo .= "\n\nPara descargar tu certificado (enlace de un solo uso, vence en 7 días):\n$link\n";
    }

    return $cuerpo;
}

function email_enviarCertificado(
    mysqli $conn,
    array $registro,
    string $pdfContent,
    string $instructor
): array {
    $cfg = email_cargarConfig($conn);

    if (!email_configurado($cfg)) {
        return ['ok' => false, 'msg' => 'El envío por email no está configurado.'];
    }

    $destino = strtolower(trim($registro['email'] ?? ''));
    if ($destino === '' || !filter_var($destino, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'msg' => 'El asistente no tiene un email válido.'];
    }

    try {
        $mail = email_crearMailer($cfg);

        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($destino, trim(($registro['apellidos'] ?? '') . ' ' . ($registro['nombres'] ?? '')));

        $mail->Subject = $cfg['asunto'];
        $mail->Body    = email_renderCuerpo($registro, $cfg);
        $mail->AltBody = strip_tags(str_replace("\n", "\r\n", email_renderCuerpo($registro, $cfg)));

        $nombreArchivo = 'certificado_' . preg_replace('/\D/', '', $registro['dni'] ?? 'asistente') . '.pdf';
        $mail->addStringAttachment($pdfContent, $nombreArchivo, 'base64', 'application/pdf');

        $mail->send();

        $st = $conn->prepare("
            UPDATE asistencias
            SET cert_email_enviado = 1, cert_email_fecha = NOW()
            WHERE id = ?
        ");
        $st->bind_param("i", $registro['id']);
        $st->execute();
        $st->close();

        return ['ok' => true, 'msg' => 'Certificado enviado a ' . $destino];
    } catch (\Throwable $e) {
        $errDetail = !empty($mail->ErrorInfo) ? $mail->ErrorInfo : $e->getMessage();
        return ['ok' => false, 'msg' => 'Error al enviar: ' . $errDetail];
    }
}

/**
 * Envía un email con el enlace de descarga de un solo uso (sin adjunto).
 */
function email_enviarCertificadoLink(
    mysqli $conn,
    array $registro,
    string $instructor,
    string $link
): array {
    $cfg = email_cargarConfig($conn);

    if (!email_configurado($cfg)) {
        return ['ok' => false, 'msg' => 'El envío por email no está configurado.'];
    }

    $destino = strtolower(trim($registro['email'] ?? ''));
    if ($destino === '' || !filter_var($destino, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'msg' => 'El asistente no tiene un email válido.'];
    }

    try {
        $mail = email_crearMailer($cfg);

        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($destino, trim(($registro['apellidos'] ?? '') . ' ' . ($registro['nombres'] ?? '')));

        $mail->Subject = $cfg['asunto'];
        $mail->Body    = email_renderCuerpo($registro, $cfg, $link);
        $mail->AltBody = strip_tags(str_replace("\n", "\r\n", email_renderCuerpo($registro, $cfg, $link)));

        $mail->send();

        $st = $conn->prepare("
            UPDATE asistencias
            SET cert_email_enviado = 1, cert_email_fecha = NOW()
            WHERE id = ?
        ");
        $st->bind_param("i", $registro['id']);
        $st->execute();
        $st->close();

        return ['ok' => true, 'msg' => 'Enlace enviado a ' . $destino];
    } catch (\Throwable $e) {
        $errDetail = !empty($mail->ErrorInfo) ? $mail->ErrorInfo : $e->getMessage();
        return ['ok' => false, 'msg' => 'Error al enviar: ' . $errDetail];
    }
}
