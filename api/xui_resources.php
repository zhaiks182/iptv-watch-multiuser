<?php
/**
 * Recursos de hardware de los servidores del panel XUI·ONE activo (solo lectura).
 * GET -> llama action=get_servers contra la conexión activa guardada en
 * xui_panels y normaliza cpu/ram/disco/red de cada servidor. El API Key
 * nunca sale de este endpoint: se lee de la BD y se usa solo para la
 * llamada saliente al panel.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/XuiClient.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('Método no soportado', 405);
}

$pdo = get_pdo();
$stmt = $pdo->prepare("SELECT * FROM xui_panels WHERE is_active = 1 AND user_id = ? LIMIT 1");
$stmt->execute([(int)$_SESSION['user_id']]);
$panel = $stmt->fetch();
if (!$panel) {
    respond_error('No hay ninguna conexión XUI·ONE activa. Agrega o activa una en "Integración XUI·ONE".', 404);
}

// La sesión ya se validó (bootstrap.php); la cerramos antes de la petición
// de red saliente para no bloquear otras pestañas/peticiones concurrentes.
session_write_close();

$result = xui_call($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'get_servers');
if (!$result['ok']) {
    respond_error('No se pudo conectar al panel XUI·ONE (' . $panel['name'] . '): ' . ($result['error'] ?? ('HTTP ' . $result['http_code'])), 502);
}

$servers = $result['json'];
if (!is_array($servers)) {
    respond_error('El panel no devolvió una lista de servidores válida.', 502);
}

function xui_decode_nested(?string $json): array
{
    if (!$json) {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

$out = [];
foreach ($servers as $s) {
    $hw = xui_decode_nested($s['server_hardware'] ?? null);
    $watchdog = xui_decode_nested($s['watchdog_data'] ?? null);

    $ramTotal = (float)($watchdog['total_mem'] ?? $hw['total_ram'] ?? 0);
    $ramUsed = (float)($watchdog['total_mem_used'] ?? $hw['total_used'] ?? 0);
    $ramPercent = $watchdog['total_mem_used_percent'] ?? ($ramTotal > 0 ? round($ramUsed / $ramTotal * 100, 1) : null);

    $diskTotal = (float)($watchdog['total_disk_space'] ?? 0);
    $diskFree = (float)($watchdog['free_disk_space'] ?? 0);
    $diskUsed = $diskTotal > 0 ? $diskTotal - $diskFree : null;
    $diskPercent = $diskTotal > 0 ? round($diskUsed / $diskTotal * 100, 1) : null;

    $out[] = [
        'id' => $s['id'] ?? null,
        'name' => $s['server_name'] ?? ('Servidor ' . ($s['id'] ?? '?')),
        'domain' => $s['domain_name'] ?? null,
        'ip' => $s['server_ip'] ?? null,
        'is_main' => (bool)($s['is_main'] ?? false),
        'enabled' => (bool)($s['enabled'] ?? false),
        'online' => (($s['status'] ?? '0') === '1'),
        'xui_version' => $s['xui_version'] ?? null,
        'cpu_name' => $watchdog['cpu_name'] ?? $hw['cpu_name'] ?? null,
        'cpu_cores' => $watchdog['cpu_cores'] ?? $hw['cores'] ?? null,
        'cpu_percent' => $watchdog['cpu'] ?? $hw['cpu_usage'] ?? null,
        'cpu_load_average' => $watchdog['cpu_load_average'] ?? null,
        'ram_used_kb' => $ramUsed ?: null,
        'ram_total_kb' => $ramTotal ?: null,
        'ram_percent' => $ramPercent,
        'disk_used_bytes' => $diskUsed,
        'disk_total_bytes' => $diskTotal ?: null,
        'disk_percent' => $diskPercent,
        'network_in_bytes' => $watchdog['bytes_received'] ?? $hw['bytes_received'] ?? null,
        'network_out_bytes' => $watchdog['bytes_sent'] ?? $hw['bytes_sent'] ?? null,
        'running_streams' => $watchdog['total_running_streams'] ?? $hw['total_running_streams'] ?? null,
        'uptime' => $watchdog['uptime'] ?? null,
        'last_check_ago_seconds' => isset($s['last_check_ago']) ? (time() - (int)$s['last_check_ago']) : null,
    ];
}

respond(['panel_name' => $panel['name'], 'servers' => $out]);
