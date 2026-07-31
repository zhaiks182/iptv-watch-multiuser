<?php

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// login.php, logout.php y register.php deben poder ejecutarse sin sesión
// activa. app_settings.php también: su GET es público a propósito
// (register.html lo necesita antes de loguear para saber si mostrar el
// captcha) — su propio POST exige auth_require_admin() por dentro.
$__script = basename($_SERVER['SCRIPT_NAME'] ?? '');
if (!in_array($__script, ['login.php', 'logout.php', 'register.php', 'app_settings.php'], true)) {
    auth_require_or_401();
}

function json_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function respond($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function respond_error(string $message, int $code = 400): void
{
    respond(['error' => $message], $code);
}

set_exception_handler(function (Throwable $e) {
    error_log('[iptv-watch] ' . $e->getMessage());
    respond_error('Error interno: ' . $e->getMessage(), 500);
});
