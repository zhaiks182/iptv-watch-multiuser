<?php
/**
 * Exporta un .zip con un archivo .m3u independiente por cada categoría.
 * GET /api/export.php                 -> todas las categorías, todos los proveedores
 * GET /api/export.php?provider_id=N   -> solo los canales de ese proveedor
 * GET /api/export.php?category_id=N   -> un único .m3u con los canales de esa categoría
 *                                         (admite combinarse con provider_id)
 * GET /api/export.php?channel_id=N    -> un .m3u de un solo canal (para abrir en VLC u
 *                                         otro reproductor asociado a archivos .m3u)
 *
 * No usa bootstrap.php porque la respuesta no es JSON sino un archivo binario.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!auth_is_logged_in()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Acceso no autorizado. Inicia sesión en el panel primero.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Método no soportado']);
    exit;
}

$pdo = get_pdo();
$userId = (int)$_SESSION['user_id'];
$providerId = isset($_GET['provider_id']) && $_GET['provider_id'] !== '' ? (int)$_GET['provider_id'] : null;
$categoryId = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;
$channelId = isset($_GET['channel_id']) && $_GET['channel_id'] !== '' ? (int)$_GET['channel_id'] : null;

function sanitize_filename(string $name): string
{
    $name = preg_replace('/[\\\\\/:*?"<>|]+/u', '_', $name);
    $name = trim($name);
    return $name !== '' ? $name : 'Sin_categoria';
}

function build_extinf(string $tvgId, string $logo, string $categoryName, string $channelName): string
{
    return sprintf(
        '#EXTINF:-1 tvg-id="%s" tvg-logo="%s" group-title="%s",%s',
        $tvgId,
        $logo,
        $categoryName,
        $channelName
    );
}

if ($channelId) {
    $stmt = $pdo->prepare("
        SELECT ch.name AS channel_name, ch.tvg_id, ch.logo_url, ch.stream_url,
          COALESCE(c.name, 'Sin clasificar') AS category_name
        FROM channels ch
        LEFT JOIN categories c ON c.id = ch.category_id
        WHERE ch.id = ? AND ch.status = 'active' AND ch.user_id = ?
    ");
    $stmt->execute([$channelId, $userId]);
    $ch = $stmt->fetch();

    if (!$ch) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Canal no encontrado o inactivo.']);
        exit;
    }

    $lines = [
        '#EXTM3U',
        build_extinf((string)($ch['tvg_id'] ?? ''), (string)($ch['logo_url'] ?? ''), $ch['category_name'], $ch['channel_name']),
        $ch['stream_url'],
    ];
    $content = implode("\n", $lines) . "\n";

    header('Content-Type: audio/x-mpegurl; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . sanitize_filename($ch['channel_name']) . '.m3u"');
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: no-store');
    echo $content;
    exit;
}

if ($categoryId) {
    $sql = "
        SELECT c.name AS category_name, ch.name AS channel_name, ch.tvg_id, ch.logo_url, ch.stream_url
        FROM channels ch
        JOIN categories c ON c.id = ch.category_id
        WHERE ch.status = 'active' AND ch.category_id = ? AND ch.user_id = ?
    ";
    $params = [$categoryId, $userId];
    if ($providerId) {
        $sql .= " AND ch.provider_id = ?";
        $params[] = $providerId;
    }
    $sql .= " ORDER BY ch.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'No hay canales activos para esa categoría con ese filtro.']);
        exit;
    }

    $categoryName = $rows[0]['category_name'];
    $lines = ['#EXTM3U'];
    foreach ($rows as $ch) {
        $lines[] = build_extinf((string)($ch['tvg_id'] ?? ''), (string)($ch['logo_url'] ?? ''), $categoryName, $ch['channel_name']);
        $lines[] = $ch['stream_url'];
    }
    $content = implode("\n", $lines) . "\n";

    header('Content-Type: audio/x-mpegurl; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . sanitize_filename($categoryName) . '.m3u"');
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: no-store');
    echo $content;
    exit;
}

$sql = "
    SELECT c.name AS category_name, ch.name AS channel_name, ch.tvg_id, ch.logo_url, ch.stream_url
    FROM channels ch
    JOIN categories c ON c.id = ch.category_id
    WHERE ch.status = 'active' AND ch.user_id = ?
";
$params = [$userId];
if ($providerId) {
    $sql .= " AND ch.provider_id = ?";
    $params[] = $providerId;
}
$sql .= " ORDER BY c.name ASC, ch.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (empty($rows)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No hay canales activos para exportar con ese filtro.']);
    exit;
}

// Solo la exportación completa (todas las categorías en un .zip) necesita
// ZipArchive — a diferencia de channel_id/category_id de arriba, que solo
// generan texto .m3u plano y nunca deberían depender de esta extensión.
if (!class_exists('ZipArchive')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'La extensión php-zip no está instalada en el servidor.']);
    exit;
}

$byCategory = [];
foreach ($rows as $row) {
    $byCategory[$row['category_name']][] = $row;
}

$tmpZip = tempnam(sys_get_temp_dir(), 'iptvwatch_');
$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No se pudo crear el archivo ZIP temporal.']);
    exit;
}

foreach ($byCategory as $categoryName => $channels) {
    $lines = ["#EXTM3U"];
    foreach ($channels as $ch) {
        $lines[] = build_extinf((string)($ch['tvg_id'] ?? ''), (string)($ch['logo_url'] ?? ''), $categoryName, $ch['channel_name']);
        $lines[] = $ch['stream_url'];
    }
    $filename = sanitize_filename($categoryName) . '.m3u';
    $zip->addFromString($filename, implode("\n", $lines) . "\n");
}

$zip->close();

$zipName = $providerId ? "canales_proveedor_{$providerId}.zip" : 'canales_por_categoria.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Cache-Control: no-store');

readfile($tmpZip);
unlink($tmpZip);
exit;
