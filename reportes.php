<?php
/* ================= ERRORES ================= */
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if (!isset($_SESSION['id_instru'])) {
    header("Location: login.php");
    exit();
}

// Permisos
function permiso($p) {
    return isset($_SESSION['permiso'][$p]) && $_SESSION['permiso'][$p] == 1;
}

require_once __DIR__ . "/conexion.php";

// Asegurar que exista la conexión en $conexion (varios proyectos usan $conn)
if (!isset($conexion) || !($conexion instanceof mysqli)) {
    if (isset($conn) && ($conn instanceof mysqli)) {
        $conexion = $conn;
    } elseif (defined('DB_HOST') && defined('DB_USER') && defined('DB_NAME')) {
        // Intentar crear una conexión si la configuración está definida en conexion.php
        $pass = defined('DB_PASS') ? DB_PASS : '';
        $conexion = new mysqli(DB_HOST, DB_USER, $pass, DB_NAME);
        if ($conexion->connect_error) {
            die('Error de conexión: ' . $conexion->connect_error);
        }
    } else {
        die('Error: no se encontró la variable de conexión $conexion ni $conn.');
    }
}


// ================== MOSTRAR TARJETA SI NO HAY POST ==================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Generar Reporte</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/variables.css">
<style>
body{
    background:#0d1b2a;
    display:flex;
    justify-content:center;
    align-items:center;
    height:100vh;
    color:#fff;
}
.card-panel{
    background:rgba(255,255,255,.1);
    padding:30px;
    border-radius:20px;
    text-align:center;
}
.btn-volver{
    color:#5D5E70;
    border:2px solid #5D5E70;
    padding:10px 20px;
    border-radius:10px;
    text-decoration:none;
}
</style>
</head>
<body>
<div class="card-panel">
    <h3>📄 Generar Reporte de Participantes</h3>
    <p>Debe usar el formulario de filtrado por fechas.</p>
    <a href="panel.php" class="btn-volver">⬅ Volver</a>
</div>
</body>
</html>
<?php
exit;
}

// ================== PDF ==================
ob_start();
require('fpdf/fpdf.php');

// ------------------ FECHAS ------------------
$desde = $_POST['desde'] ?? '';
$hasta = $_POST['hasta'] ?? '';

function normalizarFecha($f){
    if(!$f) return null;
    if(preg_match('/(\d{4})-(\d{2})-(\d{2})/', $f)) return $f;
    if(preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $f, $m)){
        return "$m[3]-$m[2]-$m[1]";
    }
    return null;
}

$desdeN = normalizarFecha($desde);
$hastaN = normalizarFecha($hasta);

// ------------------ CONSULTA MYSQL ------------------
$sql = "
SELECT
    fecha,
    apellidos,
    nombres,
    dni,
    licencia,
    genero,
    turno,
    lugar
FROM registros
WHERE 1=1
";

$params = [];
$types  = "";

if ($desdeN) {
    $sql .= " AND fecha >= ?";
    $params[] = $desdeN;
    $types .= "s";
}
if ($hastaN) {
    $sql .= " AND fecha <= ?";
    $params[] = $hastaN;
    $types .= "s";
}

$sql .= " ORDER BY fecha ASC";

$stmt = $conexion->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$filtrados = [];
while ($row = $result->fetch_assoc()) {
    $filtrados[] = $row;
}

if (empty($filtrados)) {
    die("No se encontraron registros en el rango seleccionado.");
}

// ================== CLASE PDF ==================
class PDF extends FPDF{
    function Header(){
        $this->SetFont('Arial','B',16);
        $this->SetTextColor(13,110,253);
        $this->Cell(0,12,'REPORTE DE PARTICIPANTES',0,1,'C');
        $this->Ln(5);
    }
    function Footer(){
        $this->SetY(-15);
        $this->SetFont('Arial','I',9);
        $this->Cell(0,10,'Pagina '.$this->PageNo().'/{nb}',0,0,'C');
    }
}

$pdf = new PDF();
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetFont('Arial','',10);

// ------------------ RANGO ------------------
if ($desdeN || $hastaN) {
    $texto = "Rango: ";
    if ($desdeN) $texto .= "desde ".date("d/m/Y", strtotime($desdeN))." ";
    if ($hastaN) $texto .= "hasta ".date("d/m/Y", strtotime($hastaN));
    $pdf->Cell(0,10,$texto,0,1,'C');
}

// ------------------ TABLA ------------------
$pdf->SetFont('Arial','B',11);
$pdf->SetFillColor(220,230,240);
$pdf->Cell(28,8,'Fecha',1,0,'C',true);
$pdf->Cell(42,8,'Apellido',1,0,'C',true);
$pdf->Cell(42,8,'Nombre',1,0,'C',true);
$pdf->Cell(28,8,'DNI',1,0,'C',true);
$pdf->Cell(30,8,'Licencia',1,1,'C',true);

$pdf->SetFont('Arial','',10);

$total=0; $porGenero=[]; $porTurno=[]; $porLugar=[];

foreach ($filtrados as $r) {
    $pdf->Cell(28,8,date("d/m/Y", strtotime($r['fecha'])),1,0,'C');
    $pdf->Cell(42,8,strtoupper($r['apellidos']),1,0,'C');
    $pdf->Cell(42,8,strtoupper($r['nombres']),1,0,'C');
    $pdf->Cell(28,8,$r['dni'],1,0,'C');
    $pdf->Cell(30,8,strtoupper($r['licencia']),1,1,'C');

    $porGenero[$r['genero']] = ($porGenero[$r['genero']] ?? 0) + 1;
    $porTurno[$r['turno']]   = ($porTurno[$r['turno']] ?? 0) + 1;
    $porLugar[$r['lugar']]   = ($porLugar[$r['lugar']] ?? 0) + 1;
    $total++;
}

// ================== RESUMEN ==================
$pdf->AddPage();
$pdf->SetFont('Arial','B',14);
$pdf->Cell(0,12,'RESUMEN ESTADISTICO',0,1,'C');
$pdf->Ln(6);

$pdf->SetFont('Arial','',11);
$pdf->Cell(0,8,"Total de registros: $total",0,1);

$pdf->Ln(4);
foreach ($porGenero as $k=>$v) {
    $pdf->Cell(0,7,"Genero $k: $v",0,1);
}
$pdf->Ln(2);
foreach ($porTurno as $k=>$v) {
    $pdf->Cell(0,7,"Turno $k: $v",0,1);
}
$pdf->Ln(2);
foreach ($porLugar as $k=>$v) {
    $pdf->Cell(0,7,"Lugar $k: $v",0,1);
}

ob_end_clean();
$pdf->Output('I','Reporte_Participantes.pdf');
