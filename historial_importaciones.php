<?php
session_start();
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/conexion.php";

proteger();

if (!permiso("ver_todos")) {
    die("Acceso denegado");
}

$sql = "
SELECT i.*, u.nombre AS usuario_nombre
FROM importaciones i
LEFT JOIN usuarios u ON i.usuario_id = u.id
ORDER BY i.fecha DESC
";

$res = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Historial de Importaciones</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-dark text-light">

<div class="container mt-5">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3>📂 Historial de Importaciones</h3>
        <a href="panel.php" class="btn btn-outline-light">⬅ Volver</a>
    </div>

    <div class="table-responsive">
        <table class="table table-dark table-striped align-middle">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Usuario</th>
                    <th>Archivo</th>
                    <th class="text-center">Insertados</th>
                    <th class="text-center">Actualizados</th>
                    <th class="text-center">Errores</th>
                </tr>
            </thead>
            <tbody>

            <?php if ($res->num_rows > 0): ?>
                <?php while ($row = $res->fetch_assoc()): ?>
                    <tr>
                        <td><?= date("d/m/Y H:i", strtotime($row['fecha'])) ?></td>
                        <td><?= htmlspecialchars($row['usuario_nombre'] ?? 'Desconocido') ?></td>
                        <td><?= htmlspecialchars($row['archivo_nombre']) ?></td>

                        <td class="text-center text-success fw-bold">
                            <?= $row['total_insertados'] ?>
                        </td>

                        <td class="text-center text-warning fw-bold">
                            <?= $row['total_actualizados'] ?>
                        </td>

                        <td class="text-center text-danger fw-bold">
                            <?= $row['total_errores'] ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" class="text-center text-muted">
                        No hay importaciones registradas.
                    </td>
                </tr>
            <?php endif; ?>

            </tbody>
        </table>
    </div>

</div>

</body>
</html>