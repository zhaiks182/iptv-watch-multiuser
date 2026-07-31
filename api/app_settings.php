<?php
/**
 * Configuración global de la app (fila única, tabla app_settings, id=1).
 * Por ahora solo cubre el captcha de Cloudflare Turnstile.
 *
 * El captcha no tiene interruptor de encendido/apagado: se exige
 * automáticamente en registro y login en cuanto hay Site Key + Secret Key
 * guardadas, y se desactiva por completo si se borra el Site Key. Así no
 * queda una llave configurada con el captcha "apagado" por error.
 *
 * GET  -> PÚBLICO, sin sesión (register.html y login.html lo necesitan antes
 *         de loguear): {captcha_required, turnstile_site_key}. NUNCA
 *         devuelve la llave secreta — esa solo se usa del lado del servidor
 *         (api/register.php, api/login.php) para verificar contra la API de
 *         Cloudflare.
 * POST {turnstile_site_key, turnstile_secret_key}
 *      -> SOLO admin (ver auth_require_admin() más abajo). turnstile_secret_key
 *         es opcional al editar: vacío = conservar la que ya había guardada.
 *         Enviar turnstile_site_key vacío borra ambas llaves y desactiva el
 *         captcha.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/Crypto.php';

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $row = $pdo->query("SELECT turnstile_site_key, turnstile_secret_key_enc FROM app_settings WHERE id = 1")->fetch();
    respond([
        'captcha_required' => $row ? (!empty($row['turnstile_site_key']) && !empty($row['turnstile_secret_key_enc'])) : false,
        'turnstile_site_key' => $row['turnstile_site_key'] ?? null,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('Método no soportado', 405);
}

// Cambiar esta configuración SÍ requiere ser admin — el GET de arriba es
// público a propósito (register.html/login.html lo necesitan sin sesión),
// pero modificarla no.
auth_require_admin();

$body = json_input();
$siteKey = trim($body['turnstile_site_key'] ?? '');
$secretKey = trim($body['turnstile_secret_key'] ?? '');

if ($siteKey === '') {
    // Sin Site Key no hay captcha posible: se borra todo (forma explícita
    // de desactivarlo desde el panel).
    $pdo->prepare("INSERT INTO app_settings (id, turnstile_site_key, turnstile_secret_key_enc) VALUES (1, NULL, NULL)
        ON DUPLICATE KEY UPDATE turnstile_site_key = NULL, turnstile_secret_key_enc = NULL")->execute();
    respond(['ok' => true]);
}

$existing = $pdo->query("SELECT turnstile_secret_key_enc FROM app_settings WHERE id = 1")->fetch();
if ($secretKey !== '') {
    $secretKeyEnc = xui_encrypt($secretKey);
} else {
    $secretKeyEnc = $existing['turnstile_secret_key_enc'] ?? null;
}

if (!$secretKeyEnc) {
    respond_error('Completa también el Secret Key de Turnstile.');
}

$stmt = $pdo->prepare("INSERT INTO app_settings (id, turnstile_site_key, turnstile_secret_key_enc) VALUES (1, ?, ?)
    ON DUPLICATE KEY UPDATE turnstile_site_key = VALUES(turnstile_site_key), turnstile_secret_key_enc = VALUES(turnstile_secret_key_enc)");
$stmt->execute([$siteKey, $secretKeyEnc]);

respond(['ok' => true]);
