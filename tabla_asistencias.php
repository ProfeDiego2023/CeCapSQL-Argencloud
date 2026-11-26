<?php
session_start();
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/conexion.php";
proteger();

/* ══════════════════════════════════════════════
   MODO: ?get_id=X  → devuelve JSON con 1 registro
   MODO: normal     → devuelve <tr> rows para refresh
══════════════════════════════════════════════ */
if (isset($_GET['get_id'])) {
    header("Content-Type: application/json");
    $id   = (int)$_GET['get_id'];
    $stmt = $conn->prepare("
        SELECT *
        FROM asistencias
        WHERE id = ? AND oculto = 0
        LIMIT 1
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$r) { echo json_encode(['error' => 'not found']); exit; }
    // Normalizar fecha_dia
    if (empty($r['fecha_dia']) && !empty($r['fecha'])) {
        $r['fecha_dia'] = substr($r['fecha'], 0, 10);
    }
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ── MODO ROWS (refresh tabla) ── */
$stmt = $conn->prepare("
    SELECT *
    FROM asistencias
    WHERE oculto = 0
    ORDER BY id DESC
    LIMIT 20
");
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

$primero = true;
while ($r = $res->fetch_assoc()):
    $cls = $primero ? 'fila-top' : '';
    if (!empty($r['fecha_dia']) && $r['fecha_dia'] !== '0000-00-00') {
        $fechaFmt = date("d/m/Y", strtotime($r['fecha_dia']));
    } elseif (!empty($r['fecha']) && $r['fecha'] !== '0000-00-00 00:00:00') {
        $fechaFmt = date("d/m/Y", strtotime($r['fecha']));
    } else {
        $fechaFmt = '-';
    }

    $esEsc = (!empty($r['es_escuela']) || preg_match('/\bESC(UELA)?\b/i', $r['lugar'] ?? ''));
    $lugarTxt = htmlspecialchars($r['lugar'] ?? '-');
    $lugarFmt = $esEsc
        ? '<span class="badge bg-warning text-dark me-1" title="Capacitación en Escuela">🏫</span> ' . $lugarTxt
        : $lugarTxt;
    $nombreCompleto = htmlspecialchars(trim(($r['apellidos'] ?? '') . ' ' . ($r['nombres'] ?? '')));
?>
<tr class="<?= $cls ?>" data-id="<?= $r['id'] ?>" data-nombre="<?= $nombreCompleto ?>">
    <td><?= $r['id'] ?></td>
    <td><?= $fechaFmt ?></td>
    <td><?= htmlspecialchars($r['apellidos']) ?></td>
    <td><?= htmlspecialchars($r['nombres']) ?></td>
    <td><?= htmlspecialchars($r['dni']) ?></td>
    <td><?= htmlspecialchars($r['edad'] ?? '-') ?></td>
    <td><?= htmlspecialchars($r['genero'] ?? '-') ?></td>
    <td><?= $lugarFmt ?></td>
    <td><?= htmlspecialchars($r['licencia'] ?? '-') ?></td>
    <td><?= htmlspecialchars($r['turno'] ?? '-') ?></td>
    <td><?= htmlspecialchars(strtolower($r['email'] ?? '-')) ?></td>
    <td class="text-center" style="white-space: nowrap;">
        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 btn-eliminar-asist"
                data-id="<?= $r['id'] ?>"
                data-nombre="<?= $nombreCompleto ?>"
                title="Eliminar este asistente">
            <i class="bi bi-trash3-fill"></i>
        </button>
    </td>
</tr>
<?php
    $primero = false;
endwhile;

if ($res->num_rows === 0):
?>
<tr><td colspan="12" class="text-center text-muted">Sin registros</td></tr>
<?php endif; ?>
