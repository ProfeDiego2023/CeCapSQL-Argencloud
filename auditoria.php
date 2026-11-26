<?php
session_start();
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/functions.php";

if (!isset($_SESSION['id_instru'])) {
    header("Location: login.php"); exit;
}
if (!permiso("admin")) {
    header("Location: panel.php"); exit;
}

$conn->set_charset("utf8mb4");

// Registrar actividad
$stmt_act = $conn->prepare("UPDATE usuarios SET last_activity = NOW() WHERE id=?");
$stmt_act->bind_param("i", $_SESSION['id_instru']);
$stmt_act->execute();
$stmt_act->close();

/* =========================
   FILTROS — prepared statements
========================= */
$usuario_id    = isset($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : 0;
$accion_filtro = trim($_GET['accion'] ?? '');
$desde         = trim($_GET['desde']  ?? '');
$hasta         = trim($_GET['hasta']  ?? '');
$export        = isset($_GET['export']);

$acciones_posibles = [
    'LOGIN','LOGOUT','ERROR LOGIN',
    'CREAR USUARIO','EDITAR USUARIO','ELIMINAR USUARIO',
    'ESTADO USUARIO','OTRA ACCIÓN'
];

// Validar accion
if ($accion_filtro && !in_array($accion_filtro, $acciones_posibles)) {
    $accion_filtro = '';
}

// Construir WHERE dinámico con prepared statement
$conds  = [];
$ptypes = '';
$pvals  = [];

if ($usuario_id > 0) {
    $conds[] = "a.id_usuario = ?"; $ptypes .= "i"; $pvals[] = $usuario_id;
}
if ($accion_filtro) {
    $conds[] = "a.accion = ?"; $ptypes .= "s"; $pvals[] = $accion_filtro;
}
if ($desde && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
    $conds[] = "a.fecha >= ?"; $ptypes .= "s"; $pvals[] = $desde . " 00:00:00";
}
if ($hasta && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
    $conds[] = "a.fecha <= ?"; $ptypes .= "s"; $pvals[] = $hasta . " 23:59:59";
}

$where = $conds ? "WHERE " . implode(" AND ", $conds) : "";

/* =========================
   EXPORTAR CSV
========================= */
if ($export) {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=auditoria_cecap_" . date('Ymd_His') . ".csv");

    $sql_exp = "
        SELECT a.fecha, u.usuario,
               CONCAT(COALESCE(u.apellidos,''), ', ', COALESCE(u.nombre,'')) AS nombre_completo,
               u.rol, a.accion,
               COALESCE(a.detalle, '') AS descripcion,
               a.ip, u.last_activity
        FROM auditoria a
        LEFT JOIN usuarios u ON a.id_usuario = u.id
        $where
        ORDER BY a.fecha DESC
    ";
    $st = $conn->prepare($sql_exp);
    if ($ptypes) $st->bind_param($ptypes, ...$pvals);
    $st->execute();
    $res_exp = $st->get_result();

    $out = fopen("php://output", "w");
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
    fputcsv($out, ["Fecha","Usuario","Nombre completo","Rol","Acción","Descripción","IP","Estado"]);
    while ($row = $res_exp->fetch_assoc()) {
        $online = !empty($row['last_activity']) &&
                  strtotime($row['last_activity']) >= strtotime("-5 minutes");
        fputcsv($out, [
            $row['fecha'],
            $row['usuario'] ?? '—',
            $row['nombre_completo'],
            $row['rol'] ?? '',
            $row['accion'],
            $row['descripcion'],
            $row['ip'],
            $online ? 'En línea' : 'Offline',
        ]);
    }
    fclose($out);
    $st->close();
    exit;
}

/* =========================
   QUERY PRINCIPAL
========================= */
$sql = "
    SELECT a.fecha, a.accion,
           COALESCE(a.detalle, '') AS descripcion,
           a.ip,
           u.usuario, u.nombre, u.apellidos, u.rol, u.last_activity
    FROM auditoria a
    LEFT JOIN usuarios u ON a.id_usuario = u.id
    $where
    ORDER BY a.fecha DESC
    LIMIT 500
";
$st = $conn->prepare($sql);
if ($ptypes) $st->bind_param($ptypes, ...$pvals);
$st->execute();
$res = $st->get_result();
$st->close();

/* =========================
   USUARIOS PARA FILTRO
========================= */
$usuarios_res = $conn->query("SELECT id, usuario FROM usuarios ORDER BY usuario ASC");

/* =========================
   USUARIOS EN LÍNEA
========================= */
$online_count = (int)$conn->query("
    SELECT COUNT(*) AS c FROM usuarios
    WHERE last_activity >= (NOW() - INTERVAL 5 MINUTE)
")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Auditoría — CeCap</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/variables.css">
<style>
body {
    background: linear-gradient(135deg,rgba(0,0,0,.80),rgba(0,0,0,.80)),
                url("img/FondoHexangonosAzulesII.jpg") center/cover fixed;
    color:#fff; min-height:100vh; font-family:'Segoe UI',sans-serif;
}
.card-cecap {
    background:rgba(15,25,40,.88);
    border-radius:18px;
    border:1px solid rgba(199,228,207,.35);
    box-shadow:0 0 24px rgba(0,0,0,.5);
}
.dot-online  {display:inline-block;width:11px;height:11px;background:#C7E4CF;border-radius:50%;box-shadow:0 0 6px #C7E4CF}
.dot-offline {display:inline-block;width:11px;height:11px;background:#d10000;border-radius:50%;box-shadow:0 0 6px #f00}
.badge-neon  {background:rgba(199,228,207,.15);border:1px solid rgba(199,228,207,.7);color:#5D5E70}

/* Inputs en dark */
.form-control,.form-select {
    background:rgba(0,0,0,.35)!important;
    border:1px solid rgba(255,255,255,.2)!important;
    color:#fff!important; border-radius:10px!important;
}
.form-control:focus,.form-select:focus {
    border-color:#5D5E70!important;
    box-shadow:0 0 0 2px rgba(93,94,112,.25)!important;
}
.form-select option {background:#1b2a3a;color:#fff}
.form-label {font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#C7E4CF;margin-bottom:3px}

/* Tabla */
.table-cecap { color:#F0EDF2; font-size:.88rem }
.table-cecap thead th {
    background:rgba(80,66,84,.35); color:#C7E4CF;
    font-size:.75rem; text-transform:uppercase;
    position:sticky; top:0; z-index:1; white-space:nowrap;
}
.table-cecap tbody tr {transition:background .12s}
.table-cecap tbody tr:hover {background:rgba(255,255,255,.07)!important}
.table-cecap tbody tr:nth-child(even) {background:rgba(255,255,255,.03)}

.badge-accion {
    font-size:.72rem; padding:3px 9px; border-radius:20px; font-weight:600;
}
.ac-LOGIN     {background:#DD4841}
.ac-LOGOUT    {background:#6c757d}
.ac-ERROR     {background:#dc3545}
.ac-CREAR     {background:#198754}
.ac-EDITAR    {background:#fd7e14}
.ac-ELIMINAR  {background:#dc3545}
.ac-ESTADO    {background:#6f42c1}
.ac-OTRA      {background:#C7E4CF}

.tabla-wrap {max-height:60vh; overflow-y:auto}
.tabla-wrap::-webkit-scrollbar{width:5px}
.tabla-wrap::-webkit-scrollbar-thumb{background:rgba(93,94,112,.4);border-radius:3px}

#inputBuscar::placeholder {color:rgba(255,255,255,.4)!important}
</style>
</head>
<body class="py-4">
<div class="container-fluid px-4">

    <!-- HEADER -->
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
        <div>
            <h2 class="fw-bold mb-1">🧾 Auditoría del Sistema</h2>
            <p class="mb-0 text-secondary">Login, logout y acciones sobre usuarios y registros.</p>
        </div>
        <div class="text-end">
            <div class="mb-2">
                <span class="dot-online"></span>
                <span class="badge badge-neon rounded-pill ms-1"><?= $online_count ?></span>
                <small class="ms-1 text-secondary">usuario<?= $online_count !== 1 ? 's' : '' ?> en línea</small>
            </div>
            <a href="panel.php" class="btn btn-sm btn-outline-info">⬅ Volver al Panel</a>
        </div>
    </div>

    <!-- FILTROS -->
    <div class="card card-cecap mb-4">
        <div class="card-body">
            <form class="row g-3" method="GET" action="auditoria.php">
                <div class="col-md-3 col-sm-6">
                    <label class="form-label">Usuario</label>
                    <select name="usuario_id" class="form-select">
                        <option value="0">Todos</option>
                        <?php while ($u = $usuarios_res->fetch_assoc()): ?>
                        <option value="<?= $u['id'] ?>" <?= $usuario_id == $u['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['usuario']) ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="col-md-3 col-sm-6">
                    <label class="form-label">Acción</label>
                    <select name="accion" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($acciones_posibles as $ac): ?>
                        <option value="<?= htmlspecialchars($ac) ?>" <?= $accion_filtro === $ac ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ac) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2 col-sm-6">
                    <label class="form-label">Desde</label>
                    <input type="date" name="desde" class="form-control"
                           value="<?= htmlspecialchars($desde) ?>">
                </div>

                <div class="col-md-2 col-sm-6">
                    <label class="form-label">Hasta</label>
                    <input type="date" name="hasta" class="form-control"
                           value="<?= htmlspecialchars($hasta) ?>">
                </div>

                <div class="col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-info fw-bold flex-fill">🔍 Filtrar</button>
                    <a href="auditoria.php" class="btn btn-outline-secondary" title="Limpiar filtros">✕</a>
                </div>

                <div class="col-12 d-flex justify-content-between align-items-center mt-1">
                    <input type="text" id="inputBuscar" class="form-control form-control-sm w-auto"
                           placeholder="🔍 Buscar en resultados..." style="min-width:220px">
                    <a href="auditoria.php?<?= http_build_query(array_merge($_GET, ['export' => 1])) ?>"
                       class="btn btn-outline-success btn-sm">
                        ⬇ Exportar CSV
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- TABLA -->
    <div class="card card-cecap">
        <div class="card-body p-0">
            <div class="tabla-wrap">
                <table class="table table-cecap table-borderless mb-0" id="tablaAuditoria">
                    <thead>
                        <tr>
                            <th class="ps-3">Fecha</th>
                            <th>Usuario</th>
                            <th>Acción</th>
                            <th>Descripción</th>
                            <th>IP</th>
                            <th class="text-center pe-3">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($res->num_rows === 0): ?>
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-4">
                                No se encontraron registros con los filtros aplicados.
                            </td>
                        </tr>
                    <?php else:
                        while ($row = $res->fetch_assoc()):
                            $nombre  = trim(($row['apellidos'] ?? '') . ', ' . ($row["nombre"] ?? ''));
                            $nombre  = ($nombre === ', ' || $nombre === ', ') ? '' : trim($nombre, ', ');
                            $online  = !empty($row['last_activity']) &&
                                       strtotime($row['last_activity']) >= strtotime("-5 minutes");

                            // Badge color según acción
                            $ac = strtoupper($row['accion']);
                            $badge_cls = match(true) {
                                str_contains($ac,'LOGIN') && !str_contains($ac,'ERROR') => 'ac-LOGIN',
                                str_contains($ac,'LOGOUT')   => 'ac-LOGOUT',
                                str_contains($ac,'ERROR')    => 'ac-ERROR',
                                str_contains($ac,'CREAR')    => 'ac-CREAR',
                                str_contains($ac,'EDITAR')   => 'ac-EDITAR',
                                str_contains($ac,'ELIMINAR') => 'ac-ELIMINAR',
                                str_contains($ac,'ESTADO')   => 'ac-ESTADO',
                                default                      => 'ac-OTRA',
                            };
                    ?>
                        <tr>
                            <td class="ps-3 text-nowrap">
                                <?= htmlspecialchars(date('d/m/Y H:i:s', strtotime($row['fecha']))) ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($row['usuario'] ?? '—') ?></strong>
                                <?php if ($nombre): ?>
                                <br><small class="text-secondary"><?= htmlspecialchars($nombre) ?>
                                <?= $row['rol'] ? ' · ' . strtoupper(htmlspecialchars($row['rol'])) : '' ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-accion <?= $badge_cls ?>">
                                    <?= htmlspecialchars($row['accion']) ?>
                                </span>
                            </td>
                            <td><?= nl2br(htmlspecialchars($row['descripcion'])) ?></td>
                            <td class="text-nowrap"><?= htmlspecialchars($row['ip'] ?? '') ?></td>
                            <td class="text-center pe-3">
                                <?php if ($online): ?>
                                    <span class="dot-online"></span> <small>En línea</small>
                                <?php else: ?>
                                    <span class="dot-offline"></span> <small>Offline</small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-end text-secondary py-2 pe-3 mb-0" style="font-size:.78rem">
                Mostrando hasta 500 registros recientes.
            </p>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Búsqueda en vivo
document.getElementById('inputBuscar').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#tablaAuditoria tbody tr').forEach(tr => {
        tr.style.display = tr.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>
</body>
</html>