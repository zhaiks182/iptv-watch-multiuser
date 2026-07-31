<?php
/**
 * Notificaciones por Telegram cuando un proveedor detecta cambios reales.
 * Configuración: tabla telegram_settings (una fila POR USUARIO, user_id
 * como llave primaria), bot_token cifrado con includes/Crypto.php (misma
 * llave que la sesión de XUI·ONE).
 *
 * IMPORTANTE: solo se notifica cuando el proveedor YA había sido
 * sincronizado antes (ver includes/Sync.php -> "was_first_sync"). La
 * primera sincronización de un proveedor recién agregado marca CADA canal
 * como "added" (no hay nada previo con qué compararlo) — notificar eso
 * mandaría un mensaje con cientos de canales "nuevos" de una sola vez, así
 * que esa primera corrida nunca llega a esta función (los llamadores
 * filtran por was_first_sync antes de invocarla).
 */

/**
 * Se probó primero con file_get_contents()/stream_context_create() (mismo
 * patrón que includes/XuiClient.php) y resultó INCONSISTENTE contra la API
 * de Telegram en este servidor: de 5 intentos seguidos, 2 fallaron con "no
 * se pudo conectar" y uno se quedó colgado más de 30s pese al timeout
 * configurado. curl, en cambio, respondió bien en todas las pruebas
 * directas — por eso esta función usa curl (ya es una dependencia
 * obligatoria del proyecto, no agrega nada nuevo) con timeouts reales
 * (CURLOPT_CONNECTTIMEOUT/CURLOPT_TIMEOUT, que si se cumplen) y un
 * reintento automático ante fallos de conexión, para no perder avisos si
 * varios proveedores detectan cambios casi al mismo tiempo.
 */
function telegram_send_message(string $botToken, string $chatId, string $text): array
{
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $postFields = http_build_query([
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ]);

    $lastError = 'No se pudo conectar con la API de Telegram.';

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            $lastError = $curlErr !== '' ? $curlErr : $lastError;
            usleep(300000); // 300ms antes de reintentar — suficiente para que un fallo transitorio de red se resuelva
            continue;
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['ok'])) {
            return ['ok' => false, 'error' => $json['description'] ?? ('Telegram rechazó el mensaje (HTTP ' . $httpCode . ').')];
        }
        return ['ok' => true];
    }

    return ['ok' => false, 'error' => $lastError];
}

/**
 * Devuelve la config activa (enabled=1) de ESE usuario, o null si no tiene
 * ninguna / está deshabilitada. No descifra el token aquí — cada llamador
 * decide si lo necesita (telegram_notify_provider_changes sí lo descifra
 * para enviar).
 */
function telegram_get_config(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM telegram_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row || empty($row['enabled'])) {
        return null;
    }
    return $row;
}

/**
 * $events: arreglo de ['type'=>'added'|'modified'|'removed'|'category_added',
 * 'old_name'=>?, 'new_name'=>?, 'detail'=>?] tal como los junta
 * Sync::syncProvider() durante una corrida. No lanza excepciones: un fallo
 * de Telegram (red caída, token inválido) nunca debe romper la sincronización
 * en sí, que ya terminó exitosamente cuando esto se llama.
 */
function telegram_notify_provider_changes(PDO $pdo, int $userId, string $providerName, array $events): void
{
    if (!$events) {
        return;
    }
    $config = telegram_get_config($pdo, $userId);
    if (!$config) {
        return;
    }

    require_once __DIR__ . '/Crypto.php';
    $botToken = xui_decrypt($config['bot_token_enc']);
    if (!$botToken) {
        return;
    }

    // Nombres de categoría por id, para mostrar "Categoría: X" en cada
    // entrada igual que el panel (los eventos solo traen category_id, ver
    // includes/Sync.php) — una sola consulta para todo el lote.
    $categoryIds = array_values(array_unique(array_filter(array_map(fn($e) => $e['category_id'] ?? null, $events))));
    $categoryNames = [];
    if ($categoryIds) {
        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
        $stmt = $pdo->prepare("SELECT id, name FROM categories WHERE user_id = ? AND id IN ($placeholders)");
        $stmt->execute(array_merge([$userId], $categoryIds));
        foreach ($stmt->fetchAll() as $row) {
            $categoryNames[(int)$row['id']] = $row['name'];
        }
    }

    $counts = ['added' => 0, 'modified' => 0, 'removed' => 0, 'category_added' => 0];
    foreach ($events as $e) {
        $counts[$e['type']] = ($counts[$e['type']] ?? 0) + 1;
    }

    $lines = ['📡<b>' . htmlspecialchars($providerName, ENT_QUOTES) . '</b> tuvo cambios:'];
    if ($counts['added']) {
        $lines[] = "🟢{$counts['added']} canal agregado";
    }
    if ($counts['modified']) {
        $lines[] = "🟡{$counts['modified']} canal modificado";
    }
    if ($counts['removed']) {
        $lines[] = "🔴{$counts['removed']} canal eliminado";
    }
    if ($counts['category_added']) {
        $lines[] = "🗂️{$counts['category_added']} categoría nueva";
    }

    // Detalle estructurado (canal + categoría + qué cambió) de hasta 15
    // entradas, para no exceder el límite de Telegram (4096 caracteres) en
    // proveedores con muchos cambios de golpe.
    $icons = ['added' => '🟢', 'modified' => '🟡', 'removed' => '🔴', 'category_added' => '🗂️'];
    $labels = ['added' => 'Nuevo', 'modified' => 'Modificado', 'removed' => 'Eliminado', 'category_added' => 'Categoría nueva'];
    $detailBlocks = [];
    foreach (array_slice($events, 0, 15) as $e) {
        $name = $e['new_name'] ?? $e['old_name'] ?? '(sin nombre)';
        $icon = $icons[$e['type']] ?? '•';
        $label = $labels[$e['type']] ?? $e['type'];
        $block = $icon . '<b>' . $label . '</b> — ' . htmlspecialchars($name, ENT_QUOTES);

        // "category_added" ya ES la categoría (se muestra en el nombre de
        // arriba), así que no repite una línea de categoría aparte.
        if ($e['type'] !== 'category_added' && !empty($e['category_id']) && isset($categoryNames[$e['category_id']])) {
            $block .= "\n   📁Categoría: " . htmlspecialchars($categoryNames[$e['category_id']], ENT_QUOTES);
        }
        if ($e['type'] === 'modified' && !empty($e['detail'])) {
            $block .= "\n   ✏️" . htmlspecialchars($e['detail'], ENT_QUOTES);
        }
        $detailBlocks[] = $block;
    }
    if ($detailBlocks) {
        $lines[] = '';
        $lines[] = implode("\n\n", $detailBlocks);
        if (count($events) > 15) {
            $lines[] = '';
            $lines[] = '… y ' . (count($events) - 15) . ' más.';
        }
    }

    telegram_send_message($botToken, $config['chat_id'], implode("\n", $lines));
}
