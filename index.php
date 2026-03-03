<?php
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

$conexion = new mysqli("localhost", "root", "", "cine_pelis");
if ($conexion->connect_error) die("Error de conexión: " . $conexion->connect_error);

// ── AGREGAR PELÍCULA ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'agregar') {

    $nombre  = $_POST['nombre']      ?? '';
    $autor   = $_POST['autor']       ?? '';
    $anio    = (int)($_POST['anio']  ?? 0);
    $genero  = $_POST['genero']      ?? '';
    $youtube = $_POST['youtube_url'] ?? '';

    $imagenBlob = null;
    $imagenTipo = 'image/jpeg';

    if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
        $imagenBlob = file_get_contents($_FILES['imagen']['tmp_name']);
        $imagenTipo = $_FILES['imagen']['type'];
    }

    $stmt = $conexion->prepare(
        "INSERT INTO peliculas (nombre, autor, anio, genero, youtube_url, imagen_tipo, imagen)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $null = null;
    $stmt->bind_param("ssisssb", $nombre, $autor, $anio, $genero, $youtube, $imagenTipo, $null);

    if ($imagenBlob !== null) {
        $chunkSize = 65536;
        $offset = 0;
        $len = strlen($imagenBlob);
        while ($offset < $len) {
            $stmt->send_long_data(6, substr($imagenBlob, $offset, $chunkSize));
            $offset += $chunkSize;
        }
    }

    $stmt->execute();
    $stmt->close();

    header("Location: index.php");
    exit;
}

