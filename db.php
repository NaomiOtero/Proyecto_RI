<?php

$conexion = new mysqli("localhost", "root", "", "cine_pelis");
//$conexion = new mysqli("127.0.0.1", "root", "", "cine_pelis", 3307);
if ($conexion->connect_error) die("Error de conexión: " . $conexion->connect_error);
$conexion->set_charset("utf8mb4");
