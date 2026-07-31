<?php
/**
 * Búsqueda de logos de canales contra el repo público de GitHub
 * github.com/tv-logo/tv-logos (OJO: organización "tv-logo", singular —
 * "tv-logos/tv-logos" no existe, se comprobó con la API antes de escribir
 * esto). ~10.8k PNG/SVG organizados por país en countries/<pais>/<slug>-
 * <código-país>.png, más algunos sueltos bajo misc/. Rama por defecto: main
 * (confirmado con la API, no asumido).
 *
 * No hace falta token: se lee el árbol completo del repo UNA sola vez (Git
 * Trees API con recursive=1, ~2.7MB de JSON con metadata que no usamos) y
 * se cachea en disco como una lista plana de paths (~500KB) — las búsquedas
 * posteriores son 100% locales, sin volver a golpear la API de GitHub ni su
 * límite de 60 req/hora sin autenticar. El caché se actualiza a mano desde
 * el modal (botón "Actualizar índice"), no automáticamente.
 *
 * Las URLs de imagen final apuntan a raw.githubusercontent.com — se sirven
 * directo desde ahí (no se descargan ni se guardan copias locales), así que
 * el <img src> del canal depende de que GitHub siga disponible.
 */

const GITHUB_LOGOS_OWNER = 'tv-logo';
const GITHUB_LOGOS_REPO = 'tv-logos';
const GITHUB_LOGOS_BRANCH = 'main';
const GITHUB_LOGOS_RAW_PREFIX = 'https://raw.githubusercontent.com/' . GITHUB_LOGOS_OWNER . '/' . GITHUB_LOGOS_REPO . '/' . GITHUB_LOGOS_BRANCH . '/';

function github_logos_cache_file(): string
{
    return __DIR__ . '/../uploads/cache/tv_logos_index.json';
}

/**
 * Descarga el árbol completo del repo y lo cachea como lista de paths de
 * imagen. Lanza RuntimeException con un mensaje entendible si algo falla
 * (rate limit, repo caído, respuesta truncada).
 */
function github_logos_refresh_index(): array
{
    $url = 'https://api.github.com/repos/' . GITHUB_LOGOS_OWNER . '/' . GITHUB_LOGOS_REPO . '/git/trees/' . GITHUB_LOGOS_BRANCH . '?recursive=1';
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 30,
            // La API de GitHub exige un User-Agent; sin esto responde 403.
            'header' => "User-Agent: IPTV-Watch/1.0\r\nAccept: application/vnd.github+json\r\n",
            'follow_location' => 1,
            'max_redirects' => 5,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || trim($raw) === '') {
        throw new RuntimeException('No se pudo contactar la API de GitHub.');
    }
    $json = json_decode($raw, true);
    if (!is_array($json) || empty($json['tree'])) {
        $msg = is_array($json) && !empty($json['message']) ? $json['message'] : 'respuesta inesperada';
        throw new RuntimeException('La API de GitHub no devolvió el árbol del repo (' . $msg . ').');
    }
    if (!empty($json['truncated'])) {
        throw new RuntimeException('El árbol del repo vino truncado (demasiado grande para una sola llamada).');
    }

    $paths = [];
    foreach ($json['tree'] as $entry) {
        if (($entry['type'] ?? '') === 'blob' && preg_match('/\.(png|svg)$/i', $entry['path'] ?? '')) {
            $paths[] = $entry['path'];
        }
    }
    sort($paths);
    if (!$paths) {
        throw new RuntimeException('El árbol del repo no trajo ninguna imagen — algo cambió en su estructura.');
    }

    $file = github_logos_cache_file();
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear el directorio de caché en el servidor.');
    }
    if (file_put_contents($file, json_encode(['updated_at' => time(), 'paths' => $paths])) === false) {
        throw new RuntimeException('No se pudo guardar el índice en el servidor.');
    }

    return ['count' => count($paths), 'updated_at' => time()];
}

function github_logos_index_status(): array
{
    $file = github_logos_cache_file();
    if (!file_exists($file)) {
        return ['count' => 0, 'updated_at' => null];
    }
    $json = json_decode((string)file_get_contents($file), true);
    return [
        'count' => count($json['paths'] ?? []),
        'updated_at' => $json['updated_at'] ?? null,
    ];
}

/** "countries/united-states/espn-us.png" -> "United States"; "misc/..." -> "Misc". */
function github_logos_label_from_path(string $path): string
{
    if (preg_match('#^countries/([^/]+)/#', $path, $m)) {
        return ucwords(str_replace('-', ' ', $m[1]));
    }
    if (strpos($path, 'misc/') === 0) {
        return 'Misc';
    }
    return '';
}

// Palabras de calidad/técnicas del stream, no del canal — el logo es el
// mismo sin importar esto, y el repo casi nunca las incluye en el nombre
// del archivo (ej. "hbo-plus-ar.png" no tiene variante "-hd-"). Exigirlas
// hacía fallar la búsqueda de canales como "HBO PLUS HD" pese a que el
// logo sí existe (comprobado: el índice real no tiene ningún archivo con
// "hd" para HBO Plus, en ningún país).
const GITHUB_LOGOS_STOPWORDS = ['hd', 'fhd', 'shd', 'uhd', 'sd', '4k', '8k', 'fullhd', 'full', 'hevc', 'h265', 'h264'];

/**
 * Busca en el índice cacheado por coincidencia de palabras del query
 * (normalizado: minúsculas, sin tildes, separadores colapsados) contra el
 * nombre de archivo. No exige que calcen TODAS — se ordena por cuántas
 * palabras coincidieron (más coincidencias primero, nombre de archivo más
 * corto como desempate) y se deja que el usuario revise y elija a mano en
 * vez de forzar una coincidencia perfecta que puede no existir.
 */
function github_logos_search(string $query, int $limit = 40): array
{
    $file = github_logos_cache_file();
    if (!file_exists($file)) {
        throw new RuntimeException('El índice de logos todavía no se descargó. Pulsa "Actualizar índice" primero.');
    }
    $json = json_decode((string)file_get_contents($file), true);
    $paths = $json['paths'] ?? [];

    $normalize = static function (string $s): string {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
    };

    $words = array_values(array_filter(explode(' ', $normalize($query))));
    // Si tras quitar las palabras de calidad no queda nada (ej. el query
    // completo era "HD"), se usan las originales igual — mejor buscar algo
    // que no buscar nada.
    $meaningful = array_values(array_diff($words, GITHUB_LOGOS_STOPWORDS));
    if ($meaningful) {
        $words = $meaningful;
    }
    if (!$words) {
        return [];
    }

    $scored = [];
    foreach ($paths as $path) {
        $base = basename($path);
        $haystack = $normalize($base);
        // El nombre de archivo casi siempre termina en "-<código-país>" (ver
        // encabezado del archivo, ej. "hi-id.png" para Indonesia) — ese
        // sufijo de 2-3 letras coincide por casualidad con abreviaturas de
        // canal reales (ej. buscar "ID" de Investigation Discovery devolvía
        // TODOS los logos de Indonesia). Para palabras cortas del query
        // (<=2 letras, el largo típico de un código ISO de país) solo se
        // matchea contra el "core" del nombre, sin ese sufijo final.
        $core = $normalize(preg_replace('/-[a-zA-Z0-9]+$/', '', pathinfo($base, PATHINFO_FILENAME)));
        $matches = 0;
        foreach ($words as $w) {
            $target = strlen($w) <= 2 ? $core : $haystack;
            if (strpos($target, $w) !== false) {
                $matches++;
            }
        }
        if ($matches === 0) {
            continue;
        }
        $scored[] = ['path' => $path, 'matches' => $matches, 'len' => strlen($haystack)];
    }
    usort($scored, function ($a, $b) {
        return $b['matches'] <=> $a['matches'] ?: $a['len'] <=> $b['len'];
    });

    $results = [];
    foreach (array_slice($scored, 0, $limit) as $s) {
        $path = $s['path'];
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));
        $results[] = [
            'path' => $path,
            'name' => basename($path),
            'label' => github_logos_label_from_path($path),
            'raw_url' => GITHUB_LOGOS_RAW_PREFIX . $encodedPath,
            'match_words' => $s['matches'],
            'match_total' => count($words),
        ];
    }
    return $results;
}

/**
 * Íconos genéricos de relleno masivo (ver api/logo_search.php,
 * action=bulk_fill_generic) para canales sin logo Y sin match en el índice
 * de tv-logo/tv-logos. Generados una sola vez con GD (no en tiempo de
 * ejecución) y guardados como assets estáticos en assets/generic-logos/ —
 * fondo de color sólido + 2-3 letras, uno por categoría amplia + un
 * "TV" por defecto. Coincidencia por palabra clave contra el NOMBRE DE LA
 * CATEGORÍA local (no el del canal) — mismo espíritu que
 * includes/AutoCategorizer.php, pero clasificando en un puñado de baldes
 * de ícono en vez de adivinar el nombre de categoría en sí.
 */
const GITHUB_LOGOS_GENERIC_BUCKETS = ['sports', 'movies', 'series', 'documentaries', 'kids', 'news', 'music', 'adults', 'default'];

// Ojo con singular/plural en español: "deporte" con \b...\b NO matchea
// "deportes" (la "s" final rompe el límite de palabra) — se comprobó con
// las categorías reales del proyecto (Deportes, Documentales, Películas,
// "Series y Novelas" caían las 4 en "default" hasta agregar el plural).
const GITHUB_LOGOS_GENERIC_RULES = [
    'sports' => ['deporte', 'deportes', 'sport', 'futbol', 'football', 'liga', 'nba', 'nfl', 'mlb', 'nhl', 'boxeo', 'ufc'],
    'movies' => ['pelicula', 'peliculas', 'movie', 'cine', 'film'],
    'series' => ['serie', 'series', 'novela', 'novelas', 'drama'],
    'documentaries' => ['documental', 'documentales', 'documentary'],
    'kids' => ['infantil', 'kids', 'nino', 'ninos', 'cartoon', 'animacion'],
    'news' => ['noticia', 'noticias', 'news', 'informativ'],
    'music' => ['musica', 'music'],
    'adults' => ['adulto', 'adultos', 'adult', 'xxx', '18'],
];

function github_logos_generic_bucket_for_category(?string $categoryName): string
{
    $name = trim((string)$categoryName);
    if ($name === '') {
        return 'default';
    }
    $normalized = mb_strtolower($name, 'UTF-8');
    $normalized = strtr($normalized, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
    foreach (GITHUB_LOGOS_GENERIC_RULES as $bucket => $keywords) {
        foreach ($keywords as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/u', $normalized) === 1) {
                return $bucket;
            }
        }
    }
    return 'default';
}

/** URL pública absoluta del ícono genérico de un balde, para poder mandarla también a XUI ONE (stream_icon). */
function github_logos_generic_url(string $bucket, string $appBaseUrl): string
{
    if (!in_array($bucket, GITHUB_LOGOS_GENERIC_BUCKETS, true)) {
        $bucket = 'default';
    }
    return rtrim($appBaseUrl, '/') . '/assets/generic-logos/' . $bucket . '.png';
}
