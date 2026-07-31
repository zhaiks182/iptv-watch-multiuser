<?php
//**
// * Ejecutar vía cron, ej. cada 5 minutos:
// *   */5 * * * * php /var/www/html/iptv-watch/cron/check_providers.php >> /var/www/html/iptv-watch/cron/cron.log 2>&1
// *
// * Revisa qué proveedores ya cumplieron su intervalo (next_check_at <= NOW())
// * y dispara su sincronización.
// */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/Sync.php';
require_once __DIR__ . '/../includes/Telegram.php';

$pdo = get_pdo();
$sync = new Sync();

$stmt = $pdo->query("
    SELECT id, name FROM providers
    WHERE is_active = 1 AND (next_check_at IS NULL OR next_check_at <= NOW())
");
$due = $stmt->fetchAll();

foreach ($due as $provider) {
    try {
        $result = $sync->syncProvider((int)$provider['id']);
        echo sprintf(
            "[%s] OK %s: +%d agregados, -%d eliminados, ~%d modificados\n",
            date('Y-m-d H:i:s'),
            $provider['name'],
            $result['added'],
            $result['removed'],
            $result['modified']
        );
        // Nunca en la primera sincronización de un proveedor (ver
        // includes/Telegram.php) — ahí cada canal se marca "added" y
        // notificar eso mandaría cientos de avisos de golpe.
        if (!$result['was_first_sync'] && !empty($result['events'])) {
            telegram_notify_provider_changes($pdo, $result['user_id'], $result['provider_name'], $result['events']);
        }
    } catch (Throwable $e) {
        echo sprintf("[%s] ERROR %s: %s\n", date('Y-m-d H:i:s'), $provider['name'], $e->getMessage());
    }
}

if (empty($due)) {
    echo sprintf("[%s] Sin proveedores pendientes.\n", date('Y-m-d H:i:s'));
}
