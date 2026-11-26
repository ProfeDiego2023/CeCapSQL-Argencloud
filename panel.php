<?php
session_start();

require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/auditar.php";

/* ==============================
   ACCESO
================================ */
proteger(); // redirige a login.php si no hay sesión

$nombre = $_SESSION['nombre'] ?? $_SESSION['usuario'] ?? "Usuario";

/* Auditoría de ingreso (una sola vez por sesión) */
if (empty($_SESSION['audit_panel'])) {
  auditar($conn, "INGRESO_PANEL", "panel");
  $_SESSION['audit_panel'] = true;
}

/* ==============================
   DASHBOARD — QUERIES SEGURAS
================================ */
$hoy        = date('Y-m-d');
$inicio_mes = date('Y-m-01');
$fechaRef   = "CASE
  WHEN fecha_dia IS NOT NULL AND CAST(fecha_dia AS CHAR(10)) NOT IN ('', '0000-00-00') THEN fecha_dia
  WHEN fecha IS NOT NULL AND CAST(fecha AS CHAR(19)) NOT IN ('', '0000-00-00 00:00:00') THEN DATE(fecha)
  ELSE NULL
END";

// Total general
$st = $conn->prepare("SELECT COUNT(*) AS total FROM asistencias WHERE oculto = 0");
$st->execute();
$total = (int)($st->get_result()->fetch_assoc()['total'] ?? 0);
$st->close();

// Hoy (usa fecha_dia; si falta o es inválida, toma DATE(fecha))
$st = $conn->prepare("SELECT COUNT(*) AS total FROM asistencias WHERE oculto = 0 AND $fechaRef = ?");
$st->bind_param("s", $hoy);
$st->execute();
$total_hoy = (int)($st->get_result()->fetch_assoc()['total'] ?? 0);
$st->close();

// Este mes (acumulado del 1° al día de hoy)
$st = $conn->prepare("SELECT COUNT(*) AS total FROM asistencias WHERE oculto = 0 AND $fechaRef BETWEEN ? AND ?");
$st->bind_param("ss", $inicio_mes, $hoy);
$st->execute();
$total_mes = (int)($st->get_result()->fetch_assoc()['total'] ?? 0);
$st->close();

// Turno más activo del mes (mismo rango: 1° a hoy)
$turno_top      = "-";
$turno_cantidad = 0;

