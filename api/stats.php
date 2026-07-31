<?php

require_once __DIR__ . '/bootstrap.php';

$pdo = get_pdo();
$userId = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT COUNT(*) FROM channels WHERE status='active' AND user_id = ?");
$stmt->execute([$userId]);
$activeChannels = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT category_id) FROM channels WHERE status='active' AND category_id IS NOT NULL AND user_id = ?
");
$stmt->execute([$userId]);
$categoriesCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND name = 'Sin clasificar' LIMIT 1");
$stmt->execute([$userId]);
$uncatRow = $stmt->fetch();
$unclassified = 0;
if ($uncatRow) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM channels WHERE status='active' AND category_id = ? AND user_id = ?");
    $stmt->execute([$uncatRow['id'], $userId]);
    $unclassified = (int)$stmt->fetchColumn();
}

$since = date('Y-m-d 00:00:00');
$stmt = $pdo->prepare("SELECT type, COUNT(*) as n FROM channel_changes WHERE created_at >= ? AND user_id = ? GROUP BY type");
$stmt->execute([$since, $userId]);
$byType = ['added' => 0, 'removed' => 0, 'modified' => 0, 'category_added' => 0];
foreach ($stmt->fetchAll() as $row) {
    $byType[$row['type']] = (int)$row['n'];
}
// "category_added" no cuenta como cambio de canal para el total mostrado en el stat-card
$totalChangesToday = $byType['added'] + $byType['removed'] + $byType['modified'];

$stmt = $pdo->prepare("SELECT COUNT(*) FROM channel_changes WHERE is_read = 0 AND user_id = ?");
$stmt->execute([$userId]);
$unreadChanges = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT name, next_check_at, check_interval_minutes
    FROM providers
    WHERE is_active = 1 AND user_id = ?
    ORDER BY next_check_at ASC
    LIMIT 1
");
$stmt->execute([$userId]);
$nextProvider = $stmt->fetch();

$stmt = $pdo->prepare("SELECT MAX(last_checked_at) AS m FROM providers WHERE user_id = ?");
$stmt->execute([$userId]);
$lastChecked = $stmt->fetch()['m'] ?? null;

respond([
    'active_channels' => $activeChannels,
    'categories_count' => $categoriesCount,
    'unclassified' => $unclassified,
    'changes_today' => $totalChangesToday,
    'changes_by_type' => $byType,
    'unread_changes' => $unreadChanges,
    'next_check' => $nextProvider ?: null,
    'last_checked_at' => $lastChecked,
]);
