<?php
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

$conexion = new mysqli("localhost", "root", "", "cine_pelis");
//$conexion = new mysqli("127.0.0.1", "root", "", "cine_pelis", 3307);
if ($conexion->connect_error) die("Error de conexión: " . $conexion->connect_error);

// ── AGREGAR PELÍCULA ─────────────────────────────────────────────────────────
// ── AGREGAR PELÍCULA ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'agregar') {

    $nombre  = $_POST['nombre']      ?? '';
    $autor   = $_POST['autor']       ?? '';
    $anio    = (int)($_POST['anio']  ?? 0);
    $youtube = $_POST['youtube_url'] ?? '';
    $generos = $_POST['generos']     ?? [];

    $imagenBlob = null;
    $imagenTipo = 'image/jpeg';

    if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
        $imagenBlob = file_get_contents($_FILES['imagen']['tmp_name']);
        $imagenTipo = $_FILES['imagen']['type'];
    }

    $stmt = $conexion->prepare(
        "INSERT INTO peliculas (nombre, autor, anio, youtube_url, imagen, imagen_tipo)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $null = null;
    $stmt->bind_param("ssisbs", $nombre, $autor, $anio, $youtube, $null, $imagenTipo);

    if ($imagenBlob !== null) {
        $chunkSize = 65536;
        $offset    = 0;
        $len       = strlen($imagenBlob);
        while ($offset < $len) {
            $stmt->send_long_data(4, substr($imagenBlob, $offset, $chunkSize));
            $offset += $chunkSize;
        }
    }

    $stmt->execute();
    $nuevaId = $conexion->insert_id;
    $stmt->close();

    // Insertar géneros en tabla intermedia
    if (!empty($generos)) {
        $stmtG = $conexion->prepare(
            "INSERT INTO pelicula_generos (id_pelicula, id_genero) VALUES (?, ?)"
        );
        foreach ($generos as $idGen) {
            $idGen = (int)$idGen;
            $stmtG->bind_param("ii", $nuevaId, $idGen);
            $stmtG->execute();
        }
        $stmtG->close();
    }

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
        // Obtener los géneros de la película vista (ahora en tabla intermedia)
        $resGeneros = $conexion->query(
            "SELECT id_genero FROM pelicula_generos WHERE id_pelicula = $id_pelicula"
        );

        $generosIds = [];
        while ($g = $resGeneros->fetch_assoc()) {
            $generosIds[] = (int)$g['id_genero'];
        }

        if (!empty($generosIds)) {
            $idsStr = implode(',', $generosIds);

            // Buscar películas que compartan al menos un género
            $recRes = $conexion->query(
                "SELECT DISTINCT p.id_pelicula, p.nombre, p.youtube_url
                 FROM peliculas p
                 JOIN pelicula_generos pg ON p.id_pelicula = pg.id_pelicula
                 WHERE pg.id_genero IN ($idsStr)
                   AND p.id_pelicula != $id_pelicula
                 LIMIT 4"
            );

            while ($r = $recRes->fetch_assoc()) {
                $recomendaciones[] = [
                    'id'          => $r['id_pelicula'],
                    'nombre'      => $r['nombre'],
                    'img'         => "imagen.php?id=" . $r['id_pelicula'],
                    'youtube_url' => $r['youtube_url'] ?? ''
                ];
            }
        }
    }

    header('Content-Type: application/json');
    echo json_encode(['gusto' => $gusto, 'recomendaciones' => $recomendaciones]);
    exit;
}


// ── MODELO BOOLEANO ──────────────────────────────────────────────────────────
function parsearConsultaBooleana($consulta, $conexion) {
    // Normalizar espacios y operadores
    $consulta = trim($consulta);
    
    // Si está vacía, traer todo
    if ($consulta === '') {
        return "SELECT id_pelicula, nombre, autor, anio, genero, youtube_url FROM peliculas";
    }

    // Tokenizar respetando comillas, AND, OR, NOT
    $tokens = tokenizarBooleano($consulta);
    
    // Construir WHERE desde árbol booleano
    $where = construirWhere($tokens, $conexion);
    
    if ($where === false || $where === '') {
        return false; // Expresión inválida
    }
    
    return "SELECT id_pelicula, nombre, autor, anio, genero, youtube_url FROM peliculas WHERE ($where)";
}

function tokenizarBooleano($texto) {
    // Separar operadores por espacios (funciona con acentos)
    $texto = preg_replace('/\s+(AND|OR|NOT)\s+/i', ' $1 ', ' ' . trim($texto) . ' ');
    $texto = str_replace(['(', ')'], [' ( ', ' ) '], $texto);
    $partes = preg_split('/\s+/', trim($texto));
    return array_values(array_filter($partes, fn($p) => $p !== ''));
}

function construirWhere(&$tokens, $conexion) {
    return parseOrExpr($tokens, $conexion);
}

