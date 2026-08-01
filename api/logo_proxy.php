<?php
/**
 * Proxy de logos: algunos proveedores M3U alojan sus logos en un host que
 * solo responde por HTTP (sin TLS válido, ej. el puerto de un panel XUI
 * cualquiera). El dashboard se sirve por HTTPS, y los navegadores modernos
 * intentan "subir" (auto-upgrade) esas imágenes a HTTPS antes de pedirlas —
 * si el host no tiene HTTPS real ahí, la petición falla directo
 * (net::ERR_SSL_PROTOCOL_ERROR / ERR_CONNECTION_*) sin reintentar por HTTP,
 * así que el <img> nunca carga aunque la URL guardada sea perfectamente
 * válida por HTTP. Confirmado en producción (2026-08-01) con las
 * herramientas de red del navegador.
 *
 * Este endpoint descarga la imagen del lado del servidor (que sí puede
 * hablarle al origen por HTTP sin restricción del navegador) y la reenvía
 * al navegador desde nuestro propio dominio HTTPS, con caché en disco para
 * no repetir la descarga en cada carga de la página.
 *
 * GET ?channel_id=123 -> el logo_url del canal DEBE pertenecer al usuario
 * de la sesión (mismo criterio que el resto de la app: cada quien solo ve
 * sus propios canales). Devuelve la imagen tal cual (Content-Type real) o
 * un código de error HTTP si algo falla — nunca JSON, porque esto se usa
 * directo como <img src="...">.
 */

require_once __DIR__ . '/bootstrap.php';

$channelId = (int)($_GET['channel_id'] ?? 0);
if (!$channelId) {
    http_response_code(400);
    exit;
}

$pdo = get_pdo();
$userId = (int)$_SESSION['user_id'];
$stmt = $pdo->prepare('SELECT logo_url FROM channels WHERE id = ? AND user_id = ?');
$stmt->execute([$channelId, $userId]);
$row = $stmt->fetch();
$logoUrl = trim((string)($row['logo_url'] ?? ''));

if ($logoUrl === '' || !preg_match('#^https?://#i', $logoUrl)) {
    http_response_code(404);
    exit;
}

// Caché en disco: evita re-descargar la misma imagen en cada carga de la
// página. Clave incluye un hash de la URL (no solo el channel_id) para que
// un cambio de logo (mismo canal, otra URL) no sirva la imagen vieja.
$cacheDir = __DIR__ . '/../uploads/cache/logo_proxy';
if (!is_dir($cacheDir) && !mkdir($cacheDir, 0750, true) && !is_dir($cacheDir)) {
    http_response_code(500);
    exit;
}
$cacheKey = $channelId . '_' . substr(md5($logoUrl), 0, 16);
$cacheFile = $cacheDir . '/' . $cacheKey;
$cacheTypeFile = $cacheFile . '.type';
$maxAgeSeconds = 7 * 86400;

if (is_file($cacheFile) && is_file($cacheTypeFile) && (time() - filemtime($cacheFile)) < $maxAgeSeconds) {
    header('Content-Type: ' . trim((string)file_get_contents($cacheTypeFile)));
    header('Cache-Control: public, max-age=86400');
    readfile($cacheFile);
    exit;
}

// Freno SSRF: el proveedor del M3U controla el contenido de logo_url — no
// hay que dejar que esto se use para golpear direcciones internas de
// nuestra propia red (localhost, rangos privados, link-local, etc.).
$host = parse_url($logoUrl, PHP_URL_HOST);
$ips = $host ? (gethostbynamel($host) ?: []) : [];
if (!$ips) {
    http_response_code(502);
    exit;
}
foreach ($ips as $ip) {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        http_response_code(403);
        exit;
    }
}

$maxBytes = 5 * 1024 * 1024; // logos son iconos chicos; 5MB es más que de sobra
$buffer = '';
$ch = curl_init($logoUrl);
curl_setopt_array($ch, [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USERAGENT => 'IPTV-Watch/1.0',
    CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buffer, $maxBytes) {
        $buffer .= $chunk;
        return strlen($buffer) > $maxBytes ? 0 : strlen($chunk); // 0 aborta la transferencia
    },
]);
$ok = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$originType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($ok === false || $httpCode < 200 || $httpCode >= 300 || $buffer === '') {
    http_response_code(502);
    exit;
}

$contentType = $originType;
if (stripos($contentType, 'image/') !== 0) {
    // El origen no mandó (o mandó mal) el Content-Type — se confirma
    // olfateando los bytes reales antes de servir cualquier cosa como imagen.
    $info = @getimagesizefromstring($buffer);
    if ($info === false || empty($info['mime'])) {
        http_response_code(502);
        exit;
    }
    $contentType = $info['mime'];
}

file_put_contents($cacheFile, $buffer);
file_put_contents($cacheTypeFile, $contentType);

header('Content-Type: ' . $contentType);
header('Cache-Control: public, max-age=86400');
echo $buffer;
