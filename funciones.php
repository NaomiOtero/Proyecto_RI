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
    $duracion = (int)($post['duracion']      ?? 0);
    $generos  = $post['generos']             ?? [];
    $id_plan  = (int)($post['id_plan_peli']  ?? 1);

    $imagenBlob = null;
    $imagenTipo = 'image/jpeg';

    if (isset($files['imagen']) && $files['imagen']['error'] === UPLOAD_ERR_OK) {
        $imagenBlob = file_get_contents($files['imagen']['tmp_name']);
        $imagenTipo = $files['imagen']['type'];
    }

    $stmt = $conexion->prepare(
        "INSERT INTO peliculas (nombre, autor, anio, youtube_url, sinopsis, duracion, imagen, imagen_tipo, id_plan)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $null = null;
    $stmt->bind_param("ssississi", $nombre, $autor, $anio, $youtube, $sinopsis, $duracion, $null, $imagenTipo, $id_plan);

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

function registrarTiempo(mysqli $conexion, int $idPelicula, int $segundos, int $idUsuario, int $idPlanUsuario = 2): array {
    $gusto = $segundos >= 15 ? 1 : 0;

    $stmt = $conexion->prepare(
        "INSERT INTO visualizaciones (id_pelicula, id_usuario, segundos, gusto) VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param("iiii", $idPelicula, $idUsuario, $segundos, $gusto);
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

function obtenerHistorialCuenta(mysqli $conexion, int $idCuenta): array {
    $stmt = $conexion->prepare(
        "SELECT u.nombre AS usuario, u.email, p.nombre AS pelicula, p.autor, v.segundos, v.gusto, COALESCE(v.fecha, '') AS fecha
         FROM visualizaciones v
         JOIN usuarios u   ON v.id_usuario = u.id_usuario
         JOIN peliculas p ON v.id_pelicula = p.id_pelicula
         WHERE u.id_cuenta = ?
         ORDER BY v.fecha DESC"
    );
    $stmt->bind_param('i', $idCuenta);
    $stmt->execute();
    $result = $stmt->get_result();

    $historial = [];
    while ($fila = $result->fetch_assoc()) {
        $historial[] = $fila;
    }
    $stmt->close();
    return $historial;
}

function exportarHistorialXML(mysqli $conexion, int $idCuenta): void {
    $filas = obtenerHistorialCuenta($conexion, $idCuenta);
    $doc  = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;

    $root = $doc->createElement('historial');
    $doc->appendChild($root);

    foreach ($filas as $fila) {
        $registro = $doc->createElement('registro');
        foreach (['usuario', 'email', 'pelicula', 'autor', 'segundos', 'gusto', 'fecha'] as $campo) {
            $elemento = $doc->createElement($campo, htmlspecialchars($fila[$campo], ENT_XML1 | ENT_COMPAT, 'UTF-8'));
            $registro->appendChild($elemento);
        }
        $root->appendChild($registro);
    }

    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="historial.xml"');
    echo $doc->saveXML();
    exit;
}

function exportarHistorialPDF(mysqli $conexion, int $idCuenta): void {
    require_once 'libs/fpdf.php';

    $filas = obtenerHistorialCuenta($conexion, $idCuenta);

    class PDF extends FPDF {
        function Row($data, $w) {
            $nb = 0;
            for($i=0;$i<count($data);$i++)
                $nb = max($nb, $this->NbLines($w[$i], $data[$i]));
            $h = 5*$nb;
            if($this->GetY()+$h > $this->PageBreakTrigger)
                $this->AddPage($this->CurOrientation);
            for($i=0;$i<count($data);$i++) {
                $x = $this->GetX();
                $y = $this->GetY();
                $this->Rect($x, $y, $w[$i], $h);
                $this->MultiCell($w[$i],5,$data[$i],0,'L');
                $this->SetXY($x+$w[$i], $y);
            }
            $this->Ln($h);
        }

        function NbLines($w, $txt) {
            $cw = &$this->CurrentFont['cw'];
            if($w==0)
                $w = $this->w - $this->rMargin - $this->x;
            $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
            $s = str_replace("\r",'',$txt);
            $nb = strlen($s);
            if($nb>0 && $s[$nb-1]=="\n")
                $nb--;
            $sep = -1;
            $i = 0;
            $j = 0;
            $l = 0;
            $nl = 1;
            while($i<$nb) {
                $c = $s[$i];
                if($c=="\n") {
                    $i++;
                    $sep = -1;
                    $j = $i;
                    $l = 0;
                    $nl++;
                    continue;
                }
                if($c==' ')
                    $sep = $i;
                $l += isset($cw[$c]) ? $cw[$c] : 0;
                if($l>$wmax) {
                    if($sep==-1) {
                        if($i==$j)
                            $i++;
                    } else
                        $i = $sep+1;
                    $sep = -1;
                    $j = $i;
                    $l = 0;
                    $nl++;
                } else
                    $i++;
            }
            return $nl;
        }
    }

    $pdf = new PDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial','B',16);
    $titulo = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Historial de visualizaciones') ?: 'Historial de visualizaciones';
    $pdf->Cell(0,10,$titulo,0,1,'C');
    $pdf->SetFont('Arial','',10);
    $fecha = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Fecha de generación: ' . date('d/m/Y')) ?: 'Fecha de generación: ' . date('d/m/Y');
    $pdf->Cell(0,10,$fecha,0,1,'R');
    $pdf->Ln(5);

    $w = array(40, 80, 25, 15, 35);
    $header = array_map(function($s) { return @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s; }, array('Usuario', 'Película', 'Segundos', 'Gusto', 'Fecha'));
    $pdf->Row($header, $w);

    if (empty($filas)) {
        $empty = array_map(function($s) { return @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s; }, array('', 'No hay registros en el historial.', '', '', ''));
        $pdf->Row($empty, $w);
    } else {
        foreach($filas as $row) {
            $data = array_map(function($s) { return @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s; }, array(
                $row['usuario'],
                $row['pelicula'],
                (string)$row['segundos'],
                $row['gusto'] ? 'Sí' : 'No',
                $row['fecha']
            ));
            $pdf->Row($data, $w);
        }
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="historial.pdf"');
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');
    echo $pdf->Output('S');
    exit;
}


