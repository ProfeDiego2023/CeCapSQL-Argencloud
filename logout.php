<?php
session_start();

require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/auditar.php";


// 🔐 Registrar auditoría SOLO si estaba logueado
if (isset($_SESSION['id_instru'])) {
    auditar($conn, "LOGOUT", "sistema");
}

// 🔒 Destruir sesión completamente
session_unset();
session_destroy();

// 🔁 Redirigir al login
header("Location: login.php");
exit();
