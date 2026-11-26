<?php
// ===== CONFIG =====
$DEBUG = false;

if ($DEBUG) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if ($isHttps) {
    ini_set('session.cookie_secure', 1);
}

session_start();

require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/auth.php";

function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// Si ya está logueado
if (isset($_SESSION['id_instru'])) {
    // Si tiene pendiente cambio de contraseña, redirigir ahí
    if (!empty($_SESSION['debe_cambiar_pass'])) {
        header("Location: usuario_cambiar_pass.php");
        exit();
    }
    header("Location: panel.php");
    exit();
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $usuario = trim($_POST['usuario'] ?? '');
    $pass    = (string)($_POST['pass'] ?? '');

    if ($usuario === '' || $pass === '') {
        $error = "Completar usuario y contraseña.";
    } else {

        $USE_RECAPTCHA = false;

        if (!$error) {
            $login = login($usuario, $pass, $conn);

            if (!empty($login["status"])) {

                // Registrar login en auditoría
                require_once __DIR__ . "/auditar.php";
                auditar($conn, "LOGIN", "ACCESO", "Ingreso al sistema");

                // Cookie recordar usuario
                $cookieParams = [
                    "expires"  => time() + (60 * 60 * 24 * 30),
                    "path"     => "/",
                    "secure"   => $isHttps,
                    "httponly" => true,
                    "samesite" => "Lax",
                ];

                if (!empty($_POST['remember'])) {
                    setcookie("cecap_user", $usuario, $cookieParams);
                } else {
                    setcookie("cecap_user", "", [
                        "expires"  => time() - 3600,
                        "path"     => "/",
                        "secure"   => $isHttps,
                        "httponly" => true,
                        "samesite" => "Lax",
                    ]);
                }

                // ¿Debe cambiar contraseña? → redirigir a cambio
                if ($login["msg"] === "CAMBIAR_PASS") {
                    header("Location: usuario_cambiar_pass.php");
                    exit();
                }

                header("Location: panel.php");
                exit();

            } else {
                $error = $login["msg"] ?? "Usuario o contraseña incorrectos.";
            }
        }
    }
}

$usuario_guardado = $_COOKIE['cecap_user'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CeCap • Login</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/variables.css">
<style>
@keyframes shake {
  0%   { transform: translateX(0); }
  25%  { transform: translateX(-8px); }
  50%  { transform: translateX(8px); }
  75%  { transform: translateX(-8px); }
  100% { transform: translateX(0); }
}
.shake {
  animation: shake 0.4s ease;
  border: 2px solid var(--error-text) !important;
  box-shadow: 0 0 12px var(--error-text) !important;
}
body {
  background: linear-gradient(135deg, #504254, #3C313F);
  background-attachment: fixed;
  color: var(--text-main);
  min-height: 100vh;
}
.login-box {
  max-width: 420px;
  margin: auto;
  margin-top: 12vh;
  padding: 30px;
  background: rgba(255,255,255,0.06);
  border-radius: 20px;
  backdrop-filter: blur(8px);
  box-shadow: 0 0 20px rgba(0,0,0,0.5);
  border-top: 4px solid var(--accent-primary);
}
.login-box img { width: 250px; margin-bottom: 10px; }
.btn-cecap {
  background: var(--btn-primary-bg);
  border: none;
  padding: 12px;
  width: 100%;
  border-radius: 10px;
  font-size: 1.1rem;
  color: #fff;
}
.btn-cecap:hover { background: var(--btn-primary-hover); }
</style>
</head>
<body>

<div class="login-box text-center <?= $error ? 'shake' : '' ?>">
  <img src="img/LogoCapVial26.png" alt="CeCap">
  <h3>Ingreso al Sistema</h3>

  <?php if ($error): ?>
    <div class="alert alert-danger fw-bold text-center border-light shadow-sm">
      <?= e($error) ?>
    </div>
  <?php endif; ?>

  <form method="POST">
    <input type="text" name="usuario" class="form-control mb-3"
      value="<?= e($usuario_guardado) ?>" autocomplete="off"
      placeholder="Usuario" required>

    <input type="password" name="pass" class="form-control mb-3"
      placeholder="Contraseña" required>

    <button type="submit" class="btn-cecap">Ingresar</button>

    <div class="form-check text-start mt-2 mb-1">
      <input class="form-check-input" type="checkbox" name="remember" id="remember"
        <?= $usuario_guardado ? 'checked' : '' ?>>
      <label class="form-check-label" for="remember">Recordar usuario</label>
    </div>
  </form>
</div>

</body>
</html>