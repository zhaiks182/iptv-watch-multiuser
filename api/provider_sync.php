<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/Sync.php';
require_once __DIR__ . '/../includes/Telegram.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('Método no soportado', 405);
}

$body = json_input();
$pdo = get_pdo();
$sync = new Sync();
$userId = (int)$_SESSION['user_id'];

$providerId = isset($body['provider_id']) && $body['provider_id'] !== '' ? (int)$body['provider_id'] : null;

try {
    if ($providerId) {
        // Verificar dueño ANTES de sincronizar — evita que alguien sincronice
        // el proveedor de otro usuario adivinando su id.
        $stmt = $pdo->prepare('SELECT id FROM providers WHERE id = ? AND user_id = ?');
        $stmt->execute([$providerId, $userId]);
        if (!$stmt->fetch()) {
            respond_error('Proveedor no encontrado.', 404);
        }

        $result = $sync->syncProvider($providerId);
        if (!$result['was_first_sync'] && !empty($result['events'])) {
            telegram_notify_provider_changes($pdo, $result['user_id'], $result['provider_name'], $result['events']);
        }
        respond(['ok' => true, 'provider_id' => $providerId, 'result' => $result]);
    } else {
        $stmt = $pdo->prepare("SELECT id FROM providers WHERE is_active = 1 AND user_id = ?");
        $stmt->execute([$userId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $results = [];
        foreach ($ids as $id) {
            try {
                $results[$id] = $sync->syncProvider((int)$id);
                if (!$results[$id]['was_first_sync'] && !empty($results[$id]['events'])) {
                    telegram_notify_provider_changes($pdo, $results[$id]['user_id'], $results[$id]['provider_name'], $results[$id]['events']);
                }
            } catch (Throwable $e) {
                $results[$id] = ['error' => $e->getMessage()];
            }
        }
        respond(['ok' => true, 'results' => $results]);
    }
} catch (Throwable $e) {
    respond_error('Error al sincronizar: ' . $e->getMessage(), 500);
}
