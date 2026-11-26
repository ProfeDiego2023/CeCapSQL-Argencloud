<?php
ini_set('display_errors', 0);
error_reporting(0);

session_start();
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/auth.php";
proteger();

$desde = trim($_GET['desde'] ?? '');
$hasta = trim($_GET['hasta'] ?? '');

/* ==============================
   HELPERS
================================ */
function fetchGroup(mysqli $conn, string $sql, string $types, array $params): array {
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res  = $stmt->get_result();
    $data = [];
    while ($r = $res->fetch_assoc()) {
        $k = trim($r['k'] !== '' ? $r['k'] : 'No especificado');
        $data[$k] = (int)$r['v'];
    }
    $stmt->close();
    return $data;
}

function diaES(string $dia): string {
    return ['Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles',
            'Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sábado','Sunday'=>'Domingo'][$dia] ?? $dia;
}

/* ==============================
   WHERE
================================ */
$where  = ["oculto = 0"];
$params = [];
$types  = "";
if ($desde !== '') { $where[] = "fecha_dia >= ?"; $params[] = $desde; $types .= "s"; }
if ($hasta !== '') { $where[] = "fecha_dia <= ?"; $params[] = $hasta; $types .= "s"; }
$whereSQL = "WHERE " . implode(" AND ", $where);

/* ── Total ── */
$stmt = $conn->prepare("SELECT COUNT(*) FROM asistencias $whereSQL");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute(); $stmt->bind_result($total); $stmt->fetch(); $stmt->close();

/* ── Género ── */
$porGenero = fetchGroup($conn,
    "SELECT genero k, COUNT(*) v FROM asistencias $whereSQL GROUP BY genero ORDER BY v DESC",
    $types, $params);

/* ── Turnos normalizados ── */
$tmpTurnos = fetchGroup($conn,
    "SELECT turno k, COUNT(*) v FROM asistencias $whereSQL GROUP BY turno ORDER BY v DESC",
    $types, $params);
$porTurno = [];
foreach ($tmpTurnos as $k => $v) {
    $kLow = strtolower($k);
    if      (str_contains($kLow,'13:30')||str_contains($kLow,'1°')) $clave='1 Turno 13:30';
    elseif  (str_contains($kLow,'20:00')||str_contains($kLow,'2°')) $clave='2 Turno 20:00';
    elseif  (str_contains($kLow,'8:30') ||str_contains($kLow,'3°')) $clave='3 Turno 8:30';
    elseif  (str_contains($kLow,'prof'))                             $clave='Profesional';
    else                                                              $clave=$k?:'No especificado';
    $porTurno[$clave] = ($porTurno[$clave] ?? 0) + $v;
}
arsort($porTurno);

/* ── Lugar ── */
$tmpLugares = fetchGroup($conn,
    "SELECT lugar k, COUNT(*) v FROM asistencias $whereSQL GROUP BY lugar ORDER BY v DESC",
    $types, $params);
$porLugar = [];
$porEscuela = [];
$totalEscuelas = 0;
foreach ($tmpLugares as $k => $v) {
    $kUp = strtoupper($k);
    if (preg_match('/\bESC(UELA)?\b/i', $k)) {
        $nombreLimpio = trim(preg_replace('/^ESCUELA\s*[-:]?\s*/i', '', $k));
        if ($nombreLimpio === '') $nombreLimpio = 'ESCUELA (GENERAL)';
        else $nombreLimpio = 'ESC. ' . $nombreLimpio;
        $porEscuela[$nombreLimpio] = ($porEscuela[$nombreLimpio] ?? 0) + $v;
        $totalEscuelas += $v;
        $clave = 'ESCUELAS';
    } elseif (str_contains($kUp, 'LEHMAN') || $k === 'No especificado') {
        $clave = 'LEHMANN 1530';
    } elseif (str_contains($kUp, 'IRIONDO') || str_contains($kUp, 'NIDO')) {
        $clave = 'S.IRIONDO 5870';
    } else {
        $clave = $k;
    }
    $porLugar[$clave] = ($porLugar[$clave] ?? 0) + $v;
}
arsort($porLugar);
arsort($porEscuela);

