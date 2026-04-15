<?php
/**
 * index.php — Controlador principal y vista del catálogo de películas.
 */
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');
session_start();

// ── AUTENTICACIÓN ─────────────────────────────────────────────────────────────
if (!isset($_SESSION['id_usuario'])) {
    header("Location: login.php");
    exit;
}
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$idPlanUsuario = (int)$_SESSION['id_plan'];
$nombreUsuario = $_SESSION['nombre'];
$nombrePlan    = $_SESSION['nombre_plan'] ?? ($idPlanUsuario == 2 ? 'Premium' : 'Básico');

require_once 'funciones.php';
require_once 'db.php';

// ── VERIFICAR MÚLTIPLES USUARIOS EN CUENTA ───────────────────────────────────
$idCuenta = (int)$_SESSION['id_cuenta'];
$usuariosCuenta = [];
$result = $conexion->query("SELECT id_usuario, nombre, email FROM usuarios WHERE id_cuenta = $idCuenta");
while ($row = $result->fetch_assoc()) {
    $usuariosCuenta[] = $row;
}
$mostrarMenuPerfiles = count($usuariosCuenta) > 1;

// ── CONTROLADOR POST ──────────────────────────────────────────────────────────
$accion = $_POST['accion'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($accion === 'cambiar_perfil') {
        $idUsuarioSeleccionado = (int)($_POST['id_usuario'] ?? 0);
        $password = $_POST['password'] ?? '';

        // Verificar que el usuario pertenece a la cuenta
        $stmt = $conexion->prepare("SELECT password, nombre FROM usuarios WHERE id_usuario = ? AND id_cuenta = ?");
        $stmt->bind_param("ii", $idUsuarioSeleccionado, $idCuenta);
        $stmt->execute();
        $stmt->bind_result($hash, $nombre);
        if ($stmt->fetch() && password_verify($password, $hash)) {
            $_SESSION['id_usuario'] = $idUsuarioSeleccionado;
            $_SESSION['nombre'] = $nombre;
            header("Location: index.php");
            exit;
        } else {
            $errorPerfil = "Contraseña incorrecta.";
        }
        $stmt->close();
    }

    if ($accion === 'agregar') {
        agregarPelicula($conexion, $_POST, $_FILES);
        header("Location: index.php");
        exit;
    }

    if ($accion === 'registrar_tiempo') {
        $idPelicula = (int)($_POST['id_pelicula'] ?? 0);
        $segundos   = (int)($_POST['segundos']    ?? 0);
        $idUsuario  = (int)$_SESSION['id_usuario'];
        $data       = registrarTiempo($conexion, $idPelicula, $segundos, $idUsuario, $idPlanUsuario);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}

// ── BÚSQUEDA BOOLEANA CON SELECTS ────────────────────────────────────────────
// Construir la query 'q' a partir de los selects si vienen por GET
$genero1  = trim($_GET['genero1']  ?? '');
$operador = strtoupper(trim($_GET['operador'] ?? ''));
$genero2  = trim($_GET['genero2']  ?? '');

// NOT solo necesita genero1
$busqueda = '';
if ($genero1 !== '') {
    $operadoresValidos = ['AND', 'OR', 'NOT', 'SOLO'];
    $operador = in_array($operador, $operadoresValidos) ? $operador : 'SOLO';

    if ($operador === 'NOT') {
        $busqueda = "NOT $genero1";
    } elseif ($operador === 'SOLO' || $genero2 === '') {
        $busqueda = $genero1;
    } else {
        $busqueda = "$genero1 $operador $genero2";
    }
}

// También permitir búsqueda manual por texto (compatibilidad)
$busquedaManual = trim($_GET['q'] ?? '');
if ($busquedaManual !== '' && $busqueda === '') {
    $busqueda = $busquedaManual;
}

['resultado' => $resultado, 'errorBooleano' => $errorBooleano] =
    buscarPeliculas($conexion, $busqueda, $idPlanUsuario);

if (!$resultado) die("Error en la consulta: " . $conexion->error);

// ── SUGERENCIAS ───────────────────────────────────────────────────────────────
$sugerencia = $tipoError = $imagenSugerida = $sugerenciaId = '';

if ($resultado->num_rows === 0 && $busqueda !== '') {
    $datos          = obtenerSugerencia($conexion, $busqueda, $idPlanUsuario);
    $sugerencia     = $datos['sugerencia'];
    $sugerenciaId   = $datos['sugerenciaId'];
    $imagenSugerida = $datos['imagenSugerida'];
    $tipoError      = $datos['tipoError'];
}

// ── GÉNEROS (para modal agregar y para selects de búsqueda) ───────────────────
$resGenerosArr = [];
$resGeneros = $conexion->query("SELECT id_genero, nombre FROM generos ORDER BY nombre");
while ($g = $resGeneros->fetch_assoc()) $resGenerosArr[] = $g;

// ── TOP 10 PELÍCULAS ──────────────────────────────────────────────────────────
$top10 = [];
$topRes = $conexion->query(
    "SELECT p.id_pelicula, p.nombre, COUNT(DISTINCT v.id_usuario) as likes
     FROM peliculas p
     JOIN visualizaciones v ON p.id_pelicula = v.id_pelicula
     WHERE v.gusto = 1 AND p.id_plan <= $idPlanUsuario
     GROUP BY p.id_pelicula, p.nombre
     ORDER BY likes DESC
     LIMIT 10"
);
while ($t = $topRes->fetch_assoc()) {
    $top10[] = $t;
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

    <!-- HEADER -->
    <header class="bg-red-700 rounded-xl p-4 mb-6 relative flex items-center justify-center">
        <div class="text-center">
            <h1 class="text-white text-3xl font-bold">📽️ Catálogo de Películas</h1>
            <h3 class="text-white text-sm">Las mejores películas</h3>
        </div>

        <!-- Usuario + Cerrar sesión -->
        <div class="absolute right-4 top-1/2 -translate-y-1/2 flex flex-col items-end gap-1">
            <span class="text-white text-xs font-semibold">
                👤 <?= htmlspecialchars($nombreUsuario) ?>
            </span>
            <span class="text-xs font-semibold <?= $idPlanUsuario == 2 ? 'text-yellow-300' : 'text-gray-200' ?>">
                <?= $idPlanUsuario == 2 ? '⭐ Premium' : '📦 Básico' ?>
            </span>
            <?php if ($mostrarMenuPerfiles): ?>
            <button onclick="document.getElementById('modalPerfiles').classList.remove('hidden')"
                class="text-xs bg-black bg-opacity-40 hover:bg-opacity-70 text-white px-2 py-1 rounded-lg">
                Cambiar perfil
            </button>
            <?php endif; ?>
            <a href="?logout=1"
               onclick="return confirm('¿Cerrar sesión?')"
               class="flex items-center gap-1 text-xs bg-black bg-opacity-40 hover:bg-opacity-70
                      text-white px-2 py-1 rounded-lg transition mt-1">
                🚪 Cerrar sesión
            </a>
        </div>
    </header>

    <!-- MAIN CONTENT -->
    <div class="flex-1 flex">

        <!-- LEFT: CATÁLOGO -->
        <div class="flex-1 pr-6">

    <main class="flex-1">

        <!-- BANNER UPGRADE -->
        <?php if ($idPlanUsuario == 1): ?>
        <div class="mb-5 p-3 bg-yellow-900 border border-yellow-600 rounded-xl text-center">
            <p class="text-yellow-200 text-sm">
                ⭐ ¿Quieres ver estrenos y contenido exclusivo?
                <strong>Actualiza a Premium</strong> por solo $9.99/mes.
                <a href="upgrade.php" class="underline text-yellow-300 ml-1 font-semibold">Mejorar plan →</a>
            </p>
        </div>
        <?php endif; ?>

        <!-- ── BUSCADOR BOOLEANO CON SELECTS ────────────────────────────────── -->
         <div class="mb-8 flex flex-col items-center gap-3">
            <form method="GET" class="flex w-full max-w-2xl">
                <input type="text" name="q"
                    placeholder='Escribe el nombre de tu pelicula'
                    value="<?= htmlspecialchars($busqueda) ?>"
                    class="flex-1 px-4 py-2 rounded-l-lg focus:outline-none text-sm">
                <button class="bg-red-600 text-white px-6 rounded-r-lg hover:bg-red-700 whitespace-nowrap">
                    Buscar
                </button>
            </form>
        </div>
        <div class="mb-8 flex flex-col items-center gap-4">
            <form method="GET" id="formBusqueda" class="w-full max-w-3xl">

                <div class="bg-gray-800 border border-gray-600 rounded-2xl p-4 flex flex-col gap-4">

                    <p class="text-gray-300 text-sm font-semibold text-center">🔍 Búsqueda por género</p>

                    <!-- FILA DE SELECTS -->
                    <div class="flex flex-col sm:flex-row items-center gap-3">

                        <!-- GÉNERO 1 -->
                        <select name="genero1" id="genero1"
                            onchange="actualizarBuscador()"
                            class="flex-1 px-3 py-2 rounded-lg bg-gray-700 text-white border border-gray-500
                                   focus:outline-none focus:border-red-500 text-sm">
                            <option value="">-- Género --</option>
                            <?php foreach ($resGenerosArr as $g): ?>
                            <option value="<?= htmlspecialchars($g['nombre']) ?>"
                                <?= $genero1 === $g['nombre'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($g['nombre']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>

                        <!-- OPERADOR -->
                        <select name="operador" id="operador"
                            onchange="actualizarBuscador()"
                            class="w-full sm:w-36 px-3 py-2 rounded-lg bg-gray-700 text-white border border-gray-500
                                   focus:outline-none focus:border-red-500 text-sm font-mono">
                            <option value="SOLO" <?= $operador === 'SOLO' || $operador === '' ? 'selected' : '' ?>>Solo este</option>
                            <option value="AND"  <?= $operador === 'AND'  ? 'selected' : '' ?>>AND</option>
                            <option value="OR"   <?= $operador === 'OR'   ? 'selected' : '' ?>>OR</option>
                            <option value="NOT"  <?= $operador === 'NOT'  ? 'selected' : '' ?>>NOT</option>
                        </select>

                        <!-- GÉNERO 2 (oculto si operador = SOLO o NOT) -->
                        <select name="genero2" id="genero2"
                            class="flex-1 px-3 py-2 rounded-lg bg-gray-700 text-white border border-gray-500
                                   focus:outline-none focus:border-red-500 text-sm
                                   <?= in_array($operador, ['SOLO','NOT','']) ? 'opacity-40 pointer-events-none' : '' ?>">
                            <option value="">-- Segundo género --</option>
                            <?php foreach ($resGenerosArr as $g): ?>
                            <option value="<?= htmlspecialchars($g['nombre']) ?>"
                                <?= $genero2 === $g['nombre'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($g['nombre']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>

                    </div>

                    <!-- EXPLICACIÓN EN TIEMPO REAL -->
                    <div id="explicacion"
                         class="text-center text-xs text-gray-400 bg-gray-900 rounded-lg px-3 py-2 font-mono min-h-[28px]">
                    </div>

                    <!-- BOTONES -->
                    <div class="flex gap-3 justify-center">
                        <button type="submit"
                            class="bg-red-600 hover:bg-red-700 text-white px-8 py-2 rounded-lg font-semibold">
                            🔍 Buscar
                        </button>
                        <a href="index.php"
                            class="bg-gray-600 hover:bg-gray-500 text-white px-6 py-2 rounded-lg text-sm flex items-center">
                            ✕ Limpiar
                        </a>
                    </div>

                </div>
            </form>

            <!-- LEYENDA DE OPERADORES -->
            <div class="flex gap-4 text-xs text-gray-400 flex-wrap justify-center">
                <span><span class="text-blue-300 font-mono font-bold">AND</span> — películas que pertenecen a AMBOS géneros</span>
                <span><span class="text-green-300 font-mono font-bold">OR</span> — películas de CUALQUIERA de los dos géneros</span>
                <span><span class="text-red-300 font-mono font-bold">NOT</span> — películas que NO son de ese género</span>
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
                <div class="bg-gray-800 text-white rounded-xl shadow-lg overflow-hidden transition transform hover:-translate-y-2 hover:shadow-2xl"
                     data-id="<?= (int)$fila['id_pelicula'] ?>"
                     data-nombre="<?= htmlspecialchars($fila['nombre'], ENT_QUOTES) ?>"
                     data-sinopsis="<?= htmlspecialchars($fila['sinopsis'] ?? '', ENT_QUOTES) ?>"
                     data-youtube="<?= htmlspecialchars($fila['youtube_url'] ?? '', ENT_QUOTES) ?>">
                    <?php if ((int)($fila['id_plan'] ?? 1) === 2): ?>
                    <div class="bg-yellow-500 text-black text-xs font-bold text-center py-1 tracking-wide">
                        ⭐ PREMIUM
                    </div>
                    <?php endif; ?>
                    <img src="imagen.php?id=<?= (int)$fila['id_pelicula'] ?>"
                         alt="<?= htmlspecialchars($fila['nombre']) ?>"
                         class="h-72 w-full object-cover cursor-pointer"
                         onclick="abrirModalSinopsis(this.parentElement)">
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
                <p class="text-white col-span-full text-center">
                    <?= $busqueda !== '' ? 'No se encontraron películas con esa búsqueda.' : 'No hay películas en el catálogo.' ?>
                </p>
            <?php endif; ?>
        </div>

    </main>

        </div> <!-- END LEFT -->

        <!-- RIGHT: SIDEBAR TOP 10 -->
        <div class="w-80 bg-gray-800 rounded-xl p-4">
            <h3 class="text-white text-xl font-bold mb-4 text-center">🏆 Top 10 Películas</h3>
            <?php if (!empty($top10)): ?>
            <ol class="space-y-3">
                <?php foreach ($top10 as $index => $peli): ?>
                <li class="flex items-center gap-3 bg-gray-700 rounded-lg p-2">
                    <span class="text-yellow-400 font-bold text-lg w-8 text-center">
                        <?= $index + 1 ?>
                    </span>
                    <img src="imagen.php?id=<?= $peli['id_pelicula'] ?>" alt="<?= htmlspecialchars($peli['nombre']) ?>"
                         class="w-12 h-16 object-cover rounded">
                    <div class="flex-1">
                        <p class="text-white text-sm font-semibold line-clamp-2">
                            <?= htmlspecialchars($peli['nombre']) ?>
                        </p>
                        <p class="text-gray-400 text-xs">
                            ❤️ <?= $peli['likes'] ?> likes
                        </p>
                    </div>
                </li>
                <?php endforeach; ?>
            </ol>
            <?php else: ?>
            <p class="text-gray-400 text-center">No hay películas en el top aún.</p>
            <?php endif; ?>
        </div>

    </div> <!-- END MAIN CONTENT -->

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
            <textarea name="sinopsis" placeholder="Sinopsis de la película" rows="3"
                class="px-4 py-2 rounded-lg bg-gray-800 text-white border border-gray-600 focus:outline-none focus:border-red-500 resize-none"></textarea>

            <!-- CHECKBOXES DE GÉNEROS -->
            <div class="bg-gray-800 border border-gray-600 rounded-lg p-3">
                <p class="text-gray-300 text-sm mb-2">🎭 Géneros (selecciona uno o más):</p>
                <div class="grid grid-cols-2 gap-2">
                    <?php foreach ($resGenerosArr as $g): ?>
                    <label class="flex items-center gap-2 text-white text-sm cursor-pointer hover:text-red-400">
                        <input type="checkbox" name="generos[]" value="<?= (int)$g['id_genero'] ?>"
                            class="accent-red-500 w-4 h-4">
                        <?= htmlspecialchars($g['nombre']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- PLAN DE LA PELÍCULA -->
            <div class="bg-gray-800 border border-gray-600 rounded-lg p-3">
                <p class="text-gray-300 text-sm mb-2">🔒 Disponibilidad:</p>
                <div class="flex gap-4">
                    <label class="flex items-center gap-2 text-white text-sm cursor-pointer">
                        <input type="radio" name="id_plan_peli" value="1" checked class="accent-red-500">
                        📦 Básico
                    </label>
                    <label class="flex items-center gap-2 text-white text-sm cursor-pointer">
                        <input type="radio" name="id_plan_peli" value="2" class="accent-yellow-400">
                        ⭐ Premium
                    </label>
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

<!-- ── MODAL: SINOPSIS CON VIDEO DE FONDO ───────────────────────────────────── -->
<div id="modalSinopsis" class="hidden fixed inset-0 bg-black z-50">
    <div class="relative w-full h-full">
        <!-- Video de fondo -->
        <iframe id="sinopsisFrame" class="w-full h-full"
            frameborder="0" allowfullscreen allow="autoplay; encrypted-media; mute"></iframe>
        <!-- Overlay con sinopsis -->
        <div class="absolute inset-0 bg-black bg-opacity-50 flex items-center justify-center">
            <div class="bg-gray-900 bg-opacity-90 border border-gray-700 rounded-2xl p-6 w-full max-w-2xl mx-4 shadow-2xl">
                <div class="flex justify-between items-center mb-4">
                    <h2 id="sinopsisTitulo" class="text-white text-2xl font-bold"></h2>
                    <button onclick="cerrarModalSinopsis()" class="text-gray-400 hover:text-white text-2xl">✕</button>
                </div>
                <p id="sinopsisTexto" class="text-gray-300 text-lg leading-relaxed"></p>
            </div>
        </div>
    </div>
</div>

<!-- ── MODAL: CAMBIAR PERFIL ────────────────────────────────────────────────── -->
<div id="modalPerfiles" class="hidden fixed inset-0 bg-black bg-opacity-70 flex items-center justify-center z-50">
    <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6 w-full max-w-md mx-4 shadow-2xl">
        <h2 class="text-white text-2xl font-bold mb-4 text-center">👥 Seleccionar Perfil</h2>
        <?php if (isset($errorPerfil)): ?>
        <p class="text-red-400 text-sm mb-4"><?= htmlspecialchars($errorPerfil) ?></p>
        <?php endif; ?>
        <form method="POST" class="flex flex-col gap-4">
            <input type="hidden" name="accion" value="cambiar_perfil">
            <div>
                <label class="text-gray-400 text-sm mb-2 block">Elige un perfil:</label>
                <select name="id_usuario" required class="w-full px-4 py-2 rounded-lg bg-gray-800 text-white border border-gray-600 focus:outline-none focus:border-red-500">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($usuariosCuenta as $u): ?>
                    <option value="<?= $u['id_usuario'] ?>" <?= $u['id_usuario'] == $_SESSION['id_usuario'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u['nombre']) ?> (<?= htmlspecialchars($u['email']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-gray-400 text-sm mb-1 block">Contraseña del perfil</label>
                <input type="password" name="password" required placeholder="••••••••"
                    class="w-full px-4 py-2 rounded-lg bg-gray-800 text-white border border-gray-600 focus:outline-none focus:border-red-500">
            </div>
            <div class="flex gap-3 mt-2">
                <button type="submit" class="flex-1 bg-red-600 hover:bg-red-700 text-white py-2 rounded-lg font-semibold">
                    Cambiar
                </button>
                <button type="button" onclick="document.getElementById('modalPerfiles').classList.add('hidden')"
                    class="flex-1 bg-gray-600 hover:bg-gray-700 text-white py-2 rounded-lg">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<script src="assets/js/main.js"></script>
<?php if ($mostrarMenuPerfiles && !isset($_SESSION['perfil_seleccionado'])): ?>
<script>
document.getElementById('modalPerfiles').classList.remove('hidden');
<?php $_SESSION['perfil_seleccionado'] = true; ?>
</script>
<?php endif; ?>
<script>
// ── LÓGICA DEL BUSCADOR BOOLEANO ─────────────────────────────────────────────

function actualizarBuscador() {
    const g1  = document.getElementById('genero1').value;
    const op  = document.getElementById('operador').value;
    const g2  = document.getElementById('genero2');
    const exp = document.getElementById('explicacion');

    // Habilitar/deshabilitar segundo género
    const necesitaG2 = op === 'AND' || op === 'OR';
    g2.classList.toggle('opacity-40',          !necesitaG2);
    g2.classList.toggle('pointer-events-none', !necesitaG2);
    if (!necesitaG2) g2.value = '';

    // Explicación dinámica
    if (!g1) { exp.textContent = 'Selecciona un género para comenzar.'; return; }

    const g2val = g2.value;
    const colores = {
        'AND': 'color:#93c5fd',   // blue-300
        'OR':  'color:#86efac',   // green-300
        'NOT': 'color:#fca5a5',   // red-300
        'SOLO':'color:#fde68a',   // yellow-200
    };
    const c = colores[op] || 'color:#e5e7eb';

    let msg = '';
    if (op === 'SOLO' || !op) {
        msg = `Mostrando películas de: <span style="${c}; font-weight:600">${g1}</span>`;
    } else if (op === 'NOT') {
        msg = `Películas que <span style="${c}; font-weight:600">NO</span> son de: <span style="color:#fca5a5; font-weight:600">${g1}</span>`;
    } else if (necesitaG2 && g2val) {
        const conector = op === 'AND' ? 'que pertenecen a' : 'de';
        const condicion = op === 'AND'
            ? `<span style="${c}; font-weight:600">AMBOS</span> géneros`
            : `<span style="${c}; font-weight:600">CUALQUIERA</span> de los dos géneros`;
        msg = `Películas ${conector} ${condicion}: <span style="color:#fde68a">${g1}</span> <span style="${c}; font-weight:700">${op}</span> <span style="color:#fde68a">${g2val}</span>`;
    } else {
        msg = `Selecciona el segundo género para usar <span style="${c}; font-weight:700">${op}</span>`;
    }

    exp.innerHTML = msg;
}

// Inicializar al cargar (en caso de que vengan valores por GET)
document.addEventListener('DOMContentLoaded', actualizarBuscador);
</script>
</body>
</html>
<?php $conexion->close(); ?>