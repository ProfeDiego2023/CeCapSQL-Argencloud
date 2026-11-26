<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/auth.php";

if (empty($_SESSION['id_instru'])) {
    header("Location: ../login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Importar Datos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- SheetJS para leer XLSX en el navegador -->
  <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
</head>
<body class="bg-dark text-light">

<div class="container mt-5" style="max-width:560px">
  <div class="card bg-secondary">
    <div class="card-header">
      <h4>📥 Importar Datos (XLSX)</h4>
    </div>
    <div class="card-body">

      <p class="small text-light opacity-75 mb-3">
        Subí el archivo Excel exportado desde Google Sheets.<br>
        Se procesarán <strong>todas las hojas</strong> del archivo.<br>
        Los registros ya existentes <strong>no serán modificados</strong>.
      </p>

      <div class="mb-4">
        <label class="form-label">Archivo Excel (.xlsx)</label>
        <input type="file" id="archivoXLSX" class="form-control" accept=".xlsx,.xls" required>
      </div>

      <!-- Barra de progreso -->
      <div id="progreso" style="display:none" class="mb-3">
        <div class="d-flex justify-content-between small mb-1">
          <span id="progresoTexto">Procesando...</span>
          <span id="progresoPct">0%</span>
        </div>
        <div class="progress">
          <div id="progresoBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success"
               style="width:0%"></div>
        </div>
      </div>

      <div id="resultado" class="mb-3"></div>

      <div class="d-flex gap-2">
        <button id="btnImportar" class="btn btn-success" onclick="procesarXLSX()">
          Importar
        </button>
        <a href="panel.php" class="btn btn-outline-light">← Volver</a>
      </div>

    </div>
  </div>
</div>

<script>
async function procesarXLSX() {
    const input   = document.getElementById("archivoXLSX");
    const btnImp  = document.getElementById("btnImportar");
    const divRes  = document.getElementById("resultado");
    const divProg = document.getElementById("progreso");

    if (!input.files.length) {
        divRes.innerHTML = '<div class="alert alert-warning">Seleccioná un archivo primero.</div>';
        return;
    }

    btnImp.disabled = true;
    divRes.innerHTML = '';
    divProg.style.display = 'block';
    setProgreso(5, "Leyendo archivo...");

    try {
        const file   = input.files[0];
        const buffer = await file.arrayBuffer();
        const wb     = XLSX.read(buffer, { type: "array", cellDates: true });

        setProgreso(20, "Procesando hojas...");

        // Recolectar filas de TODAS las hojas
        let todasLasFilas = [];

        // Hojas a ignorar
        const hojasIgnorar = ["papelera", "trash", "eliminados"];

        /**
         * Convierte el valor de una celda de fecha de SheetJS a "DD/MM/YYYY HH:MM:SS".
         * SheetJS con raw:true devuelve las fechas como objetos Date de JS.
         * Con raw:false las convierte a string pero en formato americano MM/DD/YYYY.
         * Usamos raw:true y convertimos manualmente para garantizar DD/MM/YYYY.
         */
        function formatearFecha(val) {
            if (!val) return "";

            let d;
            if (val instanceof Date) {
                d = val;
            } else if (typeof val === "number") {
                // Número serial de Excel → Date
                d = new Date(Math.round((val - 25569) * 86400 * 1000));
            } else {
                return String(val); // ya es string, devolver tal cual
            }

            if (isNaN(d.getTime())) return "";

            const dd   = String(d.getDate()).padStart(2, "0");
            const mm   = String(d.getMonth() + 1).padStart(2, "0");
            const yyyy = d.getFullYear();
            const hh   = String(d.getHours()).padStart(2, "0");
            const min  = String(d.getMinutes()).padStart(2, "0");
            const ss   = String(d.getSeconds()).padStart(2, "0");

            return `${dd}/${mm}/${yyyy} ${hh}:${min}:${ss}`;
        }

        wb.SheetNames.forEach((nombreHoja) => {

            // Saltar hojas que no son de asistencias
            if (hojasIgnorar.some(h => nombreHoja.toLowerCase().includes(h))) {
                console.log(`Hoja "${nombreHoja}" ignorada`);
                return;
            }

            const ws = wb.Sheets[nombreHoja];

            // raw:true para recibir fechas como objetos Date nativos
            const rows = XLSX.utils.sheet_to_json(ws, { defval: "", raw: true, cellDates: true });

            // Normalizar fechas y convertir números a string
            const rowsNorm = rows.map(fila => {
                const nueva = {};
                for (const [key, val] of Object.entries(fila)) {
                    if (val instanceof Date) {
                        nueva[key] = formatearFecha(val);
                    } else if (typeof val === "number") {
                        // DNI, Edad y otros campos numéricos vienen como number con raw:true
                        nueva[key] = String(val);
                    } else {
                        nueva[key] = val;
                    }
                }
                return nueva;
            });

            console.log(`Hoja "${nombreHoja}": ${rowsNorm.length} filas`);
            if (rowsNorm.length > 0) {
                console.log("Primera fila normalizada:", rowsNorm[0]);
            }

            todasLasFilas = todasLasFilas.concat(rowsNorm);
        });

        if (todasLasFilas.length === 0) {
            divRes.innerHTML = '<div class="alert alert-warning">El archivo no tiene datos.</div>';
            btnImp.disabled = false;
            divProg.style.display = 'none';
            return;
        }

        setProgreso(40, `Enviando ${todasLasFilas.length} filas al servidor...`);

        // Enviar en lotes de 500 para no superar límites de POST
        const LOTE = 500;
        let insertados = 0, omitidos = 0, errores = 0;
        const totalLotes = Math.ceil(todasLasFilas.length / LOTE);

        for (let i = 0; i < todasLasFilas.length; i += LOTE) {
            const lote      = todasLasFilas.slice(i, i + LOTE);
            const loteNum   = Math.floor(i / LOTE) + 1;
            const pct       = 40 + Math.round((loteNum / totalLotes) * 55);

            setProgreso(pct, `Importando lote ${loteNum} de ${totalLotes}...`);

            const resp = await fetch("procesar_importacion.php", {
                method:  "POST",
                headers: { "Content-Type": "application/json" },
                body:    JSON.stringify({ filas: lote })
            });

            if (!resp.ok) {
                throw new Error(`Error HTTP ${resp.status} en lote ${loteNum}`);
            }

            const data = await resp.json();

            if (data.error) {
                throw new Error(data.error);
            }

            insertados += data.insertados ?? 0;
            omitidos   += data.omitidos   ?? 0;
            errores    += data.errores    ?? 0;
        }

        setProgreso(100, "Completado");

        divRes.innerHTML = `
            <div class="alert alert-success mt-3">
                <h5>✅ Importación completada</h5>
                <table class="table table-sm table-dark mb-0 mt-2">
                    <tr><td>📥 Insertados nuevos</td>
                        <td><span class="badge bg-success">${insertados}</span></td></tr>
                    <tr><td>⏭️ Omitidos (ya existían)</td>
                        <td><span class="badge bg-warning text-dark">${omitidos}</span></td></tr>
                    <tr><td>❌ Errores</td>
                        <td><span class="badge bg-danger">${errores}</span></td></tr>
                </table>
            </div>`;

    } catch (err) {
        divRes.innerHTML = `<div class="alert alert-danger">❌ Error: ${err.message}</div>`;
        console.error(err);
    }

    btnImp.disabled = false;
}

function setProgreso(pct, texto) {
    document.getElementById("progresoBar").style.width  = pct + "%";
    document.getElementById("progresoPct").textContent  = pct + "%";
    document.getElementById("progresoTexto").textContent = texto;
}
</script>

</body>
</html>