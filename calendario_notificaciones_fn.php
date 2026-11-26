<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/email_certificado_fn.php";

function calNotif_asegurarTablas(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS calendario_eventos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            titulo VARCHAR(255) NOT NULL DEFAULT '',
            descripcion TEXT NULL,
            fecha_desde DATE NOT NULL,
            fecha_hasta DATE NOT NULL,
            hora_desde TIME DEFAULT NULL,
            hora_hasta TIME DEFAULT NULL,
            lugar VARCHAR(250) NOT NULL DEFAULT '',
            capacidad INT DEFAULT NULL,
            destinatarios_email TEXT NULL,
            destinatarios_whatsapp TEXT NULL,
            aviso_email TINYINT(1) NOT NULL DEFAULT 1,
            aviso_whatsapp TINYINT(1) NOT NULL DEFAULT 0,
            aviso_modal TINYINT(1) NOT NULL DEFAULT 1,
            dias_anticipacion INT NOT NULL DEFAULT 1,
            estado VARCHAR(30) NOT NULL DEFAULT 'programado',
            creado_por INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS calendario_notificaciones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            evento_id INT NOT NULL,
            tipo ENUM('email','whatsapp','modal') NOT NULL,
            enviado_a VARCHAR(255) NOT NULL DEFAULT '',
            fecha_envio DATETIME NOT NULL,
            programado_para DATETIME NOT NULL,
            estado ENUM('pendiente','enviado','error') NOT NULL DEFAULT 'pendiente',
            detalle TEXT NULL,
            INDEX idx_evento (evento_id),
            INDEX idx_estado (estado),
            INDEX idx_programado (programado_para)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $cols = [];
    $res = $conn->query("SHOW COLUMNS FROM calendario_eventos");
    if ($res) while ($c = $res->fetch_assoc()) $cols[] = $c['Field'];

    $migraciones = [
        'capacidad'            => 'INT DEFAULT NULL',
        'destinatarios_email'  => 'TEXT NULL',
        'destinatarios_whatsapp' => 'TEXT NULL',
        'aviso_email'          => "TINYINT(1) NOT NULL DEFAULT 1",
        'aviso_whatsapp'       => "TINYINT(1) NOT NULL DEFAULT 0",
        'aviso_modal'          => "TINYINT(1) NOT NULL DEFAULT 1",
        'dias_anticipacion'    => "INT NOT NULL DEFAULT 1",
        'estado'               => "VARCHAR(30) NOT NULL DEFAULT 'programado'",
        'creado_por'           => "INT DEFAULT NULL",
    ];
    foreach ($migraciones as $col => $tipo) {
        if (!in_array($col, $cols)) {
            @$conn->query("ALTER TABLE calendario_eventos ADD COLUMN $col $tipo");
        }
    }
}

function calNotif_enviarEmail(mysqli $conn, array $evento, string $destinos): array
{
    $cfg = email_cargarConfig($conn);
    if (!email_configurado($cfg)) {
        return ['ok' => false, 'msg' => 'Email no configurado. Configuralo en Configurar Email.'];
    }

    $emails = array_filter(array_map('trim', explode(',', $destinos)));
    $emails = array_filter($emails, function ($e) { return filter_var($e, FILTER_VALIDATE_EMAIL); });

    if (empty($emails)) {
        return ['ok' => false, 'msg' => 'No hay direcciones de email válidas.'];
    }

    $fechaEvento = date('d/m/Y', strtotime($evento['fecha_desde']));
    if ($evento['fecha_desde'] !== $evento['fecha_hasta']) {
        $fechaEvento .= ' al ' . date('d/m/Y', strtotime($evento['fecha_hasta']));
    }
    $hora = '';
    if (!empty($evento['hora_desde'])) {
        $hora = ' de ' . substr($evento['hora_desde'], 0, 5) . ' a ' . substr($evento['hora_hasta'], 0, 5) . ' hs';
    }

    $asunto = "Aviso de evento: " . $evento['titulo'];
    $cuerpo = "Estimado/a:\n\n"
        . "Le informamos que se realizará el siguiente evento:\n\n"
        . "📌 Evento: " . $evento['titulo'] . "\n"
        . "📅 Fecha: " . $fechaEvento . $hora . "\n"
        . "📍 Lugar: " . $evento['lugar'] . "\n";
    if (!empty($evento['descripcion'])) {
        $cuerpo .= "📝 Detalle: " . strip_tags($evento['descripcion']) . "\n";
    }
    $cuerpo .= "\nMunicipalidad de Esperanza\nCeCap - Centro de Capacitación";

    $enviados = 0;
    $errores = 0;

    try {
        $mail = email_crearMailer($cfg);
        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->Subject = $asunto;
        $mail->Body = $cuerpo;
        $mail->AltBody = $cuerpo;

        foreach ($emails as $email) {
            try {
                $mail->clearAddresses();
                $mail->addAddress($email);
                $mail->send();
                $enviados++;
            } catch (\Throwable $e) {
                $errores++;
            }
        }

        return [
            'ok' => $enviados > 0,
            'msg' => "Enviados: $enviados" . ($errores > 0 ? " | Errores: $errores" : ''),
        ];
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => 'Error: ' . $e->getMessage()];
    }
}

function calNotif_registrarNotificacion(
    mysqli $conn,
    int $eventoId,
    string $tipo,
    string $enviadoA,
    string $programadoPara,
    string $estado = 'enviado',
    string $detalle = ''
): void {
    $st = $conn->prepare("
        INSERT INTO calendario_notificaciones (evento_id, tipo, enviado_a, fecha_envio, programado_para, estado, detalle)
        VALUES (?, ?, ?, NOW(), ?, ?, ?)
    ");
    $st->bind_param("isssss", $eventoId, $tipo, $enviadoA, $programadoPara, $estado, $detalle);
    $st->execute();
    $st->close();
}

function calNotif_procesarPendientes(mysqli $conn): array
{
    $ahora = date('Y-m-d H:i:s');
    $resultados = ['emails' => 0, 'whatsapp_links' => 0, 'modales' => 0];

    $res = $conn->query("
        SELECT e.* FROM calendario_eventos e
        WHERE e.estado = 'programado'
          AND e.fecha_desde >= CURDATE()
    ");
    if (!$res || $res->num_rows === 0) return $resultados;

    while ($evento = $res->fetch_assoc()) {
        $eid = (int)$evento['id'];
        $diasAnt = max(1, (int)$evento['dias_anticipacion']);
        $fechaAviso = date('Y-m-d', strtotime($evento['fecha_desde'] . " -$diasAnt days"));
        $horaAviso = !empty($evento['hora_desde']) ? substr($evento['hora_desde'], 0, 5) : '09:00';
        $programado = $fechaAviso . ' ' . $horaAviso . ':00';

        if ($programado > $ahora) continue;

        $programadoTs = strtotime($programado);
        $margen = strtotime($ahora) - $programadoTs;
        if ($margen < 0 || $margen > 3600) continue;

        $yaEnviado = $conn->query("
            SELECT COUNT(*) AS total FROM calendario_notificaciones
            WHERE evento_id = $eid
              AND programado_para = '" . $conn->real_escape_string($programado) . "'
              AND estado = 'enviado'
        ");
        $cnt = $yaEnviado ? (int)$yaEnviado->fetch_assoc()['total'] : 0;
        if ($cnt > 0) continue;

        if ((int)$evento['aviso_email'] && !empty($evento['destinatarios_email'])) {
            $r = calNotif_enviarEmail($conn, $evento, $evento['destinatarios_email']);
            $resTipo = $r['ok'] ? 'enviado' : 'error';
            calNotif_registrarNotificacion($conn, $eid, 'email', $evento['destinatarios_email'], $programado, $resTipo, $r['msg']);
            if ($r['ok']) $resultados['emails']++;
        }

        if ((int)$evento['aviso_whatsapp'] && !empty($evento['destinatarios_whatsapp'])) {
            calNotif_registrarNotificacion($conn, $eid, 'whatsapp', $evento['destinatarios_whatsapp'], $programado, 'enviado', 'Link generado');
            $resultados['whatsapp_links']++;
        }

        if ((int)$evento['aviso_modal']) {
            calNotif_registrarNotificacion($conn, $eid, 'modal', 'panel', $programado, 'enviado', 'Modal activo');
            $resultados['modales']++;
        }
    }

    return $resultados;
}

function calNotif_eventoModalActivo(mysqli $conn): ?array
{
    $res = $conn->query("
        SELECT e.* FROM calendario_eventos e
        WHERE e.estado = 'programado'
          AND e.aviso_modal = 1
          AND e.fecha_desde >= CURDATE()
          AND e.fecha_desde <= DATE_ADD(CURDATE(), INTERVAL e.dias_anticipacion DAY)
        ORDER BY e.fecha_desde ASC
        LIMIT 1
    ");
    if ($res && $res->num_rows > 0) {
        return $res->fetch_assoc();
    }
    return null;
}

function calNotif_generarLinksWhatsApp(array $evento): array
{
    $numeros = array_filter(array_map('trim', explode(',', $evento['destinatarios_whatsapp'] ?? '')));
    $numeros = array_filter($numeros);

    $fechaEvento = date('d/m/Y', strtotime($evento['fecha_desde']));
    if ($evento['fecha_desde'] !== $evento['fecha_hasta']) {
        $fechaEvento .= ' al ' . date('d/m/Y', strtotime($evento['fecha_hasta']));
    }
    $hora = '';
    if (!empty($evento['hora_desde'])) {
        $hora = ' de ' . substr($evento['hora_desde'], 0, 5) . ' a ' . substr($evento['hora_hasta'], 0, 5) . ' hs';
    }

    $mensaje = "Aviso CeCap:\n"
        . "📌 " . $evento['titulo'] . "\n"
        . "📅 " . $fechaEvento . $hora . "\n"
        . "📍 " . $evento['lugar'];
    if (!empty($evento['descripcion'])) {
        $mensaje .= "\n📝 " . strip_tags($evento['descripcion']);
    }

    $links = [];
    foreach ($numeros as $num) {
        $numLimpio = preg_replace('/\D/', '', $num);
        if (strlen($numLimpio) >= 8) {
            $links[] = [
                'numero' => $num,
                'url' => 'https://wa.me/' . $numLimpio . '?text=' . urlencode($mensaje),
            ];
        }
    }
    return $links;
}