// OR tiene menor precedencia (se evalúa al final)
function parseOrExpr(&$tokens, $conexion) {
    $left = parseAndExpr($tokens, $conexion);
    
    while (!empty($tokens) && strtoupper($tokens[0]) === 'OR') {
        array_shift($tokens); // consumir OR
        $right = parseAndExpr($tokens, $conexion);
        $left = "($left OR $right)";
    }
    
    return $left;
}

// AND tiene mayor precedencia que OR
function parseAndExpr(&$tokens, $conexion) {
    $left = parseNotExpr($tokens, $conexion);
    
    while (!empty($tokens) && strtoupper($tokens[0]) === 'AND') {
        array_shift($tokens); // consumir AND
        $right = parseNotExpr($tokens, $conexion);
        $left = "($left AND $right)";
    }
    
    return $left;
}

// NOT tiene mayor precedencia que AND
function parseNotExpr(&$tokens, $conexion) {
    if (!empty($tokens) && strtoupper($tokens[0]) === 'NOT') {
        array_shift($tokens); // consumir NOT
        $expr = parsePrimario($tokens, $conexion);
        return "(NOT ($expr))";
    }
    return parsePrimario($tokens, $conexion);
}

// Término base: palabra o grupo entre paréntesis
function parsePrimario(&$tokens, $conexion) {
    if (empty($tokens)) return '1=1';

    if ($tokens[0] === '(') {
        array_shift($tokens);
        $expr = parseOrExpr($tokens, $conexion);
        if (!empty($tokens) && $tokens[0] === ')') array_shift($tokens);
        return $expr;
    }

    $raw         = array_shift($tokens);
    $termino     = $conexion->real_escape_string($raw);
    $normTermino = $conexion->real_escape_string(normalizar($raw));

    //   Detectar si el término es un año (número de 4 dígitos)
    if (preg_match('/^\d{4}$/', $raw)) {
        return "(anio = $termino)";
    }

    return "(
        nombre LIKE '%$termino%'
        OR autor   LIKE '%$termino%'
        OR genero  LIKE '%$termino%'
        OR nombre  LIKE '%$normTermino%'
        OR autor   LIKE '%$normTermino%'
        OR genero  LIKE '%$normTermino%'
    )";
}

// ── BÚSQUEDA ─────────────────────────────────────────────────────────────────
$busqueda = $_GET['q'] ?? '';
$errorBooleano = '';

$sqlBase = "SELECT p.id_pelicula, p.nombre, p.autor, p.anio, p.youtube_url,
                   GROUP_CONCAT(g.nombre ORDER BY g.nombre SEPARATOR ', ') AS genero
            FROM peliculas p
            LEFT JOIN pelicula_generos pg ON p.id_pelicula = pg.id_pelicula
            LEFT JOIN generos g           ON pg.id_genero  = g.id_genero";

if ($busqueda !== '') {
    $sql = parsearConsultaBooleana($busqueda, $conexion);
    if ($sql === false) {
        $errorBooleano = "Expresión booleana inválida.";
        $sql = $sqlBase . " GROUP BY p.id_pelicula HAVING 1=0";
    } else {
        // Envolver con JOIN de géneros
        $sql = $sqlBase . " GROUP BY p.id_pelicula HAVING " . extraerHaving($busqueda, $conexion);
    }
} else {
    $sql = $sqlBase . " GROUP BY p.id_pelicula";
}

$resultado = $conexion->query($sql);
if (!$resultado) die("Error: " . $conexion->error);

$sugerencia = $tipoError = $imagenSugerida = $sugerenciaId = "";

if ($resultado->num_rows == 0 && $busqueda != "") {
    $busquedaNorm    = normalizar($busqueda);
    $resPeliculas    = $conexion->query("SELECT id_pelicula, nombre, youtube_url FROM peliculas");
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
function extraerHaving($busqueda, $conexion) {
    // Reconstruir WHERE booleano usando HAVING para campos calculados
    $tokens = tokenizarBooleano($busqueda);
    return construirHaving($tokens, $conexion);
}

function construirHaving(&$tokens, $conexion) {
    return parseOrHaving($tokens, $conexion);
}

function parseOrHaving(&$tokens, $conexion) {
    $left = parseAndHaving($tokens, $conexion);
    while (!empty($tokens) && strtoupper($tokens[0]) === 'OR') {
        array_shift($tokens);
        $right = parseAndHaving($tokens, $conexion);
        $left = "($left OR $right)";
    }
    return $left;
}

function parseAndHaving(&$tokens, $conexion) {
    $left = parseNotHaving($tokens, $conexion);
    while (!empty($tokens) && strtoupper($tokens[0]) === 'AND') {
        array_shift($tokens);
        $right = parseNotHaving($tokens, $conexion);
        $left = "($left AND $right)";
    }
    return $left;
}

function parseNotHaving(&$tokens, $conexion) {
    if (!empty($tokens) && strtoupper($tokens[0]) === 'NOT') {
        array_shift($tokens);
        $expr = parsePrimarioHaving($tokens, $conexion);
        return "(NOT ($expr))";
    }
    return parsePrimarioHaving($tokens, $conexion);
}

function parsePrimarioHaving(&$tokens, $conexion) {
    if (empty($tokens)) return '1=1';

    if ($tokens[0] === '(') {
        array_shift($tokens);
        $expr = parseOrHaving($tokens, $conexion);
        if (!empty($tokens) && $tokens[0] === ')') array_shift($tokens);
        return $expr;
    }

    $raw     = array_shift($tokens);
    $termino = $conexion->real_escape_string($raw);
    $norm    = $conexion->real_escape_string(normalizar($raw));

    if (preg_match('/^\d{4}$/', $raw)) {
        return "anio = $termino";
    }

    // Buscar en nombre, autor Y en géneros concatenados
    return "(
        p.nombre LIKE '%$termino%' OR p.autor LIKE '%$termino%'
        OR p.nombre LIKE '%$norm%'  OR p.autor LIKE '%$norm%'
        OR GROUP_CONCAT(g.nombre SEPARATOR ',') LIKE '%$termino%'
        OR GROUP_CONCAT(g.nombre SEPARATOR ',') LIKE '%$norm%'
    )";
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
        <h1 class="text-white text-3xl font-bold">Catálogo de Películas</h1>
        <h3 class="text-white text-sm">Las mejores películas</h3>
    </header>

    <main class="flex-1">

        <!-- BARRA SUPERIOR -->
<div class="mb-8 flex flex-col items-center gap-3">
    <form method="GET" class="flex w-full max-w-2xl">
        <input type="text" name="q" placeholder='Ej: acción AND 2020  |  terror OR comedia  |  NOT romance'
            value="<?= htmlspecialchars($busqueda) ?>"
            class="flex-1 px-4 py-2 rounded-l-lg focus:outline-none text-sm">
        <button class="bg-red-600 text-white px-6 rounded-r-lg hover:bg-red-700 whitespace-nowrap">
            Buscar
        </button>
    </form>

    <!-- Ayuda de operadores -->
    <div class="flex gap-3 text-xs text-gray-400 flex-wrap justify-center">
        <span class="bg-gray-700 rounded px-2 py-1 text-blue-300 font-mono">AND</span> ambas palabras &nbsp;
        <span class="bg-gray-700 rounded px-2 py-1 text-green-300 font-mono">OR</span> cualquiera &nbsp;
        <span class="bg-gray-700 rounded px-2 py-1 text-red-300 font-mono">NOT</span> excluir &nbsp;
        <span class="bg-gray-700 rounded px-2 py-1 text-yellow-300 font-mono">( )</span> agrupar
    </div>

    <?php if ($errorBooleano): ?>
    <p class="text-red-400 text-sm"><?= $errorBooleano ?></p>
    <?php endif; ?>

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
<!-- MODAL: AGREGAR PELÍCULA -->
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
                <p class="text-gray-300 text-sm mb-2">Géneros (selecciona uno o más):</p>
                <div class="grid grid-cols-2 gap-2">
                    <?php
                    $resGeneros = $conexion->query("SELECT id_genero, nombre FROM generos ORDER BY nombre");
                    while ($g = $resGeneros->fetch_assoc()):
                    ?>
                    <label class="flex items-center gap-2 text-white text-sm cursor-pointer hover:text-red-400">
                        <input type="checkbox" name="generos[]" value="<?= $g['id_genero'] ?>"
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

<!-- MODAL: VER PELÍCULA + CONTADOR -->
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
            <p class="text-white font-semibold mb-2">Te pueden gustar también:</p>
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
                label.textContent = '¡Le gustó!';
                label.className = 'ml-auto text-sm font-semibold text-green-400';
            } else {
                label.textContent = 'No le gustó';
                label.className = 'ml-auto text-sm font-semibold text-red-400';
            }
            label.classList.remove('hidden');

            if (data.recomendaciones && data.recomendaciones.length > 0) {
                const grid = document.getElementById('recGrid');
                grid.innerHTML = '';
                data.recomendaciones.forEach(p => {
                    const div = document.createElement('div');
                    div.className = 'bg-gray-800 rounded-lg overflow-hidden text-white text-center text-xs cursor-pointer hover:ring-2 hover:ring-red-500';
                    div.innerHTML = `<img src="${p.img}" class="w-full h-28 object-cover"><p class="p-1">${p.nombre}</p>`;
                    div.addEventListener('click', () => verPelicula(p.id, p.youtube_url || ''));
                    grid.appendChild(div);
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