$st = $conn->prepare("
    SELECT turno, COUNT(*) AS cantidad
    FROM asistencias
    WHERE oculto = 0 AND $fechaRef BETWEEN ? AND ?
    GROUP BY turno
    ORDER BY cantidad DESC
    LIMIT 1
");
$st->bind_param("ss", $inicio_mes, $hoy);
$st->execute();
$fila = $st->get_result()->fetch_assoc();
$st->close();

if ($fila) {
  $turno_top      = htmlspecialchars((string)($fila['turno'] ?? '-'), ENT_QUOTES, 'UTF-8');
  $turno_cantidad = (int)($fila['cantidad'] ?? 0);
}

/* Datos para gráfico (preparados aunque esté comentado) */
$st = $conn->prepare("
    SELECT $fechaRef AS fecha_ref, COUNT(*) AS cantidad
    FROM asistencias
    WHERE oculto = 0 AND $fechaRef BETWEEN ? AND ?
    GROUP BY fecha_ref
    ORDER BY fecha_ref ASC
");
$st->bind_param("ss", $inicio_mes, $hoy);
$st->execute();
$res_mes = $st->get_result();
$st->close();

$labels_mes = [];
$data_mes   = [];
while ($row = $res_mes->fetch_assoc()) {
  $labels_mes[] = !empty($row['fecha_ref']) ? date("d/m", strtotime($row['fecha_ref'])) : '-';
  $data_mes[]   = (int)$row['cantidad'];
}

/* Modal de Actualización Activo */
$modal_actualizacion = null;
$conn->query("
    CREATE TABLE IF NOT EXISTS modales_actualizacion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titulo VARCHAR(255) NOT NULL,
        version VARCHAR(50) DEFAULT NULL,
        contenido TEXT NOT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
        fecha_actualizacion DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$st_m = $conn->prepare("SELECT id, titulo, version, contenido FROM modales_actualizacion WHERE activo = 1 ORDER BY id DESC LIMIT 1");
if ($st_m) {
    $st_m->execute();
    $modal_actualizacion = $st_m->get_result()->fetch_assoc();
    $st_m->close();
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <title>Panel CeCap</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/variables.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

  <!-- Reemplazá todo el bloque <style>...</style> del <head> con esto: -->

  <style>
    :root {
      --glass: rgba(255, 255, 255, 0.06);
      --border: rgba(255, 255, 255, 0.10);
      --shadow: 0 14px 35px rgba(0, 0, 0, 0.40);
      --text: #F0EDF2;
      --muted: rgba(199, 228, 207, 0.65);
      --topbar-h: 88px;
      --footer-h: 70px;
    }

    * {
      box-sizing: border-box;
    }

    body {
      min-height: 100vh;
      margin: 0;
      color: var(--text);
      font-family: 'Segoe UI', sans-serif;
      background: linear-gradient(135deg, #504254, #3C313F);
      background-attachment: fixed;
      padding-top: var(--topbar-h);
      padding-bottom: var(--footer-h);
    }

    /* ── Top bar ── */
    .topbar {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      z-index: 1200;
      padding: 14px 16px;
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      background: rgba(255, 255, 255, 0.06);
      border-bottom: 1px solid var(--border);
    }

    .topbar-inner {
      max-width: 1200px;
      margin: 0 auto;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 10px;
      min-width: 220px;
    }

    .brand img {
      width: 42px;
      height: 42px;
      object-fit: contain;
      filter: drop-shadow(0 8px 18px rgba(0, 0, 0, 0.35));
    }

    .brand .title strong {
      font-size: 1.05rem;
      display: block;
    }

    .brand .title span {
      font-size: .85rem;
      color: var(--muted);
    }

    .welcome {
      display: flex;
      align-items: center;
      gap: 10px;
      background: rgba(255, 255, 255, 0.06);
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 10px 14px;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.25);
    }

    .welcome i {
      font-size: 1.25rem;
    }

    .welcome .txt b {
      color: #C7E4CF;
    }

    /* ── Wrap y glass ── */
    .wrap {
      max-width: 1200px;
      margin: 0 auto;
      padding: 20px 14px;
    }

    .dashboard-glass {
      width: 100%;
      padding: 28px 26px;
      border-radius: 20px;
      background: rgba(255, 255, 255, 0.06);
      border: 1px solid var(--border);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      box-shadow: 0 25px 70px rgba(0, 0, 0, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.10);
      position: relative;
      overflow: hidden;
    }

    .dashboard-glass::before {
      content: "";
      position: absolute;
      top: -120px;
      right: -120px;
      width: 280px;
      height: 280px;
      background: radial-gradient(circle, rgba(199, 228, 207, 0.12), transparent 65%);
      pointer-events: none;
    }

    /* ── Grid 4 columnas ── */
    .grid {
      width: 100%;
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 16px;
      margin-top: 10px;
    }

    /* ── Card compacta ── */
    .card-glass {
      min-height: 130px;
      padding: 16px 18px;
      border-radius: 20px;
      text-decoration: none;
      color: var(--text);
      background: var(--glass);
      border: 1px solid var(--border);
      border-top: 3px solid var(--accent-primary);
      box-shadow: var(--shadow);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      transition: transform .18s ease, box-shadow .18s ease, background .18s ease;
      position: relative;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    .card-glass::before {
      content: "";
      position: absolute;
      inset: -30px -40px auto auto;
      width: 130px;
      height: 130px;
      background: radial-gradient(circle at center, rgba(199, 228, 207, 0.12), transparent 65%);
      transform: rotate(15deg);
    }

    .card-glass:hover {
      transform: translateY(-3px);
      background: rgba(255, 255, 255, 0.14);
      box-shadow: 0 18px 50px rgba(0, 0, 0, 0.55);
    }

    .card-top {
      display: flex;
      align-items: center;
      gap: 12px;
      position: relative;
      z-index: 1;
    }

    .card-title {
      font-size: 0.95rem;
      font-weight: 800;
      line-height: 1.2;
      margin: 0 0 4px;
    }

    .card-desc {
      font-size: 0.78rem;
      color: var(--muted);
      margin: 0;
      line-height: 1.3;
    }

    .icon-badge {
      width: 52px;
      height: 52px;
      border-radius: 13px;
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      overflow: hidden;
      background: rgba(0, 0, 0, 0.28);
      border: 1px solid rgba(255, 255, 255, 0.18);
      box-shadow: 0 6px 16px rgba(0, 0, 0, 0.25);
      flex-shrink: 0;
    }

    /* Capa de color = mismo tono del borde superior de la card, con transparencia */
    .icon-badge::before {
      content: "";
      position: absolute;
      inset: 0;
      background: var(--accent-primary);
      opacity: .32;
    }

    .icon-badge i {
      font-size: 1.55rem;
      line-height: 1;
      position: relative;
      z-index: 1;
    }

    .card-arrow {
      margin-left: auto;
      opacity: .7;
      font-size: 1rem;
      position: relative;
      z-index: 1;
      flex-shrink: 0;
    }

    .card-glass:hover .card-arrow {
      opacity: 1;
      transform: translateX(2px);
      transition: transform .18s ease;
    }

    /* ── Cerrar sesión destacado ── */
    .card-glass.card-logout {
      --accent-primary: #e74c3c;
      border-color: rgba(231, 76, 60, 0.35);
      background: rgba(231, 76, 60, 0.10);
    }

    .card-glass.card-logout:hover {
      background: rgba(231, 76, 60, 0.18);
      border-color: rgba(231, 76, 60, 0.50);
      box-shadow: 0 18px 50px rgba(231, 76, 60, 0.18);
    }

    .card-glass.card-logout .icon-badge i {
      color: #ff8080;
    }

    /* ── Contadores del panel ── */
    .stat-card {
      border-radius: 16px;
      padding: 18px 16px;
      text-align: center;
      background: rgba(255, 255, 255, 0.07);
      border: 1px solid rgba(255, 255, 255, 0.14);
      box-shadow: 0 16px 36px rgba(0, 0, 0, 0.45);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      height: 100%;
    }

    .stat-card h6 {
      margin: 0 0 6px;
      font-size: .8rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
      color: var(--muted);
    }

    .stat-card .stat-value {
      font-size: 2rem;
      font-weight: 800;
      line-height: 1.1;
      margin: 0;
      color: #fff;
    }

    .stat-card .stat-sub {
      font-size: .9rem;
      color: #F0EDF2;
      margin-bottom: 4px;
      min-height: 1.4em;
    }

    .stat-card .stat-value-sm {
      font-size: 1.05rem;
      font-weight: 700;
      color: #fff;
    }

    .stat-card.stat-total {
      border-color: rgba(72, 187, 120, 0.35);
      box-shadow: 0 16px 36px rgba(0, 0, 0, 0.45), inset 0 1px 0 rgba(72, 187, 120, 0.10);
    }
    .stat-card.stat-total .stat-value { color: #7ee2a8; }

    .stat-card.stat-hoy {
      border-color: rgba(56, 189, 248, 0.35);
      box-shadow: 0 16px 36px rgba(0, 0, 0, 0.45), inset 0 1px 0 rgba(56, 189, 248, 0.10);
    }
    .stat-card.stat-hoy .stat-value { color: #7dd3fc; }

    .stat-card.stat-mes {
      border-color: rgba(251, 191, 36, 0.35);
      box-shadow: 0 16px 36px rgba(0, 0, 0, 0.45), inset 0 1px 0 rgba(251, 191, 36, 0.10);
    }
    .stat-card.stat-mes .stat-value { color: #fcd34d; }

    .stat-card.stat-turno {
      border-color: rgba(96, 165, 250, 0.35);
      box-shadow: 0 16px 36px rgba(0, 0, 0, 0.45), inset 0 1px 0 rgba(96, 165, 250, 0.10);
    }
    .stat-card.stat-turno .stat-sub { color: #bfdbfe; }

    /* ── Responsive ── */
    @media (max-width: 1024px) {
      .grid {
        grid-template-columns: repeat(3, 1fr);
      }
    }

    @media (max-width: 768px) {
      .grid {
        grid-template-columns: repeat(2, 1fr);
      }
    }

    @media (max-width: 480px) {
      .grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>

<body>

  <!-- TOP BAR -->
  <div class="topbar">
    <div class="topbar-inner">
      <div class="brand">
        <img src="img/LogoCapVial26.png" alt="CeCap">
        <div class="title">
          <strong>CeCap • Panel</strong>
          <span>Sistema de Capacitación</span>
        </div>
      </div>
      <div class="welcome">
        <i class="bi bi-person-check-fill"></i>
        <div class="txt">
          Bienvenido/a, <b><?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?></b>
        </div>
      </div>
    </div>
  </div>

  <!-- STATS -->
  <div class="container mt-4">
    <div class="row mb-4 g-3">

      <div class="col-md-3 col-6">
        <div class="stat-card stat-total">
          <h6>Total General</h6>
          <div class="stat-value"><?= (int)$total ?></div>
        </div>
      </div>

      <div class="col-md-3 col-6">
        <div class="stat-card stat-hoy">
          <h6>Asistentes Hoy</h6>
          <div class="stat-value"><?= (int)$total_hoy ?></div>
        </div>
      </div>

      <div class="col-md-3 col-6">
        <div class="stat-card stat-mes">
          <h6>Este Mes</h6>
          <div class="stat-value"><?= (int)$total_mes ?></div>
        </div>
      </div>

      <div class="col-md-3 col-6">
        <div class="stat-card stat-turno">
          <h6>Turno Más Activo</h6>
          <div class="stat-sub"><?= $turno_top ?></div>
          <div class="stat-value-sm"><?= (int)$turno_cantidad ?> personas</div>
        </div>
      </div>

    </div>
  </div>

  <!-- TARJETAS -->
  <div class="wrap">
    <div class="dashboard-glass">
      <div class="grid">

        <?php
        $tienePermisos = permiso('registrar') || permiso('certificados') || permiso('cert_email') || permiso('reportes') || permiso('buscar') || permiso('admin') || permiso('config_cert') || permiso('config_form') || permiso('calendario') || permiso('formularios');
        if (!$tienePermisos):
        ?>
          <div style="grid-column: 1 / -1;" class="text-center py-4 px-3 rounded-4 bg-dark bg-opacity-50 border border-secondary border-opacity-25">
            <i class="bi bi-shield-slash text-warning display-6 d-block mb-2"></i>
            <h5 class="fw-bold mb-1">Sin permisos ni módulos asignados</h5>
            <div class="text-white-50 small">No tenés secciones ni funciones habilitadas en tu cuenta. Solicitá a un administrador que active tus accesos.</div>
          </div>
        <?php endif; ?>
        <?php if (permiso('registrar')): ?>
          <a class="card-glass" href="registro_manual.php">
            <div class="card-top">
              <div class="icon-badge"><i class="bi bi-person-plus-fill"></i></div>
              <div>
                <h3 class="card-title">Nuevos Asistentes</h3>
                <div class="card-desc">Agregar y editar asistentes manualmente</div>
              </div>
              <i class="bi bi-chevron-right card-arrow"></i>
            </div>
          </a>
        <?php endif; ?>

        <!-- Emitir Certificados — permiso certificados -->
        <?php if (permiso('certificados')): ?>
          <a class="card-glass" href="filtrar_fechas.php">
            <div class="card-top">
              <div class="icon-badge"><i class="bi bi-file-earmark-pdf-fill"></i></div>
              <div>
                <h3 class="card-title">Emitir Certificados</h3>
                <div class="card-desc">Generar certificados PDF</div>
              </div>
              <i class="bi bi-chevron-right card-arrow"></i>
            </div>
          </a>
        <?php endif; ?>

        <!-- Enviar Certificados por Email — permiso cert_email -->
        <?php if (permiso('cert_email')): ?>
          <a class="card-glass" href="enviar_certificados_email.php">
            <div class="card-top">
              <div class="icon-badge"><i class="bi bi-envelope-paper-fill"></i></div>
              <div>
                <h3 class="card-title">Enviar Certificados por Email</h3>
                <div class="card-desc">Enviar PDF automáticamente a los asistentes</div>
              </div>
              <i class="bi bi-chevron-right card-arrow"></i>
            </div>
          </a>
        <?php endif; ?>

        <!-- Reportes — permiso reportes -->
        <?php if (permiso('reportes')): ?>
          <a class="card-glass" href="resumen.php">
            <div class="card-top">
              <div class="icon-badge"><i class="bi bi-bar-chart-fill"></i></div>
              <div>
                <h3 class="card-title">Reportes</h3>
                <div class="card-desc">Estadísticas y gráficos</div>
              </div>
              <i class="bi bi-chevron-right card-arrow"></i>
            </div>
          </a>
        <?php endif; ?>

        <!-- Buscar Asistentes — permiso buscar -->
        <?php if (permiso('buscar')): ?>
          <a class="card-glass" href="buscar_asistentes.php">
            <div class="card-top">
              <div class="icon-badge"><i class="bi bi-search-heart-fill"></i></div>
              <div>
                <h3 class="card-title">Buscar Asistentes</h3>
                <div class="card-desc">Consulta, modificación y certificados individuales</div>
              </div>
              <i class="bi bi-chevron-right card-arrow"></i>
            </div>
          </a>
        <?php endif; ?>

        <!-- Administrar Usuarios — solo ADMIN -->
        <?php if (permiso('admin')): ?>
          <a class="card-glass" href="usuarios.php">
            <div class="card-top">
              <div class="icon-badge"><i class="bi bi-shield-lock-fill"></i></div>
              <div>
                <h3 class="card-title">Administrar Usuarios</h3>
                <div class="card-desc">Roles, permisos y accesos</div>
              </div>
              <i class="bi bi-chevron-right card-arrow"></i>
            </div>
          </a>
        <?php endif; ?>

        <!-- Configurar Certificados — permiso config_cert -->
        <?php if (permiso('config_cert')): ?>
          <a class="card-glass" href="configurar_certificados.php">
            <div class="card-top">
              <div class="icon-badge"><i class="bi bi-card-text"></i></div>
              <div>
                <h3 class="card-title">Configurar Certificados</h3>
                <div class="card-desc">Mensaje personalizado en certificados PDF</div>
              </div>
              <i class="bi bi-chevron-right card-arrow"></i>
            </div>
          </a>
        <?php endif; ?>

        <!-- Configurar Formulario — permiso config_form -->
        <?php if (permiso('config_form')): ?>
          <a class="card-glass" href="config_form_eventos.php">
            <div class="card-top">
              <div class="icon-badge"><i class="bi bi-calendar2-event-fill"></i></div>
              <div>
                <h3 class="card-title">Configurar Formulario</h3>
                <div class="card-desc">Eventos especiales por fecha y horario</div>
              </div>
              <i class="bi bi-chevron-right card-arrow"></i>
            </div>
          </a>
        <?php endif; ?>

        <!-- Calendario — Programación de Eventos — permiso calendario -->
        <?php if (permiso('calendario')): ?>
          <a class="card-glass" href="calendario_eventos.php">
            <div class="card-top">
              <div class="icon-badge" style="--accent-primary:#FFD166"><i class="bi bi-calendar-week-fill" style="color:#FFD166"></i></div>
              <div>
                <h3 class="card-title">Calendario</h3>
                <div class="card-desc">Programación de Eventos y avisos</div>
              </div>
              <i class="bi bi-chevron-right card-arrow"></i>
            </div>
          </a>
        <?php endif; ?>

        <!-- Auditoría — solo ADMIN -->
        <?php if (permiso('admin')): ?>
          <a class="card-glass" href="auditoria.php">
            <div class="card-top">
              <div class="icon-badge"><i class="bi bi-clipboard2-data-fill"></i></div>
              <div>
                <h3 class="card-title">Auditoría</h3>
                <div class="card-desc">Registro de accesos, acciones y actividad</div>
              </div>
              <i class="bi bi-chevron-right card-arrow"></i>
            </div>
          </a>
        <?php endif; ?>

        <!-- Importar Datos — solo ADMIN -->
        <?php if (permiso('admin')): ?>
          <a class="card-glass" href="importar_datos.php">
            <div class="card-top">
              <div class="icon-badge"><i class="bi bi-database-up"></i></div>
              <div>
                <h3 class="card-title">Importar Datos</h3>
                <div class="card-desc">Importar Excel con múltiples hojas</div>
              </div>
              <i class="bi bi-chevron-right card-arrow"></i>
            </div>
          </a>
        <?php endif; ?>

        <!-- Avisos de Actualización — solo ADMIN -->
        <?php if (permiso('admin')): ?>
          <a class="card-glass" href="configurar_modales.php">
            <div class="card-top">
              <div class="icon-badge"><i class="bi bi-window-stack"></i></div>
              <div>
                <h3 class="card-title">Avisos de Actualización</h3>
                <div class="card-desc">Crear y configurar modales informativos</div>
              </div>
              <i class="bi bi-chevron-right card-arrow"></i>
            </div>
          </a>
        <?php endif; ?>

        <!-- Generar Formularios — permiso formularios -->
        <?php if (permiso('formularios')): ?>
          <a class="card-glass" href="formulario_qr.php">
            <div class="card-top">
              <div class="icon-badge"><i class="bi bi-file-earmark-text-fill"></i></div>
              <div>
                <h3 class="card-title">Generar Formularios</h3>
                <div class="card-desc">Seleccionar asistente y emitir formularios</div>
              </div>
              <i class="bi bi-chevron-right card-arrow"></i>
            </div>
          </a>
        <?php endif; ?>

        <!-- Código QR — todos pueden imprimir, solo admin puede subir -->
        <a class="card-glass" href="qr_impresion.php">
          <div class="card-top">
            <div class="icon-badge"><i class="bi bi-qr-code"></i></div>
            <div>
              <h3 class="card-title">Código QR</h3>
              <div class="card-desc">Imprimir QR de asistencia<?php if (permiso('admin')): ?> · Subir imagen<?php endif; ?></div>
            </div>
            <i class="bi bi-chevron-right card-arrow"></i>
          </div>
        </a>

        <!-- Imprimir Certificados Vacíos -->
        <a class="card-glass" href="certificados_vacios.php">
          <div class="card-top">
            <div class="icon-badge"><i class="bi bi-file-earmark-fill"></i></div>
            <div>
              <h3 class="card-title">Certificados Vacíos</h3>
              <div class="card-desc">Descargar plantilla PDF para impresión manual</div>
            </div>
            <i class="bi bi-chevron-right card-arrow"></i>
          </div>
        </a>

        <!-- Cerrar Sesión — todos -->
        <a class="card-glass card-logout" href="logout.php">
          <div class="card-top">
            <div class="icon-badge"><i class="bi bi-box-arrow-right"></i></div>
            <div>
              <h3 class="card-title">Cerrar Sesión</h3>
              <div class="card-desc">Salir del sistema de forma segura</div>
            </div>
            <i class="bi bi-chevron-right card-arrow"></i>
          </div>
        </a>

      </div>
    </div>
  </div>

  <?php include __DIR__ . "/footer.php"; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <?php if ($modal_actualizacion): ?>
  <!-- MODAL DE ACTUALIZACIÓN CENTRADO -->
  <style>
    .modal-update-glass .modal-content {
      background: rgba(45, 36, 48, 0.95);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid rgba(255, 255, 255, 0.18);
      border-radius: 20px;
      color: #F0EDF2;
      box-shadow: 0 25px 60px rgba(0,0,0,0.65);
    }
    .modal-update-glass .modal-header {
      border-bottom: 1px solid rgba(255, 255, 255, 0.12);
      padding: 20px 24px;
    }
    .modal-update-glass .modal-body {
      padding: 24px;
      font-size: 0.95rem;
      line-height: 1.6;
      max-height: 60vh;
      overflow-y: auto;
    }
    .modal-update-glass .modal-footer {
      border-top: 1px solid rgba(255, 255, 255, 0.12);
      padding: 16px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
  </style>

  <div class="modal fade modal-update-glass" id="modalActualizacionSistema" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title d-flex align-items-center gap-2">
            <i class="bi bi-megaphone-fill text-success fs-4"></i>
            <span><?= htmlspecialchars($modal_actualizacion['titulo'], ENT_QUOTES, 'UTF-8') ?></span>
            <?php if (!empty($modal_actualizacion['version'])): ?>
              <span class="badge bg-success ms-2"><?= htmlspecialchars($modal_actualizacion['version'], ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
          </h5>
          <button type="button" class="btn-close btn-close-white" id="btnCerrarModalTop" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?php 
            $content_txt = $modal_actualizacion['contenido'];
            if (strip_tags($content_txt) !== $content_txt) {
              echo strip_tags($content_txt, '<p><br><b><strong><i><em><ul><ol><li><span><a><h1><h2><h3><h4><h5><h6><div>');
            } else {
              echo nl2br(htmlspecialchars($content_txt, ENT_QUOTES, 'UTF-8'));
            }
          ?>
        </div>
        <div class="modal-footer">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="chkNoMostrarNuevoModal">
            <label class="form-check-label text-white-50 small" for="chkNoMostrarNuevoModal">
              No volver a mostrar esta actualización
            </label>
          </div>
          <button type="button" class="btn btn-success px-4 rounded-3" id="btnEntendidoModal" data-bs-dismiss="modal">
            <i class="bi bi-check2-circle me-1"></i> Entendido
          </button>
        </div>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener("DOMContentLoaded", function() {
      const modalId = <?= (int)$modal_actualizacion['id'] ?>;
      const userId = <?= (int)($_SESSION['id_instru'] ?? 0) ?>;
      const storageKey = 'modal_actualizacion_oculto_' + userId + '_' + modalId;

      // Verificar si el usuario ya eligió no mostrar esta actualización
      let oculto = false;
      try { oculto = localStorage.getItem(storageKey) !== null; } catch (e) { oculto = false; }
      if (!oculto) {
        const elModal = document.getElementById('modalActualizacionSistema');
        if (elModal) {
          const bsModal = new bootstrap.Modal(elModal);
          bsModal.show();

          // Guardar preferencia si el checkbox está marcado al cerrar
          const guardarPreferencia = () => {
            const chk = document.getElementById('chkNoMostrarNuevoModal');
            if (chk && chk.checked) {
              try { localStorage.setItem(storageKey, '1'); } catch (e) {}
            }
          };

          elModal.addEventListener('hide.bs.modal', guardarPreferencia);
        }
      }
    });
  </script>
  <?php endif; ?>

  <!-- MODAL DE AVISO DE EVENTOS DEL CALENDARIO -->
  <style>
    .modal-evento-glass .modal-content {
      background: rgba(45, 36, 48, 0.95);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid rgba(255, 209, 102, 0.30);
      border-radius: 20px;
      color: #F0EDF2;
      box-shadow: 0 25px 60px rgba(0,0,0,0.65);
    }
    .modal-evento-glass .modal-header {
      border-bottom: 1px solid rgba(255, 209, 102, 0.20);
      padding: 20px 24px;
    }
    .modal-evento-glass .modal-body {
      padding: 24px;
      font-size: 0.95rem;
      line-height: 1.6;
    }
    .modal-evento-glass .modal-footer {
      border-top: 1px solid rgba(255, 209, 102, 0.20);
      padding: 16px 24px;
    }
    .evento-detail {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      padding: 8px 0;
      border-bottom: 1px solid rgba(255,255,255,.06);
    }
    .evento-detail:last-child { border-bottom: none; }
    .evento-detail i {
      font-size: 1.1rem;
      margin-top: 2px;
      flex-shrink: 0;
    }
  </style>

  <div class="modal fade modal-evento-glass" id="modalAvisoEvento" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title d-flex align-items-center gap-2">
            <i class="bi bi-calendar-event-fill text-warning fs-4"></i>
            <span>Aviso de Evento Próximo</span>
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" id="modalEventoBody">
          <p style="opacity:.7">Cargando...</p>
        </div>
        <div class="modal-footer">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="chkNoMostrarEvento">
            <label class="form-check-label text-white-50 small" for="chkNoMostrarEvento">
              No volver a mostrar este aviso
            </label>
          </div>
          <a href="calendario_eventos.php" class="btn btn-warning px-4 rounded-3 fw-bold" onclick="const m = bootstrap.Modal.getInstance(document.getElementById('modalAvisoEvento')); if (m) { m.hide(); }">
            <i class="bi bi-calendar-week me-1"></i> Ver Calendario
          </a>
        </div>
      </div>
    </div>
  </div>

  <?php if (permiso('calendario')): ?>
  <script>
    document.addEventListener("DOMContentLoaded", function() {
      fetch("calendario_check_aviso.php")
        .then(r => r.json())
        .then(data => {
          if (data.evento) {
            const userId = <?= (int)($_SESSION['id_instru'] ?? 0) ?>;
            const eventoId = data.evento.id;
            const storageKey = 'evento_aviso_oculto_' + userId + '_' + eventoId;

            // Proteger acceso a localStorage (puede bloquearse en algunos navegadores)
            let oculto = false;
            try { oculto = localStorage.getItem(storageKey) !== null; } catch (e) { oculto = false; }

            if (!oculto) {
              const ev = data.evento;
              let html = '';
              html += '<div class="evento-detail"><i class="bi bi bi bi-megaphone-fill text-warning"></i><div><strong>' + ev.titulo + '</strong></div></div>';
              html += '<div class="evento-detail"><i class="bi bi-calendar3 text-info"></i><div>📅 ' + ev.fecha + ev.hora + '</div></div>';
              html += '<div class="evento-detail"><i class="bi bi-geo-alt-fill text-danger"></i><div>📍 ' + ev.lugar + '</div></div>';
              if (ev.descripcion) {
                html += '<div class="evento-detail"><i class="bi bi-card-text text-secondary"></i><div style="opacity:.85">' + ev.descripcion + '</div></div>';
              }

              document.getElementById('modalEventoBody').innerHTML = html;

              const elModal = document.getElementById('modalAvisoEvento');
              if (elModal) {
                const bsModal = new bootstrap.Modal(elModal);
                bsModal.show();

                elModal.addEventListener('hide.bs.modal', function() {
                  const chk = document.getElementById('chkNoMostrarEvento');
                  if (chk && chk.checked) {
                    try { localStorage.setItem(storageKey, '1'); } catch (e) {}
                  }
                });
              }
            }
          }
        })
        .catch(() => {});
    });
  </script>
  <?php endif; ?>

  <script>
    // Verificar cada 5 segundos si el usuario fue forzado a cerrar sesión
    setInterval(() => {
      fetch("check_force_logout.php")
        .then(r => r.text())
        .then(t => {
          if (t === "logout") {
            alert("Sesión cerrada por administrador");
            window.location = "login.php";
          }
        });
    }, 5000);

    // Keep alive cada 30 segundos
    setInterval(() => {
      fetch("keep_alive.php");
    }, 30000);
  </script>

</body>

</html>