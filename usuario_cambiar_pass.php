<?php
session_start();
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/auth.php";
proteger();

$error = '';
$ok    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nueva = $_POST['nueva']     ?? '';
    $conf  = $_POST['confirmar'] ?? '';

    if (strlen($nueva) < 8) {
        $error = "La contraseña debe tener al menos 8 caracteres.";
    } elseif ($nueva !== $conf) {
        $error = "Las contraseñas no coinciden.";
    } else {
        $hash = password_hash($nueva, PASSWORD_DEFAULT);
        $id   = (int)$_SESSION['id_instru'];

        $st = $conn->prepare("UPDATE usuarios SET pass = ?, debe_cambiar_pass = 0 WHERE id = ?");
        $st->bind_param("si", $hash, $id);
        $st->execute();
        $st->close();

        // Limpiar flag de sesión
        unset($_SESSION['debe_cambiar_pass']);

        header("Location: panel.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cambiar contraseña — CeCap</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/variables.css">
<style>
* { box-sizing: border-box; }
body {
    background: linear-gradient(135deg, #504254, #3C313F);
    background-attachment: fixed;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Segoe UI', sans-serif;
    color: #F0EDF2;
}
.card-glass {
    background: rgba(255,255,255,.10);
    backdrop-filter: blur(16px);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 22px;
    box-shadow: 0 20px 50px rgba(0,0,0,.55);
    padding: 36px 32px;
    width: 100%;
    max-width: 420px;
}
.card-glass h4 {
    font-weight: 800;
    margin-bottom: 6px;
}
.card-glass p.sub {
    font-size: .88rem;
    color: rgba(199,228,207,.65);
    margin-bottom: 24px;
}
.form-control {
    background: rgba(0,0,0,.35) !important;
    border: 1px solid rgba(255,255,255,.22) !important;
    color: #fff !important;
    border-radius: 12px !important;
    height: 46px;
}
.form-control:focus {
    border-color: #5D5E70 !important;
    box-shadow: 0 0 0 2px rgba(93,94,112,.25) !important;
}
.form-control::placeholder { color: rgba(255,255,255,.4) !important; }
label { color: #C7E4CF; font-size: .85rem; margin-bottom: 4px; }

/* Medidor de fuerza */
.fuerza-bar {
    height: 5px;
    border-radius: 3px;
    margin-top: 6px;
    transition: width .3s, background .3s;
    background: #ef4444;
    width: 0%;
}
.fuerza-txt {
    font-size: .72rem;
    color: rgba(199,228,207,.55);
    margin-top: 3px;
}
</style>
</head>
<body>

<div class="card-glass">
    <h4>🔑 Cambiar contraseña</h4>
    <p class="sub">
        <?php if (!empty($_SESSION['debe_cambiar_pass'])): ?>
            Tu cuenta es nueva. Establecé una contraseña personal para continuar.
        <?php else: ?>
            Ingresá tu nueva contraseña.
        <?php endif; ?>
    </p>

    <?php if ($error): ?>
    <div class="alert alert-danger py-2 mb-3" style="border-radius:12px;font-size:.88rem">
        ⚠️ <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="formPass">

        <div class="mb-3">
            <label>Nueva contraseña</label>
            <div class="input-group">
                <input type="password" name="nueva" id="inp-nueva" class="form-control"
                       placeholder="Mínimo 8 caracteres" required minlength="8"
                       autocomplete="new-password">
                <button type="button" class="btn btn-outline-secondary"
                        onclick="toggleVer('inp-nueva')">👁</button>
            </div>
            <div class="fuerza-bar" id="fuerzaBar"></div>
            <div class="fuerza-txt" id="fuerzaTxt"></div>
        </div>

        <div class="mb-4">
            <label>Confirmar contraseña</label>
            <div class="input-group">
                <input type="password" name="confirmar" id="inp-conf" class="form-control"
                       placeholder="Repetí la contraseña" required minlength="8"
                       autocomplete="new-password">
                <button type="button" class="btn btn-outline-secondary"
                        onclick="toggleVer('inp-conf')">👁</button>
            </div>
            <div id="matchMsg" style="font-size:.72rem;margin-top:4px"></div>
        </div>

        <button type="submit" class="btn btn-info w-100 fw-bold" style="border-radius:12px;height:46px">
            Guardar contraseña
        </button>

    </form>
</div>

<script>
function toggleVer(id) {
    const el = document.getElementById(id);
    el.type = el.type === 'password' ? 'text' : 'password';
}

/* Medidor de fuerza */
document.getElementById('inp-nueva').addEventListener('input', function() {
    const v   = this.value;
    const bar = document.getElementById('fuerzaBar');
    const txt = document.getElementById('fuerzaTxt');
    let score = 0;
    if (v.length >= 8)              score++;
    if (v.length >= 12)             score++;
    if (/[A-Z]/.test(v))            score++;
    if (/[0-9]/.test(v))            score++;
    if (/[^A-Za-z0-9]/.test(v))     score++;

    const niveles = [
        { w:'20%',  bg:'#ef4444', label:'Muy débil'  },
        { w:'40%',  bg:'#f97316', label:'Débil'       },
        { w:'60%',  bg:'#eab308', label:'Regular'     },
        { w:'80%',  bg:'#22c55e', label:'Buena'       },
        { w:'100%', bg:'#5D5E70', label:'Excelente'   },
    ];
    const n = niveles[Math.min(score, 4)];
    bar.style.width      = v.length ? n.w  : '0%';
    bar.style.background = n.bg;
    txt.textContent      = v.length ? n.label : '';

    checkMatch();
});

/* Verificar coincidencia */
document.getElementById('inp-conf').addEventListener('input', checkMatch);

function checkMatch() {
    const v1  = document.getElementById('inp-nueva').value;
    const v2  = document.getElementById('inp-conf').value;
    const msg = document.getElementById('matchMsg');
    if (!v2) { msg.textContent = ''; return; }
    if (v1 === v2) {
        msg.innerHTML = '<span style="color:#22c55e">✅ Coinciden</span>';
    } else {
        msg.innerHTML = '<span style="color:#ef4444">❌ No coinciden</span>';
    }
}
</script>

</body>
</html>