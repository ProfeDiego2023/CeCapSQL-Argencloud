<?php
/**
 * motor_eventos.php
 *
 * Devuelve ['lugar', 'turno', 'categoria'] según el día y horario actual.
 *
 * Prioridad:
 * 1. config_form_eventos (BD) — si hay un evento activo con fecha y hora válida,
 *    sus valores REEMPLAZAN COMPLETAMENTE las reglas automáticas.
 * 2. Reglas automáticas por día/hora — definidas acá abajo.
 *
 * Si no corresponde ninguna regla, devuelve valores vacíos → formulario bloqueado.
 */

date_default_timezone_set("America/Argentina/Buenos_Aires");

$hoy       = date("Y-m-d");
$hora      = date("H:i:s");
$diaSemana = (int)date("N"); // 1=Lunes … 7=Domingo
$diaDelMes = (int)date("j");
$numSemana = (int)ceil($diaDelMes / 7); // 1°, 2°, 3°, 4° semana del mes

$lugar     = '';
$turno     = '';
$categoria = '';

/* ══════════════════════════════════════════════════════════
   1. EVENTOS ESPECIALES (BD) — PRIORIDAD MÁXIMA
   Si hay un evento activo que coincide con fecha (y hora
   si fue configurada), sus valores reemplazan TODO y
   se retorna de inmediato sin evaluar reglas automáticas.
══════════════════════════════════════════════════════════ */

if (isset($conn)) {
    $stmt = $conn->prepare("
        SELECT lugar, turno, licencia, hora_desde, hora_hasta
        FROM config_form_eventos
        WHERE activo      = 1
          AND fecha_desde <= ?
          AND fecha_hasta >= ?
        ORDER BY id DESC
    ");
    $stmt->bind_param("ss", $hoy, $hoy);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($e = $res->fetch_assoc()) {

        // Si el evento tiene rango horario, verificarlo
        if (!empty($e['hora_desde']) && !empty($e['hora_hasta'])) {
            if ($hora < $e['hora_desde'] || $hora > $e['hora_hasta']) {
                continue; // fuera del horario configurado, probar el siguiente
            }
        }

        // ✅ Evento válido encontrado → tomar sus valores y salir
        $lugar     = mb_strtoupper(trim($e['lugar']    ?? ''), 'UTF-8');
        $turno     = mb_strtoupper(trim($e['turno']    ?? ''), 'UTF-8');
        $categoria = mb_strtoupper(trim($e['licencia'] ?? ''), 'UTF-8');

        $stmt->close();

        // Retornar inmediatamente — las reglas automáticas no se evalúan
        return [
            'lugar'     => $lugar,
            'turno'     => $turno,
            'categoria' => $categoria,
        ];
    }
    $stmt->close();
}

/* ══════════════════════════════════════════════════════════
   2. REGLAS AUTOMÁTICAS POR DÍA (desde BD)
   Solo se ejecutan si ningún evento especial aplica.
══════════════════════════════════════════════════════════ */

if (isset($conn)) {
    $stmt = $conn->prepare("
        SELECT * FROM config_reglas_dias
        WHERE activo = 1 AND dia_semana = ?
        ORDER BY orden_sabado ASC
    ");
    $stmt->bind_param("i", $diaSemana);
    $stmt->execute();
    $resReglas = $stmt->get_result();

    while ($r = $resReglas->fetch_assoc()) {
        // Para Sábado, verificar orden (semana del mes)
        if ((int)$r['orden_sabado'] > 0 && (int)$r['orden_sabado'] !== $numSemana) {
            continue;
        }

        // Evaluar cada turno
        $turnos = [
            ['nombre' => $r['turno1_nombre'], 'desde' => $r['turno1_desde'], 'hasta' => $r['turno1_hasta']],
            ['nombre' => $r['turno2_nombre'], 'desde' => $r['turno2_desde'], 'hasta' => $r['turno2_hasta']],
            ['nombre' => $r['turno3_nombre'], 'desde' => $r['turno3_desde'], 'hasta' => $r['turno3_hasta']],
        ];

        foreach ($turnos as $t) {
            if (empty($t['nombre'])) continue;
            if (!empty($t['desde']) && !empty($t['hasta'])) {
                if ($hora < $t['desde'] || $hora > $t['hasta']) {
                    continue;
                }
            }
            // Turno encontrado
            $lugar     = mb_strtoupper(trim($r['lugar']), 'UTF-8');
            $categoria = mb_strtoupper(trim($r['categoria']), 'UTF-8');
            $turno     = mb_strtoupper(trim($t['nombre']), 'UTF-8');
            break 2;
        }
    }
    $stmt->close();
}

return [
    'lugar'     => $lugar,
    'turno'     => $turno,
    'categoria' => $categoria,
];