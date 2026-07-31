<?php
/**
 * Inicio de sesión.
 *
 * POST {username, password, turnstile_token}
 *
 * Protecciones anti-fuerza-bruta (en orden):
 * 1. Bloqueo temporal por usuario: máx. 5 fallos de credenciales en los
 *    últimos 15 minutos para el mismo "username" (tabla login_attempts) —
 *    SIEMPRE activo. Se guarda el string tal cual se envió, no un user_id,
 *    para no tener que revelar si la cuenta existe con una consulta aparte.
 *    Solo cuentan los fallos por credenciales inválidas, no los rechazos por
 *    estado 'pending'/'disabled' (ver includes/auth.php::auth_login()).
 * 2. Captcha (Cloudflare Turnstile) — se exige automáticamente en cuanto un
 *    admin guardó Site Key + Secret Key de Turnstile (mismo criterio que
 *    api/register.php, ver api/app_settings.php). Se verifica antes de tocar
 *    la contraseña para no darle a un bot ninguna pista extra sobre si el
 *    usuario existe.
 */

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('Método no soportado', 405);
}

$body = json_input();
$username = trim($body['username'] ?? '');
$password = (string)($body['password'] ?? '');
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if ($username === '' || $password === '') {
    respond_error('Usuario y contraseña son obligatorios.');
}

$pdo = get_pdo();

// --- Paso 1: bloqueo temporal por intentos fallidos repetidos ---
$pdo->exec("DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 1 DAY"); // auto-limpieza, tabla chica
$stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE username = ? AND created_at > NOW() - INTERVAL 15 MINUTE");
$stmt->execute([$username]);
if ((int)$stmt->fetchColumn() >= 5) {
    respond_error('Demasiados intentos fallidos para esta cuenta. Espera unos minutos e intenta de nuevo.', 429);
}

// --- Paso 2: captcha (solo si un admin guardó ambas llaves de Turnstile) ---
$settings = $pdo->query("SELECT turnstile_site_key, turnstile_secret_key_enc FROM app_settings WHERE id = 1")->fetch();
if ($settings && !empty($settings['turnstile_site_key']) && !empty($settings['turnstile_secret_key_enc'])) {
    require_once __DIR__ . '/../includes/Crypto.php';
    require_once __DIR__ . '/../includes/Turnstile.php';
    $secretKey = xui_decrypt($settings['turnstile_secret_key_enc']);
    $token = trim($body['turnstile_token'] ?? '');
    if (!$secretKey || $token === '' || !turnstile_verify($secretKey, $token, $ip)) {
        respond_error('Completa la verificación de "no soy un robot".', 422);
    }
}

$result = auth_login($pdo, $username, $password);
if (!$result['ok']) {
    if ($result['reason'] === 'invalid_credentials') {
        $pdo->prepare("INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)")->execute([$username, $ip]);
    }
    respond_error($result['error'], 401);
}

// Login correcto: limpia cualquier fallo previo para este usuario, no debe
// quedar arrastrando un bloqueo tras haber entrado bien.
$pdo->prepare("DELETE FROM login_attempts WHERE username = ?")->execute([$username]);

respond(['ok' => true]);
