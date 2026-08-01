<?php
/**
 * Segunda fuente de logos: github.com/iptv-org/database, un proyecto
 * comunitario mucho más grande que tv-logo/tv-logos (~43k logos vs ~10.8k,
 * ver GithubLogos.php) pero con las imágenes alojadas en decenas de hosts
 * externos (imgur, CDNs de proveedores, Wikimedia, etc.) en vez de vivir
 * todas en el mismo repo.
 *
 * Se descargan y cruzan DOS CSVs una sola vez (botón "Actualizar índice",
 * mismo mecanismo que GithubLogos.php): data/channels.csv (id -> nombre y
 * país) y data/logos.csv (id -> url de imagen, formato, in_use). Solo se
 * guardan las filas con in_use=TRUE. El resultado combinado se cachea como
 * una lista plana de {name, country, url, format} — las búsquedas después
 * son 100% locales, sin volver a tocar GitHub.
 *
 * Validar las ~43k URLs externas en el servidor al indexar NO es viable: se
 * comprobó con una muestra real que Wikimedia (uno de los hosts más
 * comunes en logos.csv) devuelve 429 a la IP del VPS después de pocas
 * peticiones, aunque el logo en sí exista y cargue bien en un navegador
 * normal. En vez de validar server-side, cada tarjeta de resultado en el
 * navegador se oculta sola si la imagen falla al cargar (evento `error`
 * del <img>, ver index.html) — la validación real ocurre en
 * el navegador de cada usuario, no en este servidor.
 */

const IPTVORG_CHANNELS_CSV_URL = 'https://raw.githubusercontent.com/iptv-org/database/master/data/channels.csv';
const IPTVORG_LOGOS_CSV_URL = 'https://raw.githubusercontent.com/iptv-org/database/master/data/logos.csv';

// Mismo espíritu que GITHUB_LOGOS_STOPWORDS (GithubLogos.php) — duplicado a
// propósito en vez de compartido, para que este módulo sea independiente y
// se pueda quitar sin tocar el otro si algún día deja de servir.
const IPTVORG_LOGOS_STOPWORDS = ['hd', 'fhd', 'shd', 'uhd', 'sd', '4k', '8k', 'fullhd', 'full', 'hevc', 'h265', 'h264'];

function iptvorg_logos_cache_file(): string
{
    return __DIR__ . '/../uploads/cache/iptvorg_logos_index.json';
}

/** Descarga un CSV y lo devuelve como array de filas (cada fila ya parseada con str_getcsv). */
function iptvorg_fetch_csv_rows(string $url): array
{
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 30,
            'header' => "User-Agent: IPTV-Watch/1.0\r\n",
            'follow_location' => 1,
            'max_redirects' => 5,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || trim($raw) === '') {
        throw new RuntimeException('No se pudo descargar ' . $url);
    }
    $rows = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        if (trim($line) === '') {
            continue;
        }
        $rows[] = str_getcsv($line);
    }
    return $rows;
}

/**
 * Descarga channels.csv + logos.csv, los cruza por el id de canal y cachea
 * en disco solo lo que hace falta para buscar (name, country, url, format)
 * de las filas con in_use=TRUE. Lanza RuntimeException con un mensaje
 * entendible si algo falla.
 */
function iptvorg_logos_refresh_index(): array
{
    $channelRows = iptvorg_fetch_csv_rows(IPTVORG_CHANNELS_CSV_URL);
    $channelHeader = array_shift($channelRows);
    $idCol = array_search('id', $channelHeader, true);
    $nameCol = array_search('name', $channelHeader, true);
    $countryCol = array_search('country', $channelHeader, true);
    if ($idCol === false || $nameCol === false) {
        throw new RuntimeException('channels.csv no trae las columnas esperadas (¿cambió el formato?).');
    }

    $channelsById = [];
    foreach ($channelRows as $row) {
        $id = $row[$idCol] ?? '';
        if ($id === '') {
            continue;
        }
        $channelsById[$id] = [
            'name' => $row[$nameCol] ?? '',
            'country' => $countryCol !== false ? ($row[$countryCol] ?? '') : '',
        ];
    }

    $logoRows = iptvorg_fetch_csv_rows(IPTVORG_LOGOS_CSV_URL);
    $logoHeader = array_shift($logoRows);
    $channelCol = array_search('channel', $logoHeader, true);
    $inUseCol = array_search('in_use', $logoHeader, true);
    $formatCol = array_search('format', $logoHeader, true);
    $urlCol = array_search('url', $logoHeader, true);
    if ($channelCol === false || $urlCol === false) {
        throw new RuntimeException('logos.csv no trae las columnas esperadas (¿cambió el formato?).');
    }

    $entries = [];
    foreach ($logoRows as $row) {
        $channelId = $row[$channelCol] ?? '';
        $url = trim($row[$urlCol] ?? '');
        if ($channelId === '' || $url === '') {
            continue;
        }
        if ($inUseCol !== false && strtoupper((string)($row[$inUseCol] ?? '')) !== 'TRUE') {
            continue;
        }
        $channel = $channelsById[$channelId] ?? null;
        $entries[] = [
            'name' => $channel['name'] ?? $channelId,
            'country' => $channel['country'] ?? '',
            'url' => $url,
            'format' => $formatCol !== false ? ($row[$formatCol] ?? '') : '',
        ];
    }
    if (!$entries) {
        throw new RuntimeException('logos.csv no trajo ninguna entrada usable (¿cambió el formato?).');
    }

    $file = iptvorg_logos_cache_file();
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear el directorio de caché en el servidor.');
    }
    if (file_put_contents($file, json_encode(['updated_at' => time(), 'entries' => $entries])) === false) {
        throw new RuntimeException('No se pudo guardar el índice en el servidor.');
    }

    return ['count' => count($entries), 'updated_at' => time()];
}

function iptvorg_logos_index_status(): array
{
    $file = iptvorg_logos_cache_file();
    if (!file_exists($file)) {
        return ['count' => 0, 'updated_at' => null];
    }
    $json = json_decode((string)file_get_contents($file), true);
    return [
        'count' => count($json['entries'] ?? []),
        'updated_at' => $json['updated_at'] ?? null,
    ];
}

/**
 * Busca en el índice cacheado por coincidencia de palabras del query contra
 * el NOMBRE del canal (ver [[iptv_watch_project]] / GithubLogos.php para el
 * mismo criterio de orden: más palabras coincidentes primero). A diferencia
 * de tv-logo/tv-logos, aquí se busca contra un nombre legible ("ESPN",
 * "HBO Plus") en vez de un nombre de archivo con sufijo de país, así que no
 * hace falta el recorte especial para queries cortas que sí necesita
 * GithubLogos::github_logos_search.
 */
function iptvorg_logos_search(string $query, int $limit = 40): array
{
    $file = iptvorg_logos_cache_file();
    if (!file_exists($file)) {
        throw new RuntimeException('El índice de iptv-org/database todavía no se descargó. Pulsa "Actualizar índice" primero.');
    }
    $json = json_decode((string)file_get_contents($file), true);
    $entries = $json['entries'] ?? [];

    $normalize = static function (string $s): string {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
    };

    $words = array_values(array_filter(explode(' ', $normalize($query))));
    $meaningful = array_values(array_diff($words, IPTVORG_LOGOS_STOPWORDS));
    if ($meaningful) {
        $words = $meaningful;
    }
    if (!$words) {
        return [];
    }

    $scored = [];
    foreach ($entries as $entry) {
        $haystack = $normalize((string)($entry['name'] ?? ''));
        $matches = 0;
        foreach ($words as $w) {
            if (strpos($haystack, $w) !== false) {
                $matches++;
            }
        }
        if ($matches === 0) {
            continue;
        }
        $scored[] = $entry + ['matches' => $matches, 'len' => strlen($haystack)];
    }
    usort($scored, function ($a, $b) {
        return $b['matches'] <=> $a['matches'] ?: $a['len'] <=> $b['len'];
    });

    $results = [];
    foreach (array_slice($scored, 0, $limit) as $s) {
        $country = trim((string)($s['country'] ?? ''));
        $results[] = [
            'name' => $s['name'],
            'label' => $country !== '' ? strtoupper($country) : 'Misc',
            'raw_url' => $s['url'],
            'match_words' => $s['matches'],
            'match_total' => count($words),
            'source' => 'iptv-org',
        ];
    }
    return $results;
}
