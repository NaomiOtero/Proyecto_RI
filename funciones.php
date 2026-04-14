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

function obtenerSugerencia(mysqli $conexion, string $busqueda, int $idPlanUsuario = 2): array {
    $busquedaNorm    = normalizar($busqueda);
    // Solo sugiere películas visibles para el plan del usuario
    $resPeliculas    = $conexion->query(
        "SELECT id_pelicula, nombre FROM peliculas WHERE id_plan <= $idPlanUsuario"
    );
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

function tokenizarBooleano(string $texto): array {
    $texto  = preg_replace('/\s+(AND|OR|NOT)\s+/i', ' $1 ', ' ' . trim($texto) . ' ');
    $texto  = str_replace(['(', ')'], [' ( ', ' ) '], $texto);
    $partes = preg_split('/\s+/', trim($texto));
    return array_values(array_filter($partes, fn($p) => $p !== ''));
}

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

    if (preg_match('/^\d{4}$/', $raw)) {
        return "p.anio = $termino";
    }

    return "(
        p.nombre LIKE '%$termino%' OR p.autor LIKE '%$termino%'
        OR p.nombre LIKE '%$norm%'  OR p.autor LIKE '%$norm%'
        OR GROUP_CONCAT(g.nombre SEPARATOR ',') LIKE '%$termino%'
        OR GROUP_CONCAT(g.nombre SEPARATOR ',') LIKE '%$norm%'
    )";
}

function parsearConsultaBooleana(string $busqueda, mysqli $conexion): string|false {
    $busqueda = trim($busqueda);
    if ($busqueda === '') return '';

    $tokens = tokenizarBooleano($busqueda);
    $having = construirHaving($tokens, $conexion);

    return ($having !== '') ? $having : false;
}

// ── BÚSQUEDA PRINCIPAL (con filtro de plan) ───────────────────────────────────

function buscarPeliculas(mysqli $conexion, string $busqueda, int $idPlanUsuario = 2): array {
    $errorBooleano = '';

    // Filtrar por plan: usuario solo ve películas de su plan o inferior
    $filtroPlan = "p.id_plan <= $idPlanUsuario";

    $sqlBase = "SELECT p.id_pelicula, p.nombre, p.autor, p.anio, p.youtube_url, p.sinopsis, p.id_plan,
                       GROUP_CONCAT(g.nombre ORDER BY g.nombre SEPARATOR ', ') AS genero
                FROM peliculas p
                LEFT JOIN pelicula_generos pg ON p.id_pelicula = pg.id_pelicula
                LEFT JOIN generos g           ON pg.id_genero  = g.id_genero
                WHERE $filtroPlan";

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

function agregarPelicula(mysqli $conexion, array $post, array $files): int {
    $nombre   = $post['nombre']              ?? '';
    $autor    = $post['autor']               ?? '';
    $anio     = (int)($post['anio']          ?? 0);
    $youtube  = $post['youtube_url']         ?? '';
    $sinopsis = $post['sinopsis']            ?? '';
    $generos  = $post['generos']             ?? [];
    $id_plan  = (int)($post['id_plan_peli']  ?? 1);

    $imagenBlob = null;
    $imagenTipo = 'image/jpeg';

    if (isset($files['imagen']) && $files['imagen']['error'] === UPLOAD_ERR_OK) {
        $imagenBlob = file_get_contents($files['imagen']['tmp_name']);
        $imagenTipo = $files['imagen']['type'];
    }

    $stmt = $conexion->prepare(
        "INSERT INTO peliculas (nombre, autor, anio, youtube_url, sinopsis, imagen, imagen_tipo, id_plan)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $null = null;
    $stmt->bind_param("ssissssi", $nombre, $autor, $anio, $youtube, $sinopsis, $null, $imagenTipo, $id_plan);

    if ($imagenBlob !== null) {
        $chunkSize = 65536;
        $offset    = 0;
        $len       = strlen($imagenBlob);
        while ($offset < $len) {
            $stmt->send_long_data(5, substr($imagenBlob, $offset, $chunkSize));
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

function registrarTiempo(mysqli $conexion, int $idPelicula, int $segundos, int $idPlanUsuario = 2): array {
    $gusto = $segundos >= 15 ? 1 : 0;

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
            // Recomendar solo películas accesibles según el plan del usuario
            $recRes = $conexion->query(
                "SELECT DISTINCT p.id_pelicula, p.nombre, p.youtube_url
                 FROM peliculas p
                 JOIN pelicula_generos pg ON p.id_pelicula = pg.id_pelicula
                 WHERE pg.id_genero IN ($idsStr)
                   AND p.id_pelicula != $idPelicula
                   AND p.id_plan <= $idPlanUsuario
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