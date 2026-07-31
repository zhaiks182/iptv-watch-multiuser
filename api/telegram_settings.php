<?php
/**
 * Configuración de notificaciones por Telegram (una fila por usuario,
 * user_id como llave primaria).
 * GET                                  -> estado actual (nunca devuelve el token)
 * POST {action:'test', bot_token, chat_id}
 *   -> envía un mensaje de prueba SIN guardar nada. Si bot_token viene
 *      vacío y ya hay una configuración guardada, se prueba con el token
 *      ya guardado (para poder probar solo un cambio de chat_id sin tener
 *      que volver a pegar el token).
 * POST {action:'save', bot_token, chat_id, enabled}
 *   -> valida con un mensaje de prueba real ANTES de guardar (mismo patrón
 *      que api/xui_panels.php: no se guarda una integración que en realidad
 *      no conecta). Si bot_token viene vacío, conserva el que ya había.
 * POST {action:'delete'} -> elimina la configuración por completo.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/Telegram.php';
require_once __DIR__ . '/../includes/Crypto.php';

$pdo = get_pdo();
$userId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("SELECT chat_id, enabled, bot_token_enc FROM telegram_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    respond([
        'configured' => (bool)$row,
        'chat_id' => $row['chat_id'] ?? '',
        'enabled' => $row ? (bool)$row['enabled'] : false,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('Método no soportado', 405);
}

$body = json_input();
$action = $body['action'] ?? '';

function telegram_existing_token(PDO $pdo, int $userId): ?string
{
    $stmt = $pdo->prepare("SELECT bot_token_enc FROM telegram_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ? xui_decrypt($row['bot_token_enc']) : null;
}

if ($action === 'test') {
    $botToken = trim($body['bot_token'] ?? '');
    $chatId = trim($body['chat_id'] ?? '');

    if ($botToken === '') {
        $botToken = telegram_existing_token($pdo, $userId) ?? '';
    }
    if ($botToken === '' || $chatId === '') {
        respond_error('Completa el Bot Token y el Chat ID (o guarda uno primero para poder probar solo con el Chat ID nuevo).');
    }

    session_write_close();
    $result = telegram_send_message($botToken, $chatId, "✅ <b>IPTV·WATCH</b> conectado correctamente a este chat.\nAquí llegarán los avisos de cambios en tus proveedores.");
    if (!$result['ok']) {
        respond_error('No se pudo enviar el mensaje de prueba: ' . $result['error'], 422);
    }
    respond(['ok' => true]);
}

if ($action === 'save') {
    $botToken = trim($body['bot_token'] ?? '');
    $chatId = trim($body['chat_id'] ?? '');
    $enabled = !empty($body['enabled']);

    if ($chatId === '') {
        respond_error('Falta el Chat ID.');
    }
    if ($botToken === '') {
        $botToken = telegram_existing_token($pdo, $userId) ?? '';
    }
    if ($botToken === '') {
        respond_error('Falta el Bot Token.');
    }

    // No se guarda una integración que en realidad no conecta — se prueba
    // de verdad antes de persistir, mismo patrón que api/xui_panels.php.
    session_write_close();
    $result = telegram_send_message($botToken, $chatId, "✅ <b>IPTV·WATCH</b> conectado correctamente a este chat.\nAquí llegarán los avisos de cambios en tus proveedores.");
    if (!$result['ok']) {
        respond_error('No se pudo validar la conexión antes de guardar: ' . $result['error'], 422);
    }

    $tokenEnc = xui_encrypt($botToken);
    $stmt = $pdo->prepare("INSERT INTO telegram_settings (user_id, bot_token_enc, chat_id, enabled) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE bot_token_enc = VALUES(bot_token_enc), chat_id = VALUES(chat_id), enabled = VALUES(enabled)");
    $stmt->execute([$userId, $tokenEnc, $chatId, $enabled ? 1 : 0]);

    respond(['ok' => true]);
}

if ($action === 'delete') {
    $stmt = $pdo->prepare("DELETE FROM telegram_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    respond(['ok' => true]);
}

respond_error('Acción no soportada.', 400);
