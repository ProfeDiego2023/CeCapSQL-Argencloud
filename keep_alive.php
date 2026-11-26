<?php
session_start();
require_once "conexion.php";

if(isset($_SESSION['id_instru'])){
    $id=(int)$_SESSION['id_instru'];
    $conn->query("UPDATE usuarios SET last_activity=NOW() WHERE id=$id");
}
