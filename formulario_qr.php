<?php
date_default_timezone_set("America/Argentina/Buenos_Aires");
require_once __DIR__ . "/conexion.php";

/* ── Logo configurable ── */
$logo = "img/logo_evento.png";
$stmt = $conn->prepare("SELECT valor FROM config_general WHERE clave = 'logo_formulario' LIMIT 1");
$stmt->execute();
$resLogo = $stmt->get_result();
if ($resLogo->num_rows) {
    $logo = $resLogo->fetch_assoc()['valor'];
}
$stmt->close();

/* ── Motor de eventos ── */
$auto      = require __DIR__ . "/motor_eventos.php";
$lugar     = $auto['lugar'];
$turno     = $auto['turno'];
$categoria = $auto['categoria'];

/* ── Mensajes de sesión ── */
session_start();
$msgOk    = $_SESSION['form_ok']    ?? null;
$msgError = $_SESSION['form_error'] ?? null;
unset($_SESSION['form_ok'], $_SESSION['form_error']);

$hayClase = ($lugar !== '' && $turno !== '' && $categoria !== '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Registro de Asistencia</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/variables.css">

<style>
:root {
    --glass-bg:    rgba(255,255,255,0.09);
    --glass-border:rgba(255,255,255,0.15);
    --input-bg:    rgba(0,0,0,0.40);
    --text-main:   #F0EDF2;
    --text-label:  #C7E4CF;
    --accent:      #5D5E70;
}
* { box-sizing: border-box; }
body {
    background: linear-gradient(135deg, #504254, #3C313F);
    background-attachment: fixed;
    font-family: 'Segoe UI', sans-serif;
    color: var(--text-main);
    min-height: 100vh;
    margin: 0;
    padding: 16px 12px 48px;
}
.card-form {
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: 22px;
    padding: 24px 20px;
    backdrop-filter: blur(14px);
    max-width: 480px;
    margin: 0 auto;
    box-shadow: 0 20px 55px rgba(0,0,0,.55);
}
.logo { text-align: center; margin-bottom: 12px; }
.logo img { max-height: 64px; max-width: 100%; object-fit: contain; }

.info-sesion {
    background: rgba(0,0,0,0.35);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 14px;
    padding: 12px 16px;
    margin-bottom: 20px;
}
.info-sesion .item {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 5px 0;
    font-size: .93rem;
    color: #C7E4CF;
    line-height: 1.3;
}
.info-sesion .item strong { color: #fff; }
.info-sesion .icono { flex-shrink: 0; }

.sin-clase {
    background: rgba(220,53,69,0.20);
    border: 1px solid rgba(220,53,69,0.40);
    border-radius: 14px;
    padding: 24px;
    text-align: center;
    color: #ffc8ce;
}

.form-control,
.form-select {
    background: var(--input-bg) !important;
    border: 1px solid rgba(255,255,255,0.20) !important;
    border-radius: 13px !important;
    color: #F0EDF2 !important;
    caret-color: var(--accent);
    font-size: 1rem;
    height: 50px;
    -webkit-appearance: none;
    appearance: none;
}
.form-control:focus,
.form-select:focus {
    background: rgba(0,0,0,0.52) !important;
    border-color: var(--accent) !important;
    box-shadow: 0 0 0 2px rgba(93,94,112,0.28) !important;
    color: #fff !important;
}
.form-control::placeholder { color: rgba(199,228,207,0.45) !important; }
.form-select option { background: #1b2a3a; color: #fff; }
input:-webkit-autofill,
input:-webkit-autofill:focus {
    -webkit-text-fill-color: #F0EDF2 !important;
    box-shadow: 0 0 0 1000px rgba(0,0,0,.40) inset !important;
}
.form-label {
    color: var(--text-label);
    font-size: .80rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 5px;
}
.label-opcional {
    color: rgba(199,228,207,.5);
    font-weight: 400;
    text-transform: none;
    letter-spacing: 0;
    font-size: .78rem;
}
.btn-guardar {
    height: 56px;
    border-radius: 18px;
    font-size: 1.1rem;
    font-weight: 700;
    background: linear-gradient(135deg, #DD4841, #B03030);
    border: none;
    color: #fff;
    letter-spacing: .04em;
    width: 100%;
    transition: transform .15s, box-shadow .15s;
}
.btn-guardar:active { transform: scale(.97); }
.btn-guardar:hover  { box-shadow: 0 6px 20px rgba(93,94,112,.35); }

/* ── Modales ── */
.modal-glass {
    background: rgba(10,25,40,0.97) !important;
    border: 1px solid rgba(255,255,255,0.12) !important;
    border-radius: 24px !important;
    color: #F0EDF2;
    backdrop-filter: blur(16px);
}
.modal-icono        { font-size: 4.2rem; line-height: 1; }
.modal-titulo-ok    { font-size:1.5rem; font-weight:800; color:#4dffb0; margin-top:12px; letter-spacing:.5px; }
.modal-titulo-error { font-size:1.5rem; font-weight:800; color:#ff6b6b; margin-top:12px; }
.modal-subtitulo    { font-size:1rem; color:#C7E4CF; margin-top:10px; line-height:1.6; }

.btn-modal-ok {
    background: linear-gradient(135deg,#00c96b,#00a3e0);
    border: none; border-radius: 50px;
    padding: 11px 44px;
    font-weight: 700; color: #fff; font-size: 1rem;
    transition: opacity .2s;
}
.btn-modal-ok:hover { opacity:.85; color:#fff; }

.btn-modal-err {
    background: linear-gradient(135deg,#e03b3b,#e07a00);
    border: none; border-radius: 50px;
    padding: 11px 44px;
    font-weight: 700; color: #fff; font-size: 1rem;
    transition: opacity .2s;
}
.btn-modal-err:hover { opacity:.85; color:#fff; }

@keyframes popIn {
    from { transform:scale(.3); opacity:0 }
    to   { transform:scale(1);  opacity:1 }
}
@keyframes shake {
    0%,100% { transform:translateX(0)    }
    20%,60%  { transform:translateX(-8px) }
    40%,80%  { transform:translateX(8px)  }
}
.anim-pop   { animation: popIn .45s cubic-bezier(.34,1.56,.64,1) both; }
.anim-shake { animation: shake .4s ease both; }

@media (max-width: 400px) {
    .card-form   { padding: 18px 14px; border-radius: 16px; }
    .btn-guardar { font-size: 1rem; height: 52px; }
    .form-control,
    .form-select { height: 48px; }
}
</style>
</head>

<body>
<div class="card-form">

    <div class="logo">
        <img src="<?= htmlspecialchars($logo) ?>" alt="Logo">
    </div>

    <h5 class="text-center mb-3 fw-bold">Registro de Asistencia</h5>

    <?php if (!$hayClase): ?>

    <div class="sin-clase">
        <div style="font-size:2.2rem">📵</div>
        <div class="fw-bold mt-2 fs-6">No hay clase programada en este momento</div>
        <div class="mt-1" style="font-size:.85rem;opacity:.75">
            Si creés que es un error, consultá con tu instructor.
        </div>
    </div>

    <?php else: ?>

    <div class="info-sesion">
        <div class="item">
            <span class="icono">📍</span>
            <span>Lugar: <strong><?= htmlspecialchars($lugar) ?></strong></span>
        </div>
        <div class="item">
            <span class="icono">🕒</span>
            <span>Turno: <strong><?= htmlspecialchars($turno) ?></strong></span>
        </div>
        <div class="item">
            <span class="icono">🎓</span>
            <span>Categoría: <strong><?= htmlspecialchars($categoria) ?></strong></span>
        </div>
    </div>

    <form method="POST" action="guardar_asistencia.php" autocomplete="off" novalidate>

        <div class="mb-3">
            <label class="form-label">Apellidos</label>
            <input name="apellidos" class="form-control mayusculas"
                   placeholder="TUS APELLIDOS"
                   autocomplete="family-name" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Nombres</label>
            <input name="nombres" class="form-control mayusculas"
                   placeholder="TUS NOMBRES"
                   autocomplete="given-name" required>
        </div>

        <div class="mb-3">
            <label class="form-label">DNI</label>
            <input name="dni" class="form-control"
                   placeholder="Sin puntos ni espacios"
                   inputmode="numeric" pattern="[0-9]*" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Género</label>
            <select name="genero" class="form-select" required>
                <option value="">— Seleccioná —</option>
                <option value="MASCULINO">Masculino</option>
                <option value="FEMENINO">Femenino</option>
                <option value="OTRO">Otro</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Edad</label>
            <select name="edad" class="form-select" required>
                <option value="">— Seleccioná —</option>
                <?php for ($e = 16; $e <= 99; $e++): ?>
                <option value="<?= $e ?>"><?= $e ?> años</option>
                <?php endfor; ?>
            </select>
        </div>

        <div class="mb-4">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control"
                   placeholder="tucorreo@ejemplo.com"
                   inputmode="email" autocomplete="email" required>
        </div>

        <button type="submit" class="btn-guardar">
            Registrarme ✓
        </button>

    </form>
    <?php endif; ?>

</div>

<!-- ══════════════════════════════
     PIE DE PÁGINA
══════════════════════════════ -->
<div style="text-align:center; margin-top:28px; padding-bottom:12px; opacity:.75;">
    <p style="font-size:.72rem; color:#C7E4CF; letter-spacing:.08em; text-transform:uppercase; margin-bottom:8px;">
        Desarrollado por
    </p>
    <a href="https://recursoscreativos.net.ar" target="_blank" rel="noopener noreferrer">
        <img src="img/logo_rc.jpeg"
             alt="Recursos Creativos"
             style="height:52px; width:52px; border-radius:50%; object-fit:cover;
                    border:1px solid rgba(255,255,255,0.18);
                    box-shadow:0 4px 14px rgba(0,0,0,.5);
                    transition:transform .2s, opacity .2s;">
    </a>
</div>


<!-- ══════════════════════════════
     MODAL ÉXITO
══════════════════════════════ -->
<div class="modal fade" id="modalOk" tabindex="-1"
     data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered mx-3">
        <div class="modal-content modal-glass text-center px-4 py-5">

            <div class="modal-icono anim-pop">✅</div>

            <div class="modal-titulo-ok">
                Datos Cargados
            </div>

            <p class="modal-subtitulo">
                ¡Muchas Gracias!<br>
                Tu asistencia fue registrada correctamente.
            </p>

            <div class="mt-4">
                <button class="btn btn-modal-ok" data-bs-dismiss="modal">
                    Aceptar
                </button>
            </div>

        </div>
    </div>
</div>


<!-- ══════════════════════════════
     MODAL ERROR
══════════════════════════════ -->
<div class="modal fade" id="modalError" tabindex="-1"
     data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered mx-3">
        <div class="modal-content modal-glass text-center px-4 py-5">

            <div class="modal-icono anim-shake">❌</div>

            <div class="modal-titulo-error">
                Atención
            </div>

            <p class="modal-subtitulo" id="textoError"></p>

            <div class="mt-4">
                <button class="btn btn-modal-err" data-bs-dismiss="modal">
                    Reintentar
                </button>
            </div>

        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>

document.addEventListener('DOMContentLoaded', function () {

    <?php if ($msgOk): ?>
        new bootstrap.Modal(document.getElementById('modalOk')).show();
    <?php elseif ($msgError): ?>
        document.getElementById('textoError').textContent =
            <?= json_encode($msgError) ?>;
        new bootstrap.Modal(document.getElementById('modalError')).show();
    <?php endif; ?>

});

// Mayúsculas manteniendo cursor
document.addEventListener("input", e => {
    if (!e.target.classList.contains("mayusculas")) return;
    const el  = e.target;
    const pos = el.selectionStart;
    el.value  = el.value.toUpperCase();
    el.setSelectionRange(pos, pos);
});

// Solo números en DNI
document.querySelector('[name="dni"]')?.addEventListener("input", function () {
    this.value = this.value.replace(/\D/g, '');
});

</script>

</body>
</html>