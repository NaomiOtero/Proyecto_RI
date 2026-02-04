<?php
$conexion = new mysqli("localhost", "root", "", "cine_pelis");

if ($conexion->connect_error) {
    die("Error de conexión: " . $conexion->connect_error);
}

$busqueda = $_GET['q'] ?? '';

$sql = "SELECT * FROM peliculas WHERE nombre LIKE '%$busqueda%'";
$resultado = $conexion->query($sql);

if (!$resultado) {
    die("Error en la consulta: " . $conexion->error);
}

$sugerencia = "";
$tipoError = "";
$imagenSugerida = "";

if ($resultado->num_rows == 0 && $busqueda != "") {

    $busquedaNorm = normalizar($busqueda);

    $sqlPeliculas = "SELECT nombre, imagen FROM peliculas";
    $resPeliculas = $conexion->query($sqlPeliculas);

    $distanciaMinima = 999;

    while ($fila = $resPeliculas->fetch_assoc()) {

        $nombreNorm = normalizar($fila['nombre']);
        $distancia = levenshtein($busquedaNorm, $nombreNorm);

        if ($distancia < $distanciaMinima) {
            $distanciaMinima = $distancia;
            $sugerencia = $fila['nombre'];
            $imagenSugerida = $fila['imagen'];
        }
    }

    if ($distanciaMinima <= 3) {
        $tipoError = "Error de sintaxis";
    } else {
        $tipoError = "Error semántico";
        $sugerencia = "";
        $imagenSugerida = "";
    }
}


function normalizar($texto) {
    $texto = strtolower($texto);
    $texto = str_replace(
        ['á','é','í','ó','ú','ñ'],
        ['a','e','i','o','u','n'],
        $texto
    );
    $texto = preg_replace('/\b(el|la|los|las)\b/', '', $texto);
    return trim($texto);
}

?>


<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Catálogo de Películas</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 min-h-screen p-4 flex flex-col">

<div class="max-w-7xl mx-auto bg-gray-900 border-2 border-black rounded-2xl shadow-xl p-4 flex flex-col flex-1">

    <header class="bg-red-700 rounded-xl text-center p-4 mb-6">
        <h1 class="text-white text-3xl font-bold">📽️ Catálogo de Películas</h1>
        <h3 class="text-white text-sm">Las mejores películas</h3>
    </header>

    <main class="flex-1">

        <!-- BUSCADOR -->
        <form method="GET" class="mb-8 flex justify-center">
            <input
                type="text"
                name="q"
                placeholder="Buscar película..."
                value="<?= htmlspecialchars($busqueda) ?>"
                class="w-80 px-4 py-2 rounded-l-lg focus:outline-none"
            >
            <button class="bg-red-600 text-white px-6 rounded-r-lg hover:bg-red-700">
                Buscar
            </button>
        </form>

                <?php if ($resultado->num_rows == 0 && $busqueda != ""): ?>
            <div class="text-center text-white mt-6">

                <p>No se encontraron resultados</p>

                <?php if ($sugerencia): ?>
                    <p class="mt-2 text-yellow-400">
                        ¿Quizás quisiste decir <b><?= $sugerencia ?></b>?
                    </p>

                    <p class="text-sm text-gray-300">
                        Tipo de error: <?= $tipoError ?>
                    </p>

                    <?php if ($imagenSugerida): ?>
                        <div class="mt-4 flex justify-center">
                            <img src="<?= $imagenSugerida ?>" 
                                alt="<?= $sugerencia ?>"
                                style="width:200px;border-radius:10px;">
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <p class="text-sm text-gray-300">
                        Tipo de error: <?= $tipoError ?>
                    </p>
                <?php endif; ?>

            </div>
        <?php endif; ?>



        <!-- GRID -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">

        <?php if ($resultado->num_rows > 0): ?>
            <?php while ($fila = $resultado->fetch_assoc()): ?>

            <div class="bg-gray-800 text-white rounded-xl shadow-lg overflow-hidden transition transform hover:-translate-y-2 hover:shadow-2xl">

                <img src="<?= $fila['imagen'] ?>"
                     alt="<?= $fila['nombre'] ?>"
                     class="h-72 w-full object-cover">

                <div class="p-4 flex flex-col">
                    <h5 class="text-lg font-semibold text-center mb-2">
                        <?= $fila['nombre'] ?>
                    </h5>

                    <p class="text-sm text-gray-300 mb-4 text-center">
                        <?= $fila['autor'] ?> · <?= $fila['anio'] ?>
                    </p>

                    <button class="mt-auto bg-red-600 hover:bg-red-700 rounded-lg py-2 w-full">
                        Ver película
                    </button>
                </div>
            </div>

            <?php endwhile; ?>
        <?php else: ?>
            <p class="text-white col-span-full text-center">
                No se encontraron películas 
            </p>
        <?php endif; ?>

        </div>
    </main>

    <footer class="bg-black text-white text-center rounded-xl p-3 mt-6">
        <p class="text-sm">&copy; 2026 Catálogo de Películas</p>
    </footer>

</div>

</body>
</html>

<?php $conexion->close(); ?>
