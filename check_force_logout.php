<?php
session_start();
require_once "conexion.php";

if(!isset($_SESSION['id_instru'])) exit;

$id = (int)$_SESSION['id_instru'];

$row = $conn->query("SELECT forzar_logout FROM usuarios WHERE id=$id")->fetch_assoc();

if($row && $row['forzar_logout']==1){

    // reset flag
    $conn->query("UPDATE usuarios SET forzar_logout=0 WHERE id=$id");

    session_destroy();
    echo "logout";
    exit;
}

echo "ok";
