<?php
/**
 * index.php — Controlador principal y vista del catálogo de películas.
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

require_once 'funciones.php';

// ── CONEXIÓN ──────────────────────────────────────────────────────────────────
$conexion = new mysqli("localhost", "root", "", "cine_pelis");
// $conexion = new mysqli("127.0.0.1", "root", "", "cine_pelis", 3307);
if ($conexion->connect_error) die("Error de conexión: " . $conexion->connect_error);

// ── CONTROLADOR POST ──────────────────────────────────────────────────────────
$accion = $_POST['accion'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($accion === 'agregar') {
        agregarPelicula($conexion, $_POST, $_FILES);
        header("Location: index.php");
        exit;
    }

    if ($accion === 'registrar_tiempo') {
        $idPelicula = (int)($_POST['id_pelicula'] ?? 0);
        $segundos   = (int)($_POST['segundos']    ?? 0);
        $data       = registrarTiempo($conexion, $idPelicula, $segundos);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}

// ── BÚSQUEDA ──────────────────────────────────────────────────────────────────
$busqueda = trim($_GET['q'] ?? '');
['resultado' => $resultado, 'errorBooleano' => $errorBooleano] = buscarPeliculas($conexion, $busqueda);

if (!$resultado) die("Error en la consulta: " . $conexion->error);

// ── SUGERENCIAS ───────────────────────────────────────────────────────────────
$sugerencia = $tipoError = $imagenSugerida = $sugerenciaId = '';

if ($resultado->num_rows === 0 && $busqueda !== '') {
    $datos          = obtenerSugerencia($conexion, $busqueda);
    $sugerencia     = $datos['sugerencia'];
    $sugerenciaId   = $datos['sugerenciaId'];
    $imagenSugerida = $datos['imagenSugerida'];
    $tipoError      = $datos['tipoError'];
}

// ── GÉNEROS PARA EL MODAL ─────────────────────────────────────────────────────
$resGeneros = $conexion->query("SELECT id_genero, nombre FROM generos ORDER BY nombre");
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

    <!-- HEADER -->
    <header class="bg-red-700 rounded-xl text-center p-4 mb-6">
        <h1 class="text-white text-3xl font-bold">📽️ Catálogo de Películas</h1>
        <h3 class="text-white text-sm">Las mejores películas</h3>
    </header>

    <main class="flex-1">

        <!-- BARRA SUPERIOR: BUSCADOR + AYUDA + BOTÓN AGREGAR -->
        <div class="mb-8 flex flex-col items-center gap-3">
            <form method="GET" class="flex w-full max-w-2xl">
                <input type="text" name="q"
                    placeholder='Ej: acción AND 2020  |  terror OR comedia  |  NOT romance'
                    value="<?= htmlspecialchars($busqueda) ?>"
                    class="flex-1 px-4 py-2 rounded-l-lg focus:outline-none text-sm">
                <button class="bg-red-600 text-white px-6 rounded-r-lg hover:bg-red-700 whitespace-nowrap">
                    Buscar
                </button>
            </form>

            <div class="flex gap-3 text-xs text-gray-400 flex-wrap justify-center">
                <span class="bg-gray-700 rounded px-2 py-1 text-blue-300 font-mono">AND</span> ambas palabras &nbsp;
                <span class="bg-gray-700 rounded px-2 py-1 text-green-300 font-mono">OR</span> cualquiera &nbsp;
                <span class="bg-gray-700 rounded px-2 py-1 text-red-300 font-mono">NOT</span> excluir &nbsp;
                <span class="bg-gray-700 rounded px-2 py-1 text-yellow-300 font-mono">( )</span> agrupar
            </div>

            <?php if ($errorBooleano): ?>
                <p class="text-red-400 text-sm"><?= htmlspecialchars($errorBooleano) ?></p>
            <?php endif; ?>

            <button onclick="document.getElementById('modalAgregar').classList.remove('hidden')"
                class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg font-semibold">
                ＋ Agregar Película
            </button>
        </div>

        <!-- SUGERENCIAS -->
        <?php if ($resultado->num_rows === 0 && $busqueda !== ''): ?>
        <div class="text-center text-white mt-6">
            <p>No se encontraron resultados</p>
            <?php if ($sugerencia): ?>
                <p class="mt-2 text-yellow-400">
                    ¿Quizás quisiste decir <b><?= htmlspecialchars($sugerencia) ?></b>?
                </p>
                <p class="text-sm text-gray-300">Tipo de error: <?= htmlspecialchars($tipoError) ?></p>
                <?php if ($imagenSugerida): ?>
                <div class="mt-4 flex justify-center">
                    <img src="<?= htmlspecialchars($imagenSugerida) ?>"
                         alt="<?= htmlspecialchars($sugerencia) ?>"
                         style="width:200px;border-radius:10px;">
                </div>
                <?php endif; ?>
            <?php else: ?>
                <p class="text-sm text-gray-300">Tipo de error: <?= htmlspecialchars($tipoError) ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- GRID DE PELÍCULAS -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
            <?php if ($resultado->num_rows > 0): ?>
                <?php while ($fila = $resultado->fetch_assoc()): ?>
                <div class="bg-gray-800 text-white rounded-xl shadow-lg overflow-hidden transition transform hover:-translate-y-2 hover:shadow-2xl">
                    <img src="imagen.php?id=<?= (int)$fila['id_pelicula'] ?>"
                         alt="<?= htmlspecialchars($fila['nombre']) ?>"
                         class="h-72 w-full object-cover">
                    <div class="p-4 flex flex-col">
                        <h5 class="text-lg font-semibold text-center mb-1">
                            <?= htmlspecialchars($fila['nombre']) ?>
                        </h5>
                        <p class="text-sm text-gray-300 mb-1 text-center">
                            <?= htmlspecialchars($fila['autor']) ?> · <?= (int)$fila['anio'] ?>
                        </p>
                        <p class="text-xs text-red-400 text-center mb-3">
                            🎬 <?= htmlspecialchars($fila['genero'] ?? '') ?>
                        </p>
                        <button
                            data-id="<?= (int)$fila['id_pelicula'] ?>"
                            data-url="<?= htmlspecialchars($fila['youtube_url'] ?? '', ENT_QUOTES) ?>"
                            onclick="verPeliculaBtn(this)"
                            class="mt-auto bg-red-600 hover:bg-red-700 rounded-lg py-2 w-full">
                            ▶ Ver película
                        </button>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p class="text-white col-span-full text-center">No se encontraron películas</p>
            <?php endif; ?>
        </div>

    </main>

    <footer class="bg-black text-white text-center rounded-xl p-3 mt-6">
        <p class="text-sm">&copy; 2026 Catálogo de Películas</p>
    </footer>
</div>

<!-- ── MODAL: AGREGAR PELÍCULA ──────────────────────────────────────────────── -->
<div id="modalAgregar" class="hidden fixed inset-0 bg-black bg-opacity-70 flex items-center justify-center z-50">
    <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6 w-full max-w-md mx-4 shadow-2xl max-h-screen overflow-y-auto">
        <h2 class="text-white text-2xl font-bold mb-4 text-center">🎬 Nueva Película</h2>
        <form method="POST" enctype="multipart/form-data" class="flex flex-col gap-3">
            <input type="hidden" name="accion" value="agregar">
            <input type="text" name="nombre" placeholder="Nombre de la película" required
                class="px-4 py-2 rounded-lg bg-gray-800 text-white border border-gray-600 focus:outline-none focus:border-red-500">
            <input type="text" name="autor" placeholder="Director / Autor" required
                class="px-4 py-2 rounded-lg bg-gray-800 text-white border border-gray-600 focus:outline-none focus:border-red-500">
            <input type="number" name="anio" placeholder="Año" min="1900" max="2099" required
                class="px-4 py-2 rounded-lg bg-gray-800 text-white border border-gray-600 focus:outline-none focus:border-red-500">

            <!-- CHECKBOXES DE GÉNEROS -->
            <div class="bg-gray-800 border border-gray-600 rounded-lg p-3">
                <p class="text-gray-300 text-sm mb-2">🎭 Géneros (selecciona uno o más):</p>
                <div class="grid grid-cols-2 gap-2">
                    <?php while ($g = $resGeneros->fetch_assoc()): ?>
                    <label class="flex items-center gap-2 text-white text-sm cursor-pointer hover:text-red-400">
                        <input type="checkbox" name="generos[]" value="<?= (int)$g['id_genero'] ?>"
                            class="accent-red-500 w-4 h-4">
                        <?= htmlspecialchars($g['nombre']) ?>
                    </label>
                    <?php endwhile; ?>
                </div>
            </div>

            <input type="url" name="youtube_url" placeholder="https://www.youtube.com/watch?v=..."
                class="px-4 py-2 rounded-lg bg-gray-800 text-white border border-gray-600 focus:outline-none focus:border-red-500">
            <label class="text-gray-300 text-sm">Imagen (póster):</label>
            <input type="file" name="imagen" accept="image/*" required
                class="text-gray-300 text-sm file:mr-3 file:py-1 file:px-3 file:rounded-lg file:bg-red-600 file:text-white file:border-0">

            <div class="flex gap-3 mt-2">
                <button type="submit"
                    class="flex-1 bg-green-600 hover:bg-green-700 text-white py-2 rounded-lg font-semibold">
                    Guardar
                </button>
                <button type="button"
                    onclick="document.getElementById('modalAgregar').classList.add('hidden')"
                    class="flex-1 bg-gray-600 hover:bg-gray-700 text-white py-2 rounded-lg">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── MODAL: VER PELÍCULA + CONTADOR ───────────────────────────────────────── -->
<div id="modalVideo" class="hidden fixed inset-0 bg-black bg-opacity-90 flex items-center justify-center z-50">
    <div class="bg-gray-900 border border-gray-700 rounded-2xl p-4 w-full max-w-3xl mx-4 shadow-2xl max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-3 sticky top-0 bg-gray-900 z-10 py-2">
            <h2 id="videoTitulo" class="text-white text-xl font-bold"></h2>
            <button onclick="cerrarVideo()" class="text-gray-400 hover:text-white text-2xl">✕</button>
        </div>
        <div class="relative w-full" style="padding-top:56.25%">
            <iframe id="ytFrame" class="absolute inset-0 w-full h-full rounded-xl"
                frameborder="0" allowfullscreen allow="autoplay; encrypted-media"></iframe>
        </div>
        <div class="mt-3 flex items-center gap-3">
            <span class="text-gray-300 text-sm">⏱ Tiempo viendo:</span>
            <span id="contadorDisplay" class="text-yellow-400 font-mono text-lg">0s</span>
            <span id="gustoLabel" class="ml-auto text-sm font-semibold hidden"></span>
        </div>
        <div id="recomendaciones" class="hidden mt-4 pb-2">
            <p class="text-white font-semibold mb-2">🎯 Te pueden gustar también:</p>
            <div id="recGrid" class="grid grid-cols-2 sm:grid-cols-4 gap-3"></div>
        </div>
    </div>
</div>

<script src="assets/js/main.js"></script>
</body>
</html>
<?php $conexion->close(); ?>