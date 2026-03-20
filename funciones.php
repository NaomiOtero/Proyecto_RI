<?php

// ── NORMALIZACIÓN ─────────────────────────────────────────────────────────────

function normalizar(string $texto): string {
    $texto = strtolower($texto);
    $texto = str_replace(
        ['á','é','í','ó','ú','ñ'],
        ['a','e','i','o','u','n'],
        $texto
    );
    $texto = preg_replace('/\b(el|la|los|las)\b/', '', $texto);
    return trim($texto);
}

// ── SUGERENCIAS (LEVENSHTEIN) ─────────────────────────────────────────────────

function obtenerSugerencia(mysqli $conexion, string $busqueda): array {
    $busquedaNorm    = normalizar($busqueda);
    $resPeliculas    = $conexion->query("SELECT id_pelicula, nombre FROM peliculas");
    $distanciaMinima = 999;
    $sugerencia      = '';
    $sugerenciaId    = '';

    while ($fila = $resPeliculas->fetch_assoc()) {
        $d = levenshtein($busquedaNorm, normalizar($fila['nombre']));
        if ($d < $distanciaMinima) {
            $distanciaMinima = $d;
            $sugerencia      = $fila['nombre'];
            $sugerenciaId    = $fila['id_pelicula'];
        }
    }

    if ($distanciaMinima <= 3) {
        return [
            'sugerencia'     => $sugerencia,
            'sugerenciaId'   => $sugerenciaId,
            'imagenSugerida' => 'imagen.php?id=' . $sugerenciaId,
            'tipoError'      => 'Error de sintaxis',
        ];
    }

    return [
        'sugerencia'     => '',
        'sugerenciaId'   => '',
        'imagenSugerida' => '',
        'tipoError'      => 'Error semántico',
    ];
}

// ── MODELO BOOLEANO ───────────────────────────────────────────────────────────

/**
 * Divide la consulta en tokens respetando AND / OR / NOT y paréntesis.
 */
function tokenizarBooleano(string $texto): array {
    $texto  = preg_replace('/\s+(AND|OR|NOT)\s+/i', ' $1 ', ' ' . trim($texto) . ' ');
    $texto  = str_replace(['(', ')'], [' ( ', ' ) '], $texto);
    $partes = preg_split('/\s+/', trim($texto));
    return array_values(array_filter($partes, fn($p) => $p !== ''));
}

// Parsers recursivos: OR < AND < NOT < primario
function construirHaving(array &$tokens, mysqli $conexion): string {
    return parseOrHaving($tokens, $conexion);
}

function parseOrHaving(array &$tokens, mysqli $conexion): string {
    $left = parseAndHaving($tokens, $conexion);
    while (!empty($tokens) && strtoupper($tokens[0]) === 'OR') {
        array_shift($tokens);
        $right = parseAndHaving($tokens, $conexion);
        $left  = "($left OR $right)";
    }
    return $left;
}

function parseAndHaving(array &$tokens, mysqli $conexion): string {
    $left = parseNotHaving($tokens, $conexion);
    while (!empty($tokens) && strtoupper($tokens[0]) === 'AND') {
        array_shift($tokens);
        $right = parseNotHaving($tokens, $conexion);
        $left  = "($left AND $right)";
    }
    return $left;
}

function parseNotHaving(array &$tokens, mysqli $conexion): string {
    if (!empty($tokens) && strtoupper($tokens[0]) === 'NOT') {
        array_shift($tokens);
        $expr = parsePrimarioHaving($tokens, $conexion);
        return "(NOT ($expr))";
    }
    return parsePrimarioHaving($tokens, $conexion);
}

function parsePrimarioHaving(array &$tokens, mysqli $conexion): string {
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

    // Término numérico de 4 dígitos → buscar por año
    if (preg_match('/^\d{4}$/', $raw)) {
        return "p.anio = $termino";
    }

    // Buscar en nombre, autor y géneros concatenados
    return "(
        p.nombre LIKE '%$termino%' OR p.autor LIKE '%$termino%'
        OR p.nombre LIKE '%$norm%'  OR p.autor LIKE '%$norm%'
        OR GROUP_CONCAT(g.nombre SEPARATOR ',') LIKE '%$termino%'
        OR GROUP_CONCAT(g.nombre SEPARATOR ',') LIKE '%$norm%'
    )";
}

/**
 * Convierte una consulta booleana en cláusula HAVING.
 * Devuelve string vacío si no hay búsqueda, false si la expresión es inválida.
 */
