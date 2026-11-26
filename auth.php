<?php
// auth.php
require_once("auditar.php");


function permiso(string $tipo): bool {

    if (!isset($_SESSION['id_instru'])) {
        return false;
    }

    // ADMIN siempre puede todo
    if (isset($_SESSION['rol']) && strtoupper($_SESSION['rol']) === 'ADMIN') {
        return true;
    }

    // Permisos individuales
    if (!empty($_SESSION['permiso'][$tipo])) {
        return true;
    }

    return false;
}


function login(string $usuario, string $passPlano, mysqli $conn): array {

    $usuario = trim($usuario);
    if ($usuario === '' || $passPlano === '') {
        return ["status" => false, "msg" => "Completar usuario y contraseña."];
    }

    $sql = "SELECT * FROM usuarios WHERE usuario = ? LIMIT 1";
    $st  = $conn->prepare($sql);

    if (!$st) {
        return ["status" => false, "msg" => "Error interno. Intentá más tarde."];
    }

    $st->bind_param("s", $usuario);
    $st->execute();
    $res = $st->get_result();

    if (!$res || $res->num_rows === 0) {
        return ["status" => false, "msg" => "Usuario o contraseña incorrectos."];
    }

    $u = $res->fetch_assoc();

    // Activo
    if (isset($u['activo']) && (int)$u['activo'] !== 1) {
        return ["status" => false, "msg" => "Usuario deshabilitado."];
    }

    // Password
    $passDb = (string)$u['pass'];

    // Caso 1: password_hash (bcrypt / argon)
    if (password_get_info($passDb)['algo'] !== 0) {

        if (!password_verify($passPlano, $passDb)) {
            return ["status" => false, "msg" => "Usuario o contraseña incorrectos."];
        }

    } else {
        // Caso 2: hash SHA256 legacy
        $hash = hash("sha256", $passPlano);

        if (!hash_equals($passDb, $hash)) {
            return ["status" => false, "msg" => "Usuario o contraseña incorrectos."];
        }

        // Migración automática a password_hash
        $nuevoHash = password_hash($passPlano, PASSWORD_DEFAULT);
        $up = $conn->prepare("UPDATE usuarios SET pass = ? WHERE id = ?");
        $up->bind_param("si", $nuevoHash, $u['id']);
        $up->execute();
    }

    // Cargar sesión SIEMPRE antes de cualquier redirección
    $_SESSION['id_instru'] = (int)$u['id'];
    $_SESSION['usuario']   = (string)$u['usuario'];
    $_SESSION['nombre']    = (string)($u['nombre'] ?? '');
    $_SESSION['rol']       = (string)($u['rol'] ?? 'INSTRUCTOR');

    // Permisos
    $esAdmin = strtoupper($u['rol'] ?? '') === 'ADMIN';
    $_SESSION['permiso'] = [
        "registrar"    => !empty($u['permiso_registrar']),
        "editar"       => !empty($u['permiso_editar']),
        "eliminar"     => !empty($u['permiso_eliminar']),
        "certificados" => !empty($u['permiso_certificados']),
        "cert_email"   => !empty($u['permiso_cert_email']),
        "reportes"     => !empty($u['permiso_reportes']),
        "admin"        => !empty($u['permiso_panel_admin']) || $esAdmin,
        "ver_todos"    => !empty($u['permiso_ver_todos'])   || $esAdmin,
        "buscar"       => !empty($u['permiso_buscar'])      || $esAdmin,
        "formularios"  => !empty($u['permiso_formularios']) || $esAdmin,
        "config_form"  => !empty($u['permiso_config_form']) || $esAdmin,
        "config_cert"  => !empty($u['permiso_config_cert']) || $esAdmin,
        "calendario"   => !empty($u['permiso_calendario'])  || $esAdmin,
    ];

    // Cambio de contraseña obligatorio — DESPUÉS de cargar la sesión
    if (!empty($u['debe_cambiar_pass'])) {
        $_SESSION['debe_cambiar_pass'] = true;
        return ["status" => true, "msg" => "CAMBIAR_PASS"];
    }

    return ["status" => true, "msg" => "OK"];
}


function proteger(): void {
    // Evitar que el navegador guarde en caché páginas protegidas
    // (al reabrir/cerrar sesión puede mostrar contenido o mensajes obsoletos)
    if (!headers_sent()) {
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");
        header("Expires: 0");
    }

    if (empty($_SESSION['id_instru'])) {
        header("Location: login.php");
        exit;
    }
}