// ── REGISTRAR TIEMPO ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'registrar_tiempo') {
    $id_pelicula = (int)$_POST['id_pelicula'];
    $segundos    = (int)$_POST['segundos'];
    $gusto       = $segundos >= 15 ? 1 : 0;

    $conexion->query("CREATE TABLE IF NOT EXISTS visualizaciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_pelicula INT NOT NULL,
        segundos INT NOT NULL,
        gusto TINYINT(1) NOT NULL,
        fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $conexion->query("INSERT INTO visualizaciones (id_pelicula, segundos, gusto)
                      VALUES ($id_pelicula, $segundos, $gusto)");

    $recomendaciones = [];
    if ($gusto) {
        $res = $conexion->query("SELECT genero FROM peliculas WHERE id_pelicula = $id_pelicula");
        if ($res && $fila = $res->fetch_assoc()) {
            $genero = $conexion->real_escape_string($fila['genero']);
            $recRes = $conexion->query(
                "SELECT id_pelicula, nombre FROM peliculas
                 WHERE genero = '$genero' AND id_pelicula != $id_pelicula LIMIT 4"
            );
            while ($r = $recRes->fetch_assoc()) {
                $recomendaciones[] = [
                    'id'     => $r['id_pelicula'],
                    'nombre' => $r['nombre'],
                    'img'    => "imagen.php?id=" . $r['id_pelicula']
                ];
            }
        }
    }

    header('Content-Type: application/json');
    echo json_encode(['gusto' => $gusto, 'recomendaciones' => $recomendaciones]);
    exit;
}

// ── BÚSQUEDA ─────────────────────────────────────────────────────────────────
$busqueda = $_GET['q'] ?? '';
$sql = "SELECT id_pelicula, nombre, autor, anio, genero, youtube_url
        FROM peliculas WHERE nombre LIKE '%" . $conexion->real_escape_string($busqueda) . "%'";
$resultado = $conexion->query($sql);
if (!$resultado) die("Error en la consulta: " . $conexion->error);

$sugerencia = $tipoError = $imagenSugerida = $sugerenciaId = "";

if ($resultado->num_rows == 0 && $busqueda != "") {
    $busquedaNorm    = normalizar($busqueda);
    $resPeliculas    = $conexion->query("SELECT id_pelicula, nombre FROM peliculas");
    $distanciaMinima = 999;

    while ($fila = $resPeliculas->fetch_assoc()) {
        $d = levenshtein($busquedaNorm, normalizar($fila['nombre']));
        if ($d < $distanciaMinima) {
            $distanciaMinima = $d;
            $sugerencia      = $fila['nombre'];
            $sugerenciaId    = $fila['id_pelicula'];
        }
    }

    if ($distanciaMinima <= 3) {
        $tipoError      = "Error de sintaxis";
        $imagenSugerida = "imagen.php?id=" . $sugerenciaId;
    } else {
        $tipoError  = "Error semántico";
        $sugerencia = $imagenSugerida = "";
    }
}

function normalizar($texto) {
    $texto = strtolower($texto);
    $texto = str_replace(['á','é','í','ó','ú','ñ'], ['a','e','i','o','u','n'], $texto);
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

        <!-- BARRA SUPERIOR -->
        <div class="mb-8 flex flex-col sm:flex-row justify-center items-center gap-3">
            <form method="GET" class="flex">
                <input type="text" name="q" placeholder="Buscar película..."
                    value="<?= htmlspecialchars($busqueda) ?>"
                    class="w-72 px-4 py-2 rounded-l-lg focus:outline-none">
                <button class="bg-red-600 text-white px-6 rounded-r-lg hover:bg-red-700">Buscar</button>
            </form>
            <button onclick="document.getElementById('modalAgregar').classList.remove('hidden')"
                class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg font-semibold">
                ＋ Agregar Película
            </button>
        </div>

        <!-- SUGERENCIAS -->
        <?php if ($resultado->num_rows == 0 && $busqueda != ""): ?>
        <div class="text-center text-white mt-6">
            <p>No se encontraron resultados</p>
            <?php if ($sugerencia): ?>
                <p class="mt-2 text-yellow-400">¿Quizás quisiste decir <b><?= htmlspecialchars($sugerencia) ?></b>?</p>
                <p class="text-sm text-gray-300">Tipo de error: <?= $tipoError ?></p>
                <?php if ($imagenSugerida): ?>
                <div class="mt-4 flex justify-center">
                    <img src="<?= $imagenSugerida ?>" alt="<?= htmlspecialchars($sugerencia) ?>"
                        style="width:200px;border-radius:10px;">
                </div>
                <?php endif; ?>
            <?php else: ?>
                <p class="text-sm text-gray-300">Tipo de error: <?= $tipoError ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- GRID -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
        <?php if ($resultado->num_rows > 0): ?>
            <?php while ($fila = $resultado->fetch_assoc()): ?>
            <div class="bg-gray-800 text-white rounded-xl shadow-lg overflow-hidden transition transform hover:-translate-y-2 hover:shadow-2xl">
                <img src="imagen.php?id=<?= $fila['id_pelicula'] ?>"
                     alt="<?= htmlspecialchars($fila['nombre']) ?>"
                     class="h-72 w-full object-cover">
                <div class="p-4 flex flex-col">
                    <h5 class="text-lg font-semibold text-center mb-1"><?= htmlspecialchars($fila['nombre']) ?></h5>
                    <p class="text-sm text-gray-300 mb-1 text-center"><?= htmlspecialchars($fila['autor']) ?> · <?= $fila['anio'] ?></p>
                    <p class="text-xs text-red-400 text-center mb-3">🎬 <?= htmlspecialchars($fila['genero']) ?></p>
                    <button
                        data-id="<?= $fila['id_pelicula'] ?>"
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

<!-- MODAL: AGREGAR PELÍCULA -->
<div id="modalAgregar" class="hidden fixed inset-0 bg-black bg-opacity-70 flex items-center justify-center z-50">
    <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6 w-full max-w-md mx-4 shadow-2xl">
        <h2 class="text-white text-2xl font-bold mb-4 text-center">🎬 Nueva Película</h2>
        <form method="POST" enctype="multipart/form-data" class="flex flex-col gap-3">
            <input type="hidden" name="accion" value="agregar">
            <input type="text" name="nombre" placeholder="Nombre de la película" required
                class="px-4 py-2 rounded-lg bg-gray-800 text-white border border-gray-600 focus:outline-none focus:border-red-500">
            <input type="text" name="autor" placeholder="Director / Autor" required
                class="px-4 py-2 rounded-lg bg-gray-800 text-white border border-gray-600 focus:outline-none focus:border-red-500">
            <input type="number" name="anio" placeholder="Año" min="1900" max="2099" required
                class="px-4 py-2 rounded-lg bg-gray-800 text-white border border-gray-600 focus:outline-none focus:border-red-500">
            <select name="genero" required
                class="px-4 py-2 rounded-lg bg-gray-800 text-white border border-gray-600 focus:outline-none focus:border-red-500">
                <option value="">-- Género --</option>
                <option>Acción</option><option>Comedia</option><option>Drama</option>
                <option>Terror</option><option>Ciencia Ficción</option><option>Romance</option>
                <option>Animación</option><option>Documental</option><option>Thriller</option>
            </select>
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

<!-- MODAL: VER PELÍCULA + CONTADOR -->
<div id="modalVideo" class="hidden fixed inset-0 bg-black bg-opacity-90 flex items-center justify-center z-50">
    <div class="bg-gray-900 border border-gray-700 rounded-2xl p-4 w-full max-w-3xl mx-4 shadow-2xl">
        <div class="flex justify-between items-center mb-3">
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
        <div id="recomendaciones" class="hidden mt-4">
            <p class="text-white font-semibold mb-2">🎯 Te pueden gustar también:</p>
            <div id="recGrid" class="grid grid-cols-2 sm:grid-cols-4 gap-3"></div>
        </div>
    </div>
</div>

<script>
let contadorInterval = null;
let segundosVisto    = 0;
let peliculaActualId = null;
let yaRegistro       = false;

function verPeliculaBtn(btn) {
    verPelicula(btn.dataset.id, btn.dataset.url);
}

function verPelicula(id, youtubeUrl) {
    peliculaActualId = id;
    segundosVisto    = 0;
    yaRegistro       = false;

    let embedUrl = youtubeUrl;
    const matchV  = youtubeUrl.match(/[?&]v=([^&]+)/);
    const matchBe = youtubeUrl.match(/youtu\.be\/([^?]+)/);
    const videoId = matchV ? matchV[1] : (matchBe ? matchBe[1] : null);
    if (videoId) embedUrl = `https://www.youtube.com/embed/${videoId}?autoplay=1`;

    document.getElementById('ytFrame').src = embedUrl;
    document.getElementById('contadorDisplay').textContent = '0s';
    document.getElementById('gustoLabel').classList.add('hidden');
    document.getElementById('recomendaciones').classList.add('hidden');
    document.getElementById('recGrid').innerHTML = '';
    document.getElementById('modalVideo').classList.remove('hidden');

    clearInterval(contadorInterval);
    contadorInterval = setInterval(() => {
        segundosVisto++;
        document.getElementById('contadorDisplay').textContent = segundosVisto + 's';
        if (segundosVisto === 15 && !yaRegistro) {
            yaRegistro = true;
            registrarTiempo();
        }
    }, 1000);
}

function cerrarVideo() {
    clearInterval(contadorInterval);
    document.getElementById('ytFrame').src = '';
    document.getElementById('modalVideo').classList.add('hidden');
    if (!yaRegistro && peliculaActualId) registrarTiempo();
}

function registrarTiempo() {
    const fd = new FormData();
    fd.append('accion', 'registrar_tiempo');
    fd.append('id_pelicula', peliculaActualId);
    fd.append('segundos', segundosVisto);

    fetch('index.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            const label = document.getElementById('gustoLabel');
            if (data.gusto) {
                label.textContent = '✅ ¡Le gustó!';
                label.className = 'ml-auto text-sm font-semibold text-green-400';
            } else {
                label.textContent = '👎 No le gustó';
                label.className = 'ml-auto text-sm font-semibold text-red-400';
            }
            label.classList.remove('hidden');

            if (data.recomendaciones && data.recomendaciones.length > 0) {
                const grid = document.getElementById('recGrid');
                grid.innerHTML = '';
                data.recomendaciones.forEach(p => {
                    grid.innerHTML += `
                        <div class="bg-gray-800 rounded-lg overflow-hidden text-white text-center text-xs cursor-pointer hover:ring-2 hover:ring-red-500"
                             onclick="verPelicula(${p.id}, '')">
                            <img src="${p.img}" class="w-full h-28 object-cover">
                            <p class="p-1">${p.nombre}</p>
                        </div>`;
                });
                document.getElementById('recomendaciones').classList.remove('hidden');
            }
        })
        .catch(() => {});
}
</script>
</body>
</html>
<?php $conexion->close(); ?>