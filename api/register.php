<?php
/**
 * Registro de una cuenta nueva. La cuenta nace en status='pending' — no
 * puede iniciar sesión (ver includes/auth.php::auth_login()) hasta que un
 * administrador la aprueba desde el módulo de administración
 * (api/admin_users.php). No abre sesión aquí a propósito.
 *
 * POST {username, password, password_confirm, website, form_rendered_at, turnstile_token}
 *
 * Protecciones anti-spam (en orden):
 * 1. Límite por IP: máx. 5 intentos de registro por hora (tabla
 *    registration_attempts) — SIEMPRE activo, no tiene interruptor porque
 *    es invisible y sin costo para alguien real.
 * 2. Honeypot: "website" es un campo oculto por CSS que un humano nunca
 *    llena, pero un bot que rellena todos los inputs sí. Si viene con
 *    contenido, se responde el mismo mensaje de éxito de siempre SIN crear
 *    la cuenta — para no delatarle al bot que fue detectado.
 * 3. Tiempo mínimo: "form_rendered_at" es la hora (epoch ms) en que
 *    register.html terminó de cargar, puesta por su propio JS. Si el
 *    envío llega a menos de 3 segundos de esa marca, se trata igual que el
 *    honeypot (falso éxito) — un bot típico completa y envía el formulario
 *    casi instantáneamente.
 * 4. Captcha (Cloudflare Turnstile) — se exige automáticamente en cuanto un
 *    admin guarda Site Key + Secret Key desde 🛡️ Administración de usuarios
 *    (ver api/app_settings.php); no tiene interruptor aparte, así no queda
 *    una llave configurada con el captcha "apagado" por error. Se verifica
 *    de verdad contra la API de Cloudflare antes de continuar. A diferencia
 *    del honeypot y el tiempo mínimo, un captcha fallido SÍ da un error real
 *    (quien lo falla merece saber que debe reintentarlo, no es
 *    necesariamente un bot).
 */

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('Método no soportado', 405);
}

$body = json_input();
$pdo = get_pdo();

const REGISTER_FAKE_SUCCESS = [
    'ok' => true,
    'message' => 'Cuenta creada. Un administrador debe aprobarla antes de que puedas iniciar sesión.',
];

// --- Paso 1: límite por IP (siempre activo) ---
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$pdo->exec("DELETE FROM registration_attempts WHERE created_at < NOW() - INTERVAL 1 DAY"); // auto-limpieza, tabla chica
$stmt = $pdo->prepare("SELECT COUNT(*) FROM registration_attempts WHERE ip_address = ? AND created_at > NOW() - INTERVAL 1 HOUR");
$stmt->execute([$ip]);
if ((int)$stmt->fetchColumn() >= 5) {
    respond_error('Demasiados intentos de registro desde esta conexión. Espera un rato e intenta de nuevo.', 429);
}
// Se registra el intento YA (antes de cualquier otra validación) para que
// también cuenten los que el honeypot o el tiempo mínimo detecten como bot
// — así un bot insistente se autolimita rápido.
$pdo->prepare("INSERT INTO registration_attempts (ip_address) VALUES (?)")->execute([$ip]);

// --- Paso 2: honeypot ---
$honeypot = trim($body['website'] ?? '');
if ($honeypot !== '') {
    respond(REGISTER_FAKE_SUCCESS, 201);
}

// --- Paso 3: tiempo mínimo desde que cargó el formulario ---
$renderedAt = (float)($body['form_rendered_at'] ?? 0) / 1000;
if ($renderedAt > 0 && (microtime(true) - $renderedAt) < 3) {
    respond(REGISTER_FAKE_SUCCESS, 201);
}

$username = trim($body['username'] ?? '');
$password = (string)($body['password'] ?? '');
$passwordConfirm = (string)($body['password_confirm'] ?? '');

if ($username === '' || $password === '') {
    respond_error('Usuario y contraseña son obligatorios.');
}
if (mb_strlen($username) < 3) {
    respond_error('El usuario debe tener al menos 3 caracteres.');
}
if (strlen($password) < 8) {
    respond_error('La contraseña debe tener al menos 8 caracteres.');
}
if ($password !== $passwordConfirm) {
    respond_error('Las contraseñas no coinciden.');
}

// --- Paso 4: captcha (solo si un admin guardó ambas llaves de Turnstile) ---
$settings = $pdo->query("SELECT turnstile_site_key, turnstile_secret_key_enc FROM app_settings WHERE id = 1")->fetch();
if ($settings && !empty($settings['turnstile_site_key']) && !empty($settings['turnstile_secret_key_enc'])) {
    require_once __DIR__ . '/../includes/Crypto.php';
    require_once __DIR__ . '/../includes/Turnstile.php';
    $secretKey = xui_decrypt($settings['turnstile_secret_key_enc']);
    $token = trim($body['turnstile_token'] ?? '');
    if (!$secretKey || $token === '') {
        respond_error('Completa la verificación de "no soy un robot".');
    }
    if (!turnstile_verify($secretKey, $token, $ip)) {
        respond_error('No se pudo verificar que no eres un robot. Intenta de nuevo.', 422);
    }
}

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$stmt->execute([$username]);
if ($stmt->fetch()) {
    respond_error('Ese usuario ya existe.', 409);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, status) VALUES (?, ?, 'user', 'pending')");
$stmt->execute([$username, $hash]);

respond(REGISTER_FAKE_SUCCESS, 201);
