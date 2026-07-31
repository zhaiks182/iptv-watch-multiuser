<?php
/**
 * Crear / listar / renombrar / eliminar canales (streams live) en la
 * conexión XUI·ONE activa.
 *
 * GET -> ya NO devuelve el listado de canales (se quitó del módulo Canales;
 *   ver 🗂️ Categorías XUI·ONE para ver/eliminar canales existentes). Solo
 *   devuelve "servers" + "has_session_login" para el formulario de crear
 *   canal — a propósito NO llama get_streams aquí: llamarlo de más
 *   (paginado, una petición cada 50 canales) volvía lento abrir este
 *   módulo sin necesidad, ya que nada en el frontend usaba esos datos.
 *
 * POST {action:'create', name, source_url, logo, category_ids:[...],
 *        bouquet_ids:[...]}
 *   -> siempre crea con create_stream (type=1, live). Se probó también
 *      'ondemand' (create_movie, type=2) pero esos canales viven en una
 *      acción/listado completamente aparte (get_movies) que generaba
 *      confusión — se descartó esa opción, todo se crea como live.
 *      Verificado en pruebas: stream_source, category_id y bouquets deben
 *      enviarse como ARREGLOS PHP reales (no como string JSON) — con
 *      json_encode() el panel los ignora silenciosamente y los guarda
 *      vacíos. "bouquets" es un parámetro de efecto lateral: no es una
 *      columna del stream, agrega el id del canal recién creado a
 *      bouquet_channels del bouquet indicado.
 *      "stream_all" (Stream All Codecs) siempre se envía en 1 — no es
 *      configurable desde el formulario, se habilita por defecto.
 *      "probesize_ondemand" siempre se envía en 512000 (el default del
 *      panel es 128000) por el mismo motivo.
 *      Tras crear con éxito, se guarda source_url/logo/category_ids en
 *      xui_channel_cache (ver nota sobre "rename" abajo).
 *
 * POST {action:'rename', id, name} -> cambia el nombre. Acción real:
 *   edit_stream con "id" + "stream_display_name". IMPORTANTE: se
 *   verificó que NO es un update parcial — cualquier campo que no se
 *   reenvíe (stream_source, category_id, stream_all) queda BORRADO
 *   (vacío/0) en la respuesta del panel; solo stream_icon sobrevive a una
 *   llamada parcial. "bouquets" es el caso más grave: si se omite, el
 *   canal se saca de TODOS los bouquets en los que estaba (no es aditivo,
 *   es "membresía exacta" — se verificó con dos bouquets a la vez). Por
 *   eso este endpoint reenvía siempre stream_source/category_id/
 *   stream_all/bouquets junto con el nombre nuevo, usando lo guardado en
 *   xui_channel_cache al crear el canal. Si el canal no fue creado con
 *   esta herramienta (no hay caché), se rechaza el renombrado para no
 *   arriesgar borrar su configuración — hay que editarlo directo en el
 *   panel en ese caso.
 *
 * POST {action:'delete', id} -> elimina el canal. Acción real:
 *   delete_stream con parámetro "id". También limpia la fila de
 *   xui_channel_cache si existía.
 *
 * Limitación conocida: no hay forma de listar categorías type=movie/series
 * por esta API (ver api/xui_import.php), así que las categorías del
 * formulario son siempre de tipo "live" únicamente.
 *
 * Servidor + On-Demand: get_streams expone server_id/on_demand, y el panel
 * real SÍ requiere asignar un servidor para que el canal reproduzca
 * (aparece "No Server Selected" si no se hace) — pero se confirmó que
 * create_stream/edit_stream (api_key) aceptan esos campos sin quejarse
 * (STATUS_SUCCESS) y NUNCA los guardan de verdad. La única forma real es la
 * sesión web del panel (usuario/contraseña, ver includes/XuiSession.php).
 * Por eso, si la conexión activa tiene login de sesión guardado (ver
 * api/xui_panels.php) y el formulario manda "server_ids", después de crear
 * el canal por api_key se hace un segundo paso por sesión para asignar esos
 * mismos servidores como "en vivo" y como "on-demand" (se usan los mismos
 * ids para ambos, replicando lo que se hacía a mano). Si la conexión no
 * tiene login de sesión guardado, este paso simplemente se omite (el canal
 * queda creado igual, solo sin la asignación automática).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/XuiClient.php';
require_once __DIR__ . '/../includes/XuiSession.php';

$pdo = get_pdo();
$userId = (int)$_SESSION['user_id'];
$panelStmt = $pdo->prepare("SELECT * FROM xui_panels WHERE is_active = 1 AND user_id = ? LIMIT 1");
$panelStmt->execute([$userId]);
$panel = $panelStmt->fetch();
if (!$panel) {
    respond_error('No hay ninguna conexión XUI·ONE activa. Agrega o activa una en "Integración XUI·ONE".', 404);
}
$hasSessionLogin = !empty($panel['panel_username']) && !empty($panel['panel_password_enc']);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $servers = $hasSessionLogin ? xui_session_list_servers($panel['panel_url'], $panel['access_code'], $panel['api_key']) : [];
    respond(['servers' => $servers, 'has_session_login' => $hasSessionLogin]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('Método no soportado', 405);
}

$body = json_input();
$action = $body['action'] ?? '';

if ($action === 'create') {
    $name = trim($body['name'] ?? '');
    $sourceUrl = trim($body['source_url'] ?? '');
    $logo = trim($body['logo'] ?? '');
    $categoryIds = array_values(array_filter(array_map('intval', $body['category_ids'] ?? [])));
    $bouquetIds = array_values(array_filter(array_map('intval', $body['bouquet_ids'] ?? [])));
    $serverIds = array_values(array_filter(array_map('intval', $body['server_ids'] ?? [])));
    $onDemand = !empty($body['on_demand']);
    $llod = array_key_exists('llod', $body) && $body['llod'] !== '' && $body['llod'] !== null ? (int)$body['llod'] : null;

    if ($name === '') {
        respond_error('El nombre del canal es obligatorio.');
    }
    if ($sourceUrl === '') {
        respond_error('La URL de origen es obligatoria.');
    }
    if (!filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
        respond_error('La URL de origen no es válida.');
    }
    if ($logo !== '' && !filter_var($logo, FILTER_VALIDATE_URL)) {
        respond_error('La URL del logo no es válida.');
    }

    $params = [
        'stream_display_name' => $name,
        'stream_source' => [$sourceUrl],
        'stream_all' => 1, // siempre habilitado por defecto al importar un canal
        'probesize_ondemand' => 512000, // el panel default es 128000; se sube por defecto en vez de dejarlo
    ];
    if ($logo !== '') {
        $params['stream_icon'] = $logo;
    }
    if ($categoryIds) {
        $params['category_id'] = $categoryIds;
    }
    if ($bouquetIds) {
        $params['bouquets'] = $bouquetIds;
    }

    session_write_close();
    $result = xui_call($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'create_stream', $params);

    if (!$result['ok']) {
        respond_error('No se pudo contactar al panel: ' . ($result['error'] ?? ('HTTP ' . $result['http_code'])), 502);
    }
    if (($result['json']['status'] ?? null) !== 'STATUS_SUCCESS') {
        respond_error('El panel no confirmó la creación del canal.', 502);
    }

    $newId = (int)($result['json']['data']['id'] ?? 0);
    if ($newId) {
        $stmt = $pdo->prepare("INSERT INTO xui_channel_cache (user_id, xui_panel_id, stream_id, name, source_url, logo, category_ids, bouquet_ids) VALUES (?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE name=VALUES(name), source_url=VALUES(source_url), logo=VALUES(logo), category_ids=VALUES(category_ids), bouquet_ids=VALUES(bouquet_ids)");
        $stmt->execute([$userId, $panel['id'], $newId, $name, $sourceUrl, $logo !== '' ? $logo : null, $categoryIds ? implode(',', $categoryIds) : null, $bouquetIds ? implode(',', $bouquetIds) : null]);
    }

    $serverWarning = null;
    $serverAssigned = false;
    if ($newId && $serverIds) {
        $assign = xui_session_assign_using_panel($panel, $newId, $serverIds, $onDemand, $llod);
        if (!$assign['ok']) {
            $serverWarning = 'El canal se creó, pero no se pudo asignar servidor/on-demand: ' . $assign['error'];
        } else {
            $serverAssigned = true;
        }
    }

    respond(['ok' => true, 'channel' => $result['json']['data'] ?? null, 'server_warning' => $serverWarning, 'server_assigned' => $serverAssigned], 201);
}

if ($action === 'rename') {
    $id = (int)($body['id'] ?? 0);
    $name = trim($body['name'] ?? '');
    if (!$id) {
        respond_error('Falta el id a renombrar.');
    }
    if ($name === '') {
        respond_error('El nombre no puede quedar vacío.');
    }

    $cacheStmt = $pdo->prepare("SELECT * FROM xui_channel_cache WHERE xui_panel_id = ? AND stream_id = ?");
    $cacheStmt->execute([$panel['id'], $id]);
    $cached = $cacheStmt->fetch();

    if (!$cached) {
        respond_error('Este canal no fue creado con esta herramienta, así que no tenemos su URL de origen guardada. Editar el nombre aquí borraría su fuente y categoría en el panel — cámbialo directamente desde XUI·ONE.', 409);
    }

    $params = [
        'id' => $id,
        'stream_display_name' => $name,
        'stream_source' => [$cached['source_url']],
        'stream_all' => 1,
        'probesize_ondemand' => 512000,
    ];
    if (!empty($cached['logo'])) {
        $params['stream_icon'] = $cached['logo'];
    }
    if (!empty($cached['category_ids'])) {
        $params['category_id'] = array_map('intval', explode(',', $cached['category_ids']));
    }
    if (!empty($cached['bouquet_ids'])) {
        $params['bouquets'] = array_map('intval', explode(',', $cached['bouquet_ids']));
    }

    session_write_close();
    $result = xui_call($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'edit_stream', $params);

    if (!$result['ok']) {
        respond_error('No se pudo contactar al panel: ' . ($result['error'] ?? ('HTTP ' . $result['http_code'])), 502);
    }
    if (($result['json']['status'] ?? null) !== 'STATUS_SUCCESS') {
        respond_error('El panel no confirmó el cambio de nombre.', 502);
    }

    $pdo->prepare("UPDATE xui_channel_cache SET name = ? WHERE xui_panel_id = ? AND stream_id = ?")
        ->execute([$name, $panel['id'], $id]);

    respond(['ok' => true]);
}

if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) {
        respond_error('Falta el id a eliminar.');
    }

    session_write_close();
    $result = xui_call($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'delete_stream', [
        'id' => $id,
    ]);

    if (!$result['ok']) {
        respond_error('No se pudo contactar al panel: ' . ($result['error'] ?? ('HTTP ' . $result['http_code'])), 502);
    }
    if (($result['json']['status'] ?? null) !== 'STATUS_SUCCESS') {
        respond_error('El panel no confirmó la eliminación del canal.', 502);
    }

    $del = $pdo->prepare("DELETE FROM xui_channel_cache WHERE xui_panel_id = ? AND stream_id = ?");
    $del->execute([$panel['id'], $id]);

    respond(['ok' => true]);
}

respond_error('Acción no soportada.', 400);
