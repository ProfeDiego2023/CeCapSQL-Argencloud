<?php
session_start();
require_once "conexion.php";

header("Content-Type: application/json");

// actualizar actividad del que consulta
if(isset($_SESSION['id_instru'])){
    $id=(int)$_SESSION['id_instru'];
    $conn->query("UPDATE usuarios SET last_activity=NOW() WHERE id=$id");
}

// devolver lista online
$res = $conn->query("
SELECT id,
CASE 
 WHEN last_activity > (NOW() - INTERVAL 5 MINUTE)
 THEN 1 ELSE 0 
END online
FROM usuarios
WHERE eliminado=0
");

$data=[];
while($r=$res->fetch_assoc()){
    $data[$r['id']] = (int)$r['online'];
}

echo json_encode($data);
