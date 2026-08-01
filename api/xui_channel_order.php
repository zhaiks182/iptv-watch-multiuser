<?php
/**
 * Ordenar alfabéticamente (A-Z) los canales en vivo de una o más categorías
 * ya importadas a XUI·ONE — mismo criterio que el botón "A to Z" de la
 * herramienta "Channel Order" del panel, pero sin tocar el orden de las
 * demás categorías ni de películas/series/radio (ver
 * includes/XuiSession.php::xui_session_sort_live_categories_az() para el
 * detalle de cómo se logra eso sin reconstruir el orden global entero).
 *
 * POST {action:'sort_categories_az', category_ids:[xuiCategoryId, ...]}
 *   -> devuelve {ok, sorted_count} o {error} si algo falló (sin sesión web
 *      configurada, login fallido, panel no confirmó el guardado, etc.)
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/XuiClient.php';
require_once __DIR__ . '/../includes/XuiSession.php';

set_time_limit(120);

$pdo = get_pdo();
$userId = (int)$_SESSION['user_id'];
$panelStmt = $pdo->prepare("SELECT * FROM xui_panels WHERE is_active = 1 AND user_id = ? LIMIT 1");
$panelStmt->execute([$userId]);
$panel = $panelStmt->fetch();
if (!$panel) {
    respond_error('No hay ninguna conexión XUI·ONE activa. Agrega o activa una en "Integración XUI·ONE".', 404);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('Método no soportado', 405);
}

$body = json_input();
$action = $body['action'] ?? '';

if ($action === 'sort_categories_az') {
    $categoryIds = array_values(array_unique(array_filter(array_map('intval', $body['category_ids'] ?? []))));
    if (!$categoryIds) {
        respond_error('Falta category_ids.');
    }
    if (empty($panel['panel_username']) || empty($panel['panel_password_enc'])) {
        respond_error('Esta conexión no tiene sesión web configurada (usuario/contraseña del panel) — es necesaria para ordenar canales, igual que para servidor/on-demand.', 400);
    }

    session_write_close();
    $result = xui_session_sort_live_categories_az($panel, $categoryIds);
    if (!$result['ok']) {
        respond_error($result['error'] ?? 'No se pudo ordenar los canales.', 502);
    }
    respond(['ok' => true, 'sorted_count' => $result['sorted_count']]);
}

respond_error('Acción no reconocida.', 400);
