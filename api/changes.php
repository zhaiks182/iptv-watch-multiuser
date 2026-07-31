<?php

require_once __DIR__ . '/bootstrap.php';

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'];
$userId = (int)$_SESSION['user_id'];

if ($method === 'GET') {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    if ($limit < 1 || $limit > 500) {
        $limit = 50;
    }
    $stmt = $pdo->prepare("
        SELECT cc.*, p.name AS provider_name,
          catnew.name AS new_category_name,
          catold.name AS old_category_name
        FROM channel_changes cc
        JOIN providers p ON p.id = cc.provider_id
        LEFT JOIN categories catnew ON catnew.id = cc.new_category_id
        LEFT JOIN categories catold ON catold.id = cc.old_category_id
        WHERE cc.user_id = ?
        ORDER BY cc.created_at DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    respond(['changes' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $body = json_input();
    if (!empty($body['mark_all_read'])) {
        $stmt = $pdo->prepare("UPDATE channel_changes SET is_read = 1 WHERE is_read = 0 AND user_id = ?");
        $stmt->execute([$userId]);
        respond(['ok' => true]);
    }
    respond_error('Acción no reconocida');
}

respond_error('Método no soportado', 405);
