<?php
/**
 * CRUD de bouquets contra la conexión XUI·ONE activa. Un bouquet en esta
 * versión de XUI·ONE (1.5.13) es una lista de streams por tipo
 * (bouquet_channels/bouquet_movies/bouquet_radios/bouquet_series) — NO se
 * arma por categoría (probado: create_bouquet ignora bouquet_categories).
 * Sirve para, más adelante, asociar un canal a la vez a una categoría y a
 * uno o más bouquets al crearlo (api/xui_import.php seguirá encargándose
 * de categorías; este archivo es su equivalente para bouquets).
 *
 * GET -> lista los bouquets ordenados por bouquet_order.
 *
 * POST {action:'create', name} -> crea un bouquet vacío (sin canales
 *   todavía). La acción real es "create_bouquet" con "bouquet_name".
 *
 * POST {action:'delete', id} -> elimina un bouquet. La acción real es
 *   "delete_bouquet" con parámetro "id".
 *
 * Limitación conocida (verificada en pruebas): "edit_bouquet" existe (no
 * es "Invalid action") pero devuelve STATUS_FAILURE con cualquier
 * combinación de parámetros probada (id/bouquet_id, con o sin reenviar
 * bouquet_channels/movies/radios/series) — no se pudo confirmar cómo
 * editar un bouquet ya creado por esta API. Por ahora no se expone editar
 * ni reordenar bouquets.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/XuiClient.php';

$pdo = get_pdo();
$panelStmt = $pdo->prepare("SELECT * FROM xui_panels WHERE is_active = 1 AND user_id = ? LIMIT 1");
$panelStmt->execute([(int)$_SESSION['user_id']]);
$panel = $panelStmt->fetch();
if (!$panel) {
    respond_error('No hay ninguna conexión XUI·ONE activa. Agrega o activa una en "Integración XUI·ONE".', 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $result = xui_call($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'get_bouquets');
    if (!$result['ok']) {
        respond_error('No se pudo obtener los bouquets: ' . ($result['error'] ?? ('HTTP ' . $result['http_code'])), 502);
    }
    $bouquets = is_array($result['json']) ? $result['json'] : [];
    usort($bouquets, fn($a, $b) => ((int)($a['bouquet_order'] ?? 0)) <=> ((int)($b['bouquet_order'] ?? 0)));
    respond(['bouquets' => $bouquets]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('Método no soportado', 405);
}

$body = json_input();
$action = $body['action'] ?? '';

if ($action === 'create') {
    $name = trim($body['name'] ?? '');
    if ($name === '') {
        respond_error('El nombre del bouquet es obligatorio.');
    }

    session_write_close();
    $result = xui_call($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'create_bouquet', [
        'bouquet_name' => $name,
    ]);

    if (!$result['ok']) {
        respond_error('No se pudo contactar al panel: ' . ($result['error'] ?? ('HTTP ' . $result['http_code'])), 502);
    }
    if (($result['json']['status'] ?? null) !== 'STATUS_SUCCESS') {
        respond_error('El panel no confirmó la creación del bouquet.', 502);
    }

    respond(['ok' => true, 'bouquet' => $result['json']['data'] ?? null], 201);
}

if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) {
        respond_error('Falta el id a eliminar.');
    }

    session_write_close();
    $result = xui_call($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'delete_bouquet', [
        'id' => $id,
    ]);

    if (!$result['ok']) {
        respond_error('No se pudo contactar al panel: ' . ($result['error'] ?? ('HTTP ' . $result['http_code'])), 502);
    }
    if (($result['json']['status'] ?? null) !== 'STATUS_SUCCESS') {
        respond_error('El panel no confirmó la eliminación del bouquet.', 502);
    }

    respond(['ok' => true]);
}

respond_error('Acción no soportada.', 400);
