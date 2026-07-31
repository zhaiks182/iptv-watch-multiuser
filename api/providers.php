<?php
/**
 * GET                                                   -> listar proveedores del usuario
 * POST {name, m3u_url, check_interval_minutes}          -> crear (action implícito 'create')
 * POST {action:'update', id, name, m3u_url, check_interval_minutes} -> editar
 * POST {action:'delete', id}                             -> eliminar (arrastra sus canales
 *   y su historial de cambios por FK ON DELETE CASCADE; las categorías del usuario
 *   NO se tocan, son compartidas entre proveedores)
 */

require_once __DIR__ . '/bootstrap.php';

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'];
$userId = (int)$_SESSION['user_id'];

if ($method === 'GET') {
    $stmt = $pdo->prepare("
        SELECT p.*,
          (SELECT COUNT(*) FROM channels c WHERE c.provider_id = p.id AND c.status = 'active') AS channel_count,
          (SELECT COUNT(*) FROM channel_changes cc WHERE cc.provider_id = p.id AND cc.is_read = 0) AS unread_changes
        FROM providers p
        WHERE p.user_id = ?
        ORDER BY p.id ASC
    ");
    $stmt->execute([$userId]);
    respond(['providers' => $stmt->fetchAll()]);
}

if ($method !== 'POST') {
    respond_error('Método no soportado', 405);
}

/** Valida name/m3u_url/check_interval_minutes, comunes a crear y editar. */
function providers_validate_input(array $body): array
{
    $name = trim($body['name'] ?? '');
    $url = trim($body['m3u_url'] ?? '');
    $interval = (int)($body['check_interval_minutes'] ?? 60);

    if ($name === '' || $url === '') {
        respond_error('Nombre y enlace M3U son obligatorios.');
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        respond_error('El enlace M3U no es una URL válida.');
    }
    if ($interval < 5) {
        $interval = 5;
    }
    return [$name, $url, $interval];
}

$body = json_input();
$action = $body['action'] ?? 'create';

if ($action === 'update') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) {
        respond_error('Falta el id del proveedor.');
    }
    $stmt = $pdo->prepare('SELECT id FROM providers WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    if (!$stmt->fetch()) {
        respond_error('Proveedor no encontrado.', 404);
    }

    [$name, $url, $interval] = providers_validate_input($body);

    $pdo->prepare('UPDATE providers SET name = ?, m3u_url = ?, check_interval_minutes = ? WHERE id = ?')
        ->execute([$name, $url, $interval, $id]);

    respond(['ok' => true, 'message' => 'Proveedor actualizado.']);
}

if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) {
        respond_error('Falta el id del proveedor.');
    }
    $stmt = $pdo->prepare('DELETE FROM providers WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    if ($stmt->rowCount() === 0) {
        respond_error('Proveedor no encontrado.', 404);
    }
    respond(['ok' => true, 'message' => 'Proveedor eliminado junto con sus canales e historial.']);
}

if ($action === 'create') {
    [$name, $url, $interval] = providers_validate_input($body);

    $stmt = $pdo->prepare("INSERT INTO providers (user_id, name, m3u_url, check_interval_minutes, next_check_at) VALUES (?,?,?,?, NOW())");
    $stmt->execute([$userId, $name, $url, $interval]);
    $id = (int)$pdo->lastInsertId();

    respond([
        'id' => $id,
        'message' => 'Proveedor creado. Se sincronizará en el próximo ciclo de cron o al pulsar "Sincronizar ahora".',
    ], 201);
}

respond_error('Acción no soportada.', 400);
