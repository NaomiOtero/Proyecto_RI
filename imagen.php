<?php
/**
 * imagen.php — Sirve el BLOB de imagen de una película.
 * Uso: <img src="imagen.php?id=3">
 */
$conexion = new mysqli("localhost", "root", "", "cine_pelis");
// $conexion = new mysqli("127.0.0.1", "root", "", "cine_pelis", 3307);
if ($conexion->connect_error) { http_response_code(500); exit; }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit; }

$stmt = $conexion->prepare("SELECT imagen, imagen_tipo FROM peliculas WHERE id_pelicula = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) { http_response_code(404); exit; }

$stmt->bind_result($blob, $tipo);
$stmt->fetch();

if (!$blob) { http_response_code(204); exit; }

header("Content-Type: " . ($tipo ?: 'image/jpeg'));
header("Cache-Control: max-age=86400");
echo $blob;

$stmt->close();
$conexion->close();
?>