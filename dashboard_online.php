<?php
require_once "conexion.php";

$row=$conn->query("
SELECT COUNT(*) c 
FROM usuarios
WHERE last_activity > (NOW() - INTERVAL 5 MINUTE)
AND eliminado=0
")->fetch_assoc();

echo (int)$row['c'];
?>
<!-- ============================ CHECK FORCE LOGOUT ============================ -->
<script>
setInterval(()=>{
 fetch("check_force_logout.php")
 .then(r=>r.text())
 .then(t=>{
    if(t=="logout"){
        alert("Sesión cerrada por administrador");
        window.location="login.php";
    }
 });
},5000);
</script>