function parsearConsultaBooleana(string $busqueda, mysqli $conexion): string|false {
    $busqueda = trim($busqueda);
    if ($busqueda === '') return '';

    $tokens = tokenizarBooleano($busqueda);
    $having = construirHaving($tokens, $conexion);

    return ($having !== '') ? $having : false;
}

// ── BÚSQUEDA PRINCIPAL ────────────────────────────────────────────────────────

function buscarPeliculas(mysqli $conexion, string $busqueda): array {
    $errorBooleano = '';

    $sqlBase = "SELECT p.id_pelicula, p.nombre, p.autor, p.anio, p.youtube_url,
                       GROUP_CONCAT(g.nombre ORDER BY g.nombre SEPARATOR ', ') AS genero
                FROM peliculas p
                LEFT JOIN pelicula_generos pg ON p.id_pelicula = pg.id_pelicula
                LEFT JOIN generos g           ON pg.id_genero  = g.id_genero";

    if ($busqueda !== '') {
        $having = parsearConsultaBooleana($busqueda, $conexion);
        if ($having === false) {
            $errorBooleano = 'Expresión booleana inválida.';
            $sql = $sqlBase . " GROUP BY p.id_pelicula HAVING 1=0";
        } else {
            $sql = $sqlBase . " GROUP BY p.id_pelicula HAVING " . $having;
        }
    } else {
        $sql = $sqlBase . " GROUP BY p.id_pelicula";
    }

    return [
        'resultado'     => $conexion->query($sql),
        'errorBooleano' => $errorBooleano,
    ];
}

// ── AGREGAR PELÍCULA ──────────────────────────────────────────────────────────

/**
 * Inserta una nueva película con imagen BLOB y géneros.
 * Devuelve el id insertado.
 */
function agregarPelicula(mysqli $conexion, array $post, array $files): int {
    $nombre  = $post['nombre']      ?? '';
    $autor   = $post['autor']       ?? '';
    $anio    = (int)($post['anio']  ?? 0);
    $youtube = $post['youtube_url'] ?? '';
    $generos = $post['generos']     ?? [];

    $imagenBlob = null;
    $imagenTipo = 'image/jpeg';

    if (isset($files['imagen']) && $files['imagen']['error'] === UPLOAD_ERR_OK) {
        $imagenBlob = file_get_contents($files['imagen']['tmp_name']);
        $imagenTipo = $files['imagen']['type'];
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

    return (int)$nuevaId;
}

// ── REGISTRAR TIEMPO / RECOMENDACIONES ───────────────────────────────────────

/**
 * Guarda el tiempo de visualización.
 * Devuelve ['gusto' => int, 'recomendaciones' => array]
 */
function registrarTiempo(mysqli $conexion, int $idPelicula, int $segundos): array {
    $gusto = $segundos >= 15 ? 1 : 0;

    $conexion->query("CREATE TABLE IF NOT EXISTS visualizaciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_pelicula INT NOT NULL,
        segundos INT NOT NULL,
        gusto TINYINT(1) NOT NULL,
        fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $stmt = $conexion->prepare(
        "INSERT INTO visualizaciones (id_pelicula, segundos, gusto) VALUES (?, ?, ?)"
    );
    $stmt->bind_param("iii", $idPelicula, $segundos, $gusto);
    $stmt->execute();
    $stmt->close();

    $recomendaciones = [];

    if ($gusto) {
        $resGeneros = $conexion->query(
            "SELECT id_genero FROM pelicula_generos WHERE id_pelicula = $idPelicula"
        );
        $generosIds = [];
        while ($g = $resGeneros->fetch_assoc()) {
            $generosIds[] = (int)$g['id_genero'];
        }

        if (!empty($generosIds)) {
            $idsStr = implode(',', $generosIds);
            $recRes = $conexion->query(
                "SELECT DISTINCT p.id_pelicula, p.nombre, p.youtube_url
                 FROM peliculas p
                 JOIN pelicula_generos pg ON p.id_pelicula = pg.id_pelicula
                 WHERE pg.id_genero IN ($idsStr)
                   AND p.id_pelicula != $idPelicula
                 LIMIT 4"
            );
            while ($r = $recRes->fetch_assoc()) {
                $recomendaciones[] = [
                    'id'          => $r['id_pelicula'],
                    'nombre'      => $r['nombre'],
                    'img'         => 'imagen.php?id=' . $r['id_pelicula'],
                    'youtube_url' => $r['youtube_url'] ?? '',
                ];
            }
        }
    }

    return ['gusto' => $gusto, 'recomendaciones' => $recomendaciones];
}