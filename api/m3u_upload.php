<?php
/**
 * Módulo de subida manual de listas M3U con auto-categorización por título.
 * Pensado para listas cuyo group-title viene vacío/inútil (ej. "-").
 *
 * POST {action:'preview', content}
 *   -> parsea el M3U pegado/leído en el navegador y devuelve cada canal con
 *      una categoría sugerida (la propia del archivo si es válida, o una
 *      adivinada por AutoCategorizer si venía vacía/placeholder). No toca
 *      la base de datos — el usuario ajusta categorías en el navegador
 *      antes de confirmar (hoy no existe forma de recategorizar un canal
 *      después de importado, ver api/channels.php).
 *
 * POST {action:'confirm', provider_name, check_interval_minutes?, channels}
 *   -> reconstruye un M3U a partir de la lista (ya con las categorías
 *      confirmadas/editadas), lo guarda en disco fuera del alcance HTTP
 *      directo, crea un proveedor nuevo apuntando a ese archivo local y
 *      reusa Sync::syncProvider() para la importación real — así se
 *      comparte toda la lógica de identidad/diff/categorías ya probada en
 *      vez de duplicarla aquí.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/M3uParser.php';
require_once __DIR__ . '/../includes/AutoCategorizer.php';
require_once __DIR__ . '/../includes/Sync.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('Método no soportado', 405);
}

$pdo = get_pdo();
$userId = (int)$_SESSION['user_id'];
$body = json_input();
$action = $body['action'] ?? '';

const GROUP_PLACEHOLDERS = ['', '-', 'sin categoria', 'sin categoría', 'sin clasificar', 'undefined', 'null', 'n/a'];

if ($action === 'preview') {
    $content = (string)($body['content'] ?? '');
    if (trim($content) === '') {
        respond_error('El archivo está vacío o no se pudo leer.');
    }

    $parsed = M3uParser::parse($content);
    if (empty($parsed)) {
        respond_error('No se encontraron canales en el archivo (¿es un M3U válido?).');
    }

    $channels = [];
    foreach ($parsed as $entry) {
        $url = trim($entry['url'] ?? '');
        if ($url === '') {
            continue;
        }
        $rawGroup = trim((string)($entry['group'] ?? ''));
        $isPlaceholder = in_array(mb_strtolower($rawGroup, 'UTF-8'), GROUP_PLACEHOLDERS, true);
        $category = $isPlaceholder
            ? (AutoCategorizer::guess($entry['name']) ?? 'Otros')
            : $rawGroup;

        $channels[] = [
            'name' => $entry['name'],
            'tvg_id' => $entry['tvg_id'],
            'logo' => $entry['logo'],
            'url' => $url,
            'category' => $category,
            'auto_detected' => $isPlaceholder,
        ];
    }

    respond(['ok' => true, 'count' => count($channels), 'channels' => $channels]);
}

if ($action === 'confirm') {
    $providerName = trim((string)($body['provider_name'] ?? ''));
    $interval = (int)($body['check_interval_minutes'] ?? 1440);
    $channels = $body['channels'] ?? [];

    if ($providerName === '') {
        respond_error('Ponle un nombre al proveedor.');
    }
    if (!is_array($channels) || count($channels) === 0) {
        respond_error('No hay canales para importar.');
    }
    if ($interval < 5) {
        $interval = 5;
    }

    $lines = ['#EXTM3U'];
    foreach ($channels as $ch) {
        $name = trim((string)($ch['name'] ?? ''));
        $url = trim((string)($ch['url'] ?? ''));
        if ($name === '' || $url === '') {
            continue;
        }
        $tvgId = trim((string)($ch['tvg_id'] ?? ''));
        $logo = trim((string)($ch['logo'] ?? ''));
        $category = trim((string)($ch['category'] ?? '')) ?: 'Otros';

        $attrs = 'tvg-id="' . str_replace('"', '', $tvgId) . '"'
            . ' group-title="' . str_replace('"', '', $category) . '"'
            . ' tvg-logo="' . str_replace('"', '', $logo) . '"';
        $lines[] = '#EXTINF:-1 ' . $attrs . ',' . $name;
        $lines[] = $url;
    }
    if (count($lines) <= 1) {
        respond_error('Ningún canal tenía nombre y URL válidos.');
    }

    $uploadDir = __DIR__ . '/../uploads/m3u';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
        respond_error('No se pudo crear el directorio de subidas en el servidor.', 500);
    }
    $filename = $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.m3u';
    $filePath = $uploadDir . '/' . $filename;
    if (file_put_contents($filePath, implode("\n", $lines) . "\n") === false) {
        respond_error('No se pudo guardar el archivo en el servidor.', 500);
    }

    $fileUri = 'file://' . realpath($filePath);

    $stmt = $pdo->prepare("INSERT INTO providers (user_id, name, m3u_url, check_interval_minutes, next_check_at) VALUES (?,?,?,?, NOW())");
    $stmt->execute([$userId, $providerName, $fileUri, $interval]);
    $providerId = (int)$pdo->lastInsertId();

    try {
        $sync = new Sync();
        $result = $sync->syncProvider($providerId);
    } catch (Throwable $e) {
        respond_error('El proveedor se creó, pero la importación falló: ' . $e->getMessage(), 500);
    }

    respond([
        'ok' => true,
        'provider_id' => $providerId,
        'added' => $result['added'],
        'message' => 'Proveedor "' . $providerName . '" creado con ' . $result['added'] . ' canales.',
    ], 201);
}

respond_error('Acción no soportada.', 400);
