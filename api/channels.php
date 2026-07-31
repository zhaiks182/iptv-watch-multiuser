<?php

require_once __DIR__ . '/bootstrap.php';

$pdo = get_pdo();
$userId = (int)$_SESSION['user_id'];

$providerId = isset($_GET['provider_id']) && $_GET['provider_id'] !== '' ? (int)$_GET['provider_id'] : null;

$catStmt = $pdo->prepare("SELECT id, name FROM categories WHERE user_id = ? ORDER BY name ASC");
$catStmt->execute([$userId]);
$categories = $catStmt->fetchAll();

// Categorías con un evento 'category_added' aún sin leer -> nunca se vuelven a marcar
// como nuevas una vez leídas, porque ese INSERT solo ocurre una vez en su vida.
$newCatStmt = $pdo->prepare("
    SELECT DISTINCT new_category_id FROM channel_changes
    WHERE user_id = ? AND type = 'category_added' AND is_read = 0 AND new_category_id IS NOT NULL
");
$newCatStmt->execute([$userId]);
$newCategoryIds = $newCatStmt->fetchAll(PDO::FETCH_COLUMN);
$newCategoryIds = array_map('intval', $newCategoryIds);

// Canales activos, más los eliminados que todavía tengan su evento 'removed'
// sin leer (para mostrarlos tachados hasta que el usuario los reconozca).
// Antes se mostraban durante 48h fijas sin importar si ya se habían leído;
// ahora "Marcar leído" los saca de este listado de inmediato, en vez de
// esperar a que pase el tiempo.
$sql = "
    SELECT c.id, c.name, c.status, c.category_id, c.provider_id, c.stream_url, c.logo_url, c.tvg_id,
      (SELECT cc.type FROM channel_changes cc WHERE cc.channel_id = c.id AND cc.is_read = 0 AND cc.type IN ('added','modified') ORDER BY cc.created_at DESC LIMIT 1) AS pending_change
    FROM channels c
    WHERE c.user_id = ? AND (
      c.status = 'active'
      OR (c.status = 'removed' AND EXISTS (
        SELECT 1 FROM channel_changes cc3
        WHERE cc3.channel_id = c.id AND cc3.type = 'removed' AND cc3.is_read = 0
      ))
    )
";
$params = [$userId];
if ($providerId) {
    $sql .= " AND c.provider_id = ?";
    $params[] = $providerId;
}
$sql .= " ORDER BY c.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$channels = $stmt->fetchAll();

$grouped = [];
foreach ($categories as $cat) {
    $grouped[$cat['id']] = [
        'id' => $cat['id'],
        'name' => $cat['name'],
        'is_new' => in_array((int)$cat['id'], $newCategoryIds, true),
        'channels' => [],
        'has_changes' => false,
    ];
}
foreach ($channels as $ch) {
    $catId = $ch['category_id'];
    if ($catId === null || !isset($grouped[$catId])) {
        continue;
    }
    if ($ch['pending_change']) {
        $grouped[$catId]['has_changes'] = true;
    }
    $grouped[$catId]['channels'][] = $ch;
}

// Solo devolver categorías que tengan al menos un canal en el resultado (evita categorías vacías)
$result = array_values(array_filter($grouped, fn($c) => count($c['channels']) > 0));

$activeCount = 0;
$categoryIdsPresent = [];
$unclassifiedCount = 0;
foreach ($result as $cat) {
    foreach ($cat['channels'] as $ch) {
        if ($ch['status'] === 'active') {
            $activeCount++;
            $categoryIdsPresent[$cat['id']] = true;
            if ($cat['name'] === 'Sin clasificar') {
                $unclassifiedCount++;
            }
        }
    }
}

respond([
    'categories' => $result,
    'meta' => [
        'provider_id' => $providerId,
        'active_channels' => $activeCount,
        'categories_count' => count($categoryIdsPresent),
        'unclassified' => $unclassifiedCount,
    ],
]);
