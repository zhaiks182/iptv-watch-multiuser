<?php
/**
 * Prueba de conexión (solo lectura) contra la API de administración de XUI·ONE.
 * Arma la URL {panel_url}/{access_code}/?api_key={api_key}&action={action}
 * y devuelve la respuesta cruda tal cual la entrega el panel.
 *
 * Solo permite acciones de solo lectura a propósito: se comprobó que algunas
 * acciones de XUI·ONE (ej. create_category, create_stream) se EJECUTAN de
 * verdad incluso sin parámetros completos. Cualquier acción que cree, edite,
 * elimine, habilite/deshabilite o inicie/detenga algo debe pasar por el flujo
 * de importación dedicado (api/xui_import.php), que arma parámetros completos
 * a propósito y nunca se dispara con un solo clic accidental.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/XuiClient.php';

// La sesión ya se validó arriba (bootstrap.php); la cerramos ANTES de la
// petición de red (que puede tardar varios segundos) para no bloquear otras
// pestañas/peticiones que compartan la misma sesión mientras esta espera.
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('Método no soportado', 405);
}

const XUI_READONLY_ACTIONS = [
    'user_info', 'get_lines', 'get_streams', 'get_channels', 'get_categories',
    'get_servers', 'get_packages', 'get_bouquets',
    'activity_logs', 'live_connections', 'login_logs', 'stream_errors',
];

$body = json_input();
$panelUrl = rtrim(trim($body['panel_url'] ?? ''), '/');
$accessCode = trim($body['access_code'] ?? '', "/ \t\n\r\0\x0B");
$apiKey = trim($body['api_key'] ?? '');
$action = trim($body['action'] ?? '') ?: 'user_info';

if ($panelUrl === '' || $accessCode === '' || $apiKey === '') {
    respond_error('Completa URL del panel, Access Code y API Key.');
}
if (!filter_var($panelUrl, FILTER_VALIDATE_URL)) {
    respond_error('La URL del panel no es válida.');
}
if (!preg_match('/^[A-Za-z0-9_-]+$/', $accessCode)) {
    respond_error('El Access Code solo puede tener letras, números, guiones y guion bajo.');
}
if (!in_array($action, XUI_READONLY_ACTIONS, true)) {
    respond_error('Esta acción no está permitida en el probador (solo lectura). Usa el flujo de importación para crear/editar/eliminar.');
}

$result = xui_call($panelUrl, $accessCode, $apiKey, $action);

if ($result['raw'] === null) {
    respond([
        'ok' => false,
        'error' => $result['error'],
        'tested_url' => xui_mask_api_key($result['target_url'], $apiKey),
    ]);
}

respond([
    'ok' => $result['ok'],
    'http_code' => $result['http_code'],
    'tested_url' => xui_mask_api_key($result['target_url'], $apiKey),
    'raw_response' => $result['raw'],
    'json' => $result['json'],
]);
