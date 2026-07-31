<?php

/**
 * Autenticación local por sesión PHP. Un usuario válido en la tabla `users`
 * (contraseña con password_hash/password_verify) puede iniciar sesión desde
 * login.html -> api/login.php. Mientras la sesión esté activa, el resto de
 * los endpoints de api/ responden normalmente; si no, devuelven 401.
 */

function auth_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function auth_is_logged_in(): bool
{
    auth_start_session();
    return !empty($_SESSION['user_id']);
}

function auth_require_or_401(): void
{
    if (!auth_is_logged_in()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'No autenticado. Vuelve a iniciar sesión.']);
        exit;
    }
}

/**
 * Devuelve ['ok'=>bool, 'error'=>?string, 'reason'=>?string] en vez de un
 * simple bool: el llamador (api/login.php) necesita distinguir "usuario/
 * contraseña incorrectos" de "cuenta pendiente de aprobación" o "cuenta
 * deshabilitada" para mostrar el mensaje correcto — todo el chequeo vive
 * acá para no duplicar la lógica de estados en cada endpoint que necesite
 * loguear. "reason" además le dice a api/login.php si el fallo cuenta como
 * intento de adivinar una contraseña (solo 'invalid_credentials') para el
 * bloqueo temporal por intentos repetidos — un 'pending'/'disabled' no es
 * evidencia de fuerza bruta, es una credencial válida con la cuenta bloqueada.
 */
function auth_login(PDO $pdo, string $username, string $password): array
{
    $stmt = $pdo->prepare('SELECT id, password_hash, role, status FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['ok' => false, 'error' => 'Usuario o contraseña incorrectos.', 'reason' => 'invalid_credentials'];
    }
    if ($user['status'] === 'pending') {
        return ['ok' => false, 'error' => 'Tu cuenta está pendiente de aprobación por un administrador.', 'reason' => 'pending'];
    }
    if ($user['status'] === 'disabled') {
        return ['ok' => false, 'error' => 'Tu cuenta fue deshabilitada. Contacta a un administrador.', 'reason' => 'disabled'];
    }

    auth_start_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $username;
    $_SESSION['role'] = $user['role'];
    return ['ok' => true, 'error' => null, 'reason' => null];
}

function auth_logout(): void
{
    auth_start_session();
    $_SESSION = [];
    session_destroy();
}

/**
 * Exige que la sesión activa sea de un administrador — lo usa
 * api/admin_users.php. Un admin puede aprobar/deshabilitar cuentas, pero
 * esta función (a propósito) no da acceso a los datos privados de otros
 * usuarios; cada endpoint de datos sigue filtrando por su propio user_id.
 */
function auth_require_admin(): void
{
    auth_start_session();
    if (($_SESSION['role'] ?? null) !== 'admin') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Requiere permisos de administrador.']);
        exit;
    }
}