/* ── Año ── */
$porAnio = fetchGroup($conn,
    "SELECT YEAR(fecha_dia) k, COUNT(*) v FROM asistencias $whereSQL
     GROUP BY YEAR(fecha_dia) ORDER BY k ASC", $types, $params);

/* ── Días ── */
$tmpDias = fetchGroup($conn,
    "SELECT DAYNAME(fecha_dia) k, COUNT(*) v FROM asistencias $whereSQL
     GROUP BY DAYNAME(fecha_dia), DAYOFWEEK(fecha_dia) ORDER BY DAYOFWEEK(fecha_dia)", $types, $params);
$porDia = [];
foreach (['Lunes','Martes','Miercoles','Jueves','Sabado'] as $dia) {
    foreach ($tmpDias as $diaEN => $cantidad) {
        if (diaES($diaEN) === $dia ||
            diaES($diaEN) === str_replace(['Miercoles','Sabado'],['Miércoles','Sábado'], $dia)) {
            $porDia[$dia] = $cantidad;
        }
    }
}
$porDia = array_filter($porDia, fn($v) => $v > 0);

/* ── Categorías ── */
$porCategoria = ['Automovil'=>0,'Motovehiculos'=>0,'Auto+Moto'=>0,'Profesional'=>0,'RCP'=>0];
$stmt = $conn->prepare("SELECT licencia FROM asistencias $whereSQL");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $l = strtolower(trim($r['licencia'] ?? ''));
    if      (str_contains($l,'auto')&&str_contains($l,'moto')) $porCategoria['Auto+Moto']++;
    elseif  (str_contains($l,'auto'))  $porCategoria['Automovil']++;
    elseif  (str_contains($l,'moto'))  $porCategoria['Motovehiculos']++;
    elseif  (str_contains($l,'prof'))  $porCategoria['Profesional']++;
    elseif  (str_contains($l,'rcp'))   $porCategoria['RCP']++;
}
$stmt->close();
$porCategoria = array_filter($porCategoria, fn($v) => $v > 0);

/* ── Métricas rápidas ── */
$stmt = $conn->prepare("SELECT COUNT(*) FROM asistencias WHERE oculto=0 AND fecha_dia=CURDATE()");
$stmt->execute(); $stmt->bind_result($hoy); $stmt->fetch(); $stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) FROM asistencias WHERE oculto=0
    AND MONTH(fecha_dia)=MONTH(CURDATE()) AND YEAR(fecha_dia)=YEAR(CURDATE())");
$stmt->execute(); $stmt->bind_result($esteMes); $stmt->fetch(); $stmt->close();

/* ==============================
   QUICKCHART — genera imagen PNG
   (sin extensiones cURL — usa file_get_contents)
================================ */
function chartURL(array $data, string $titulo): string {
    // Limpiar caracteres no-ASCII en labels para QuickChart
    $labels = array_map(fn($k) => iconv('UTF-8', 'ASCII//TRANSLIT', $k), array_keys($data));
    $colores = ['#3B8EDB','#FFA050','#64DCA0','#DC64B4','#A082DC','#FFDC3C','#50C8DC'];

    $config = [
        "type" => "bar",
        "data" => [
            "labels" => $labels,
            "datasets" => [[
                "label" => $titulo,
                "data"  => array_values($data),
                "backgroundColor" => array_slice($colores, 0, count($data))
            ]]
        ],
        "options" => [
            "plugins" => [
                "legend" => ["display" => false],
                "title"  => ["display" => false]
            ],
            "scales" => [
                "y" => ["beginAtZero" => true,
                        "ticks" => ["color" => "#333"]],
                "x" => ["ticks" => ["color" => "#333"]]
            ]
        ]
    ];
    return "https://quickchart.io/chart?width=500&height=280&bkg=white&c="
         . urlencode(json_encode($config, JSON_UNESCAPED_UNICODE));
}

function descargarImagen(string $url): ?string {
    $ctx = stream_context_create(['http'=>['timeout'=>10]]);
    $bytes = @file_get_contents($url, false, $ctx);
    if (!$bytes) return null;
    $tmp = tempnam(sys_get_temp_dir(), 'chart') . '.png';
    file_put_contents($tmp, $bytes);
    return $tmp;
}

/* ==============================
   FPDF
================================ */
require_once __DIR__ . "/fpdf/fpdf.php";

// Suprimir salida anterior
if (ob_get_level()) ob_end_clean();

/* ── Subclase con Header y Footer automáticos ── */
class CeCapPDF extends FPDF {
    public string $periodo = '';

    function Header() {
        // Fondo azul oscuro
        $this->SetFillColor(20, 50, 80);
        $this->Rect(0, 0, 210, 18, 'F');

        // Título izquierda
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 12);
        $this->SetXY(15, 4);
        $this->Cell(100, 10, 'Resumen Estadistico - CeCap', 0, 0, 'L');

        // Período derecha
        $this->SetFont('Arial', '', 9);
        $this->SetXY(110, 4);
        $this->Cell(85, 10, $this->periodo, 0, 0, 'R');

        $this->SetTextColor(0, 0, 0);
        $this->SetY(22); // margen top tras cabecera
    }

    function Footer() {
        $this->SetY(-12);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 5,
            'CeCap - Diego Caravaca Copyright ' . date('Y') .
            '   |   Pagina ' . $this->PageNo(),
            0, 0, 'C');
    }
}

