<?php

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');
session_start();

if (!isset($_SESSION['id_usuario'])) { header("Location: login.php"); exit; }
if ($_SESSION['id_plan'] == 2)       { header("Location: index.php");  exit; }

require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)$_SESSION['id_usuario'];
    $conexion->query("UPDATE usuarios SET id_plan = 2 WHERE id_usuario = $id");
    $_SESSION['id_plan']     = 2;
    $_SESSION['nombre_plan'] = 'Premium';
    $conexion->close();
    header("Location: upgrade.php?ok=1");
    exit;
}

$ok = isset($_GET['ok']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mejorar a Premium — CinePelis</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-950 min-h-screen flex items-center justify-center p-4">
<div class="w-full max-w-md bg-gray-900 border border-yellow-500 rounded-2xl p-8 text-center shadow-2xl">

    <?php if ($ok): ?>
        <div class="text-6xl mb-4">🎉</div>
        <h2 class="text-white text-2xl font-bold mb-2">¡Bienvenido a Premium!</h2>
        <p class="text-gray-300 text-sm mb-6">Ya tienes acceso completo a todo el catálogo.</p>
        <a href="index.php"
           class="bg-red-600 hover:bg-red-700 text-white px-8 py-3 rounded-xl font-semibold inline-block">
            Ver catálogo completo →
        </a>
    <?php else: ?>
        <div class="text-6xl mb-4">⭐</div>
        <h2 class="text-white text-2xl font-bold mb-1">Plan Premium</h2>
        <p class="text-gray-400 text-sm mb-6">
            Accede a estrenos y contenido exclusivo por solo
            <strong class="text-yellow-400">$9.99/mes</strong>
        </p>
        <ul class="text-left text-gray-300 text-sm mb-6 space-y-2 mx-auto max-w-xs">
            <li>✅ Catálogo completo sin restricciones</li>
            <li>✅ Estrenos y películas exclusivas</li>
            <li>✅ Recomendaciones personalizadas</li>
            <li>✅ Sin limitaciones</li>
        </ul>
        <form method="POST">
            <button type="submit"
                class="w-full bg-yellow-500 hover:bg-yellow-400 text-black font-bold py-3 rounded-xl text-lg">
                Activar Premium ahora
            </button>
        </form>
        <a href="index.php" class="block mt-4 text-gray-500 text-xs hover:text-gray-300">
            ← Volver al catálogo
        </a>
    <?php endif; ?>
</div>
</body>
</html>