$pdf = new CeCapPDF('P', 'mm', 'A4');
$pdf->SetMargins(15, 22, 15);  // top=22 para dejar espacio al header
$pdf->SetAutoPageBreak(true, 18);

// Asignar período al header
$pdf->periodo = ($desde || $hasta)
    ? 'Periodo: ' . ($desde ? date('d/m/Y', strtotime($desde)) : 'inicio')
      . ' al ' . ($hasta ? date('d/m/Y', strtotime($hasta)) : 'hoy')
    : 'Todos los registros';

$pdf->AddPage();

// Encabezado manejado automáticamente por Header()

// Fecha generacion
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(120,120,120);
$pdf->Cell(0, 5, 'Generado: ' . date('d/m/Y H:i'), 0, 1, 'C');
$pdf->Ln(3);

// ── Resumen numérico ──
$pdf->SetFillColor(240, 248, 255);
$pdf->SetTextColor(20, 50, 80);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 8, 'Resumen', 'B', 1, 'L');
$pdf->Ln(2);

$metricas = [
    ['Total general',   number_format($total,   0, ',', '.')],
    ['Hoy',             number_format($hoy,     0, ',', '.')],
    ['Este mes',        number_format($esteMes, 0, ',', '.')],
];
$pdf->SetFont('Arial', '', 11);
$colW = (210 - 30) / count($metricas);
foreach ($metricas as $m) {
    $pdf->SetFillColor(230, 245, 255);
    $pdf->SetTextColor(30, 30, 30);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell($colW, 6, $m[0], 1, 0, 'C', true);
}
$pdf->Ln();
foreach ($metricas as $m) {
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 13);
    $pdf->Cell($colW, 10, $m[1], 1, 0, 'C', true);
}
$pdf->Ln(14);

// ── Gráficos — 2 por página ──
$graficos = [
    'Por Año'       => $porAnio,
    'Por Genero'    => $porGenero,
    'Por Lugar'     => $porLugar,
    'Por Turno'     => $porTurno,
    'Por Dia'       => $porDia,
    'Por Categoria' => $porCategoria,
];

$pos   = 0;
$imgW  = 87;    // ancho imagen mm
$imgH  = 48;    // alto imagen mm (reducido para dar más margen)
$tabH  = 4;     // alto de cada fila de tabla
$maxTabRows = 5;// máximo filas de tabla para no desbordar
$colX  = [15, 108];
$yBase = 0;
$altoBloque = $imgH + 6 + ($tabH * $maxTabRows) + 8; // título(6) + img + tabla + separador

foreach ($graficos as $titulo => $datos) {
    if (empty($datos)) continue;

    $col = $pos % 2;

    if ($col === 0) {
        // Nueva fila de gráficos
        if ($pos > 0) {
            // Avanzar al final del bloque anterior
            $pdf->SetY($yBase + $altoBloque);
        }
        // ¿Cabe en la página?
        if ($pdf->GetY() + $altoBloque > 272) $pdf->AddPage();
        $yBase = $pdf->GetY();
    }

    $x = $colX[$col];
    $y = $yBase;

    // Título
    $pdf->SetXY($x, $y);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetTextColor(20, 50, 80);
    $pdf->Cell($imgW, 6, $titulo, 0, 0, 'C');

    // Imagen
    $url = chartURL($datos, $titulo);
    $img = descargarImagen($url);
    if ($img) {
        $pdf->Image($img, $x, $y + 7, $imgW, $imgH);
        @unlink($img);
    }

    // Tabla de valores — posición fija, no mueve el cursor de la otra columna
    $tyStart = $y + $imgH + 9;
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(60, 60, 60);
    $cw1 = $imgW * 0.72;
    $cw2 = $imgW * 0.28;
    $rowCount = 0;
    foreach ($datos as $k => $v) {
        if ($rowCount >= $maxTabRows) break;
        $ty = $tyStart + $rowCount * $tabH;
        $pdf->SetXY($x, $ty);
        $lbl = iconv('UTF-8','ASCII//TRANSLIT',$k);
        if ($titulo === 'Por Lugar' && $k === 'ESCUELAS' && !empty($porEscuela)) {
            $lbl .= ' (' . count($porEscuela) . ' esc.)';
        }
        $pdf->Cell($cw1, $tabH, $lbl, 0, 0, 'L');
        $pdf->Cell($cw2, $tabH, number_format($v, 0, ',', '.'), 0, 0, 'R');
        $rowCount++;
    }

    $pos++;
}

// Pie manejado automáticamente por Footer()

// Salida
$pdf->Output('I', 'resumen_estadistico_' . date('Ymd') . '.pdf');
exit;