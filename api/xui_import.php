<?php
/**
 * Flujo de importación hacia XUI·ONE — acciones que SÍ escriben en el panel.
 * A diferencia de xui_test.php (solo lectura), aquí cada acción arma sus
 * propios parámetros completos y los valida ANTES de enviarlos: se
 * comprobó en pruebas que XUI·ONE no valida nada del lado del panel
 * (acepta category_name vacío, category_type inventado, nombres duplicados,
 * todo sin quejarse ni devolver error), así que la validación vive
 * enteramente en este archivo.
 *
 * GET
 *   -> lista las categorías (siempre type=live, ver limitación abajo)
 *      ordenadas por cat_order, para pintar el editor de orden.
 *
 * POST {action:'create_category', name, type}
 *   -> crea una categoría en la conexión XUI·ONE activa. type debe ser
 *      'live', 'movie' o 'series'.
 *
 * POST {action:'reorder_categories', order:[id, id, ...]}
 *   -> re-numera cat_order según la posición de cada id en el arreglo
 *      (1, 2, 3...). La acción real del panel es "edit_category" — no
 *      "update_category" (no existe) — y exige el parámetro "id", no
 *      "category_id" (con "category_id" responde STATUS_FAILURE sin
 *      más detalle). Se reenvían name/type/parent_id tal cual los
 *      devolvió el panel justo antes, para no perder esos datos al
 *      cambiar solo el orden.
 *
 * POST {action:'rename_category', id, name}
 *   -> cambia el nombre de una categoría. Misma acción real que
 *      reorder_categories ("edit_category" con "id", no "category_id") —
 *      no es un update parcial, así que se relee la categoría actual justo
 *      antes para reenviar type/parent_id/cat_order tal cual y no perderlos
 *      al cambiar solo el nombre. Como get_categories solo trae type=live
 *      (ver limitación abajo), esto solo funciona para categorías live,
 *      igual que "Ordenar categorías".
 *
 * POST {action:'get_channel_ids_by_category'}
 *   -> trae get_streams UNA SOLA VEZ (recorriendo todas las páginas con
 *      xui_call_all_pages) y agrupa los ids de canal por category_id.
 *      Devuelve {by_category: {"12": [101,102,...], ...}, uncategorized:
 *      [ids...]}. "uncategorized" son los streams cuyo category_id vino
 *      vacío ([] o null) — no pertenecen a NINGUNA categoría, así que
 *      "delete_category" (aunque sea con cascade) nunca los toca; son los
 *      que el panel suele mostrar bajo "No Category". Pensado para el
 *      flujo de "eliminar todas las categorías" y para "canales sin
 *      categoría": el frontend llama esto una vez y luego usa el resultado
 *      para pasarle "stream_ids" ya calculados a cada llamada de
 *      delete_category / delete_uncategorized (ver abajo), en vez de que
 *      cada una repita esa misma lectura paginada — eso fue lo que hacía
 *      lento el borrado masivo antes. Se probó devolver el progreso en
 *      streaming (NDJSON con flush() por categoría) para que el frontend lo
 *      pintara en vivo, pero este servidor sirve PHP vía Apache +
 *      mod_proxy_fcgi, que bufferiza toda la respuesta hasta que el script
 *      termina sin importar los flush() — confirmado con una prueba real
 *      (una respuesta que debía llegar en 4 partes espaciadas 0.7s llegó
 *      toda junta al final). La solución (agregar flushpackets=on a la
 *      config de PHP-FPM en Apache) se descartó por tocar configuración
 *      global del servidor. Por eso el progreso en vivo se logra con muchas
 *      peticiones normales (una por categoría) en vez de una sola respuesta
 *      en streaming.
 *
 * POST {action:'delete_uncategorized', stream_ids:[...]}
 *   -> borra (delete_stream, en paralelo) los streams indicados que NO
 *      pertenecen a ninguna categoría. "stream_ids" viene de
 *      get_channel_ids_by_category (campo "uncategorized") — este endpoint
 *      no vuelve a leer get_streams, para no repetir esa lectura paginada.
 *      Devuelve deleted_channels con la cuenta.
 *
 * POST {action:'delete_category', id, cascade:false, stream_ids:[...]}
 *   -> elimina una categoría. La acción real es "delete_category" con
 *      parámetro "id" (con "category_id" responde STATUS_FAILURE, igual
 *      que edit_category). Es irreversible del lado del panel — no hay
 *      papelera ni confirmación en la API misma, por eso el frontend pide
 *      confirmación antes de llamar esto.
 *      Por defecto, al borrar una categoría el panel deja los canales que
 *      tenía asignados SIN categoría (no los borra). Si cascade:true, este
 *      endpoint elimina también esos canales antes de borrar la categoría.
 *      Si se manda "stream_ids" (array de ids), se usan tal cual y NO se
 *      vuelve a llamar get_streams; si no se manda, cascade hace su propia
 *      llamada a get_streams (recorriendo todas las páginas) para
 *      encontrarlos, como antes. Los delete_stream de cada canal se mandan en
 *      paralelo (xui_call_batch, ver includes/XuiClient.php) en vez de uno
 *      por uno — no existe un "borrar varios" en la API de XUI·ONE, así que
 *      esto era lo que hacía lento borrar categorías con muchos canales.
 *      Devuelve deleted_channels con la cuenta.
 *
 * Limitación conocida del panel (verificada en pruebas): get_categories
 * solo devuelve categorías type=live sin importar los parámetros — no hay
 * forma de listar ni reordenar categorías movie/series por esta API. Una
 * categoría movie/series recién creada con create_category no se puede
 * confirmar por API, solo verificando en el panel directamente.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/XuiClient.php';

set_time_limit(600); // borrar todas las categorías puede implicar cientos de delete_stream uno por uno

$pdo = get_pdo();
$panelStmt = $pdo->prepare("SELECT * FROM xui_panels WHERE is_active = 1 AND user_id = ? LIMIT 1");
$panelStmt->execute([(int)$_SESSION['user_id']]);
$panel = $panelStmt->fetch();
if (!$panel) {
    respond_error('No hay ninguna conexión XUI·ONE activa. Agrega o activa una en "Integración XUI·ONE".', 404);
}

const XUI_CATEGORY_TYPES = ['live', 'movie', 'series'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $result = xui_call($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'get_categories');
    if (!$result['ok']) {
        respond_error('No se pudo obtener las categorías: ' . ($result['error'] ?? ('HTTP ' . $result['http_code'])), 502);
    }
    $categories = is_array($result['json']) ? $result['json'] : [];
    usort($categories, fn($a, $b) => ((int)($a['cat_order'] ?? 0)) <=> ((int)($b['cat_order'] ?? 0)));
    respond(['categories' => $categories]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('Método no soportado', 405);
}

$body = json_input();
$action = $body['action'] ?? '';

if ($action === 'create_category') {
    $name = trim($body['name'] ?? '');
    $type = trim($body['type'] ?? '');

    if ($name === '') {
        respond_error('El nombre de la categoría es obligatorio.');
    }
    if (!in_array($type, XUI_CATEGORY_TYPES, true)) {
        respond_error('Tipo de categoría inválido. Debe ser una de: ' . implode(', ', XUI_CATEGORY_TYPES) . '.');
    }

    session_write_close();
    $result = xui_call($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'create_category', [
        'category_name' => $name,
        'category_type' => $type,
        'parent_id' => 0,
    ]);

    if (!$result['ok']) {
        respond_error('No se pudo contactar al panel: ' . ($result['error'] ?? ('HTTP ' . $result['http_code'])), 502);
    }
    if (($result['json']['status'] ?? null) !== 'STATUS_SUCCESS') {
        respond_error('El panel no confirmó la creación de la categoría.', 502);
    }

    respond(['ok' => true, 'category' => $result['json']['data'] ?? null], 201);
}

if ($action === 'reorder_categories') {
    $order = $body['order'] ?? null;
    if (!is_array($order) || empty($order)) {
        respond_error('Falta la lista de orden (order: [ids]).');
    }
    $order = array_values(array_unique(array_map('intval', $order)));

    session_write_close();

    // Se relee la lista actual justo antes de reordenar (en vez de confiar
    // en lo que mandó el navegador) para no pisar name/type/parent_id con
    // datos viejos si alguien más editó una categoría mientras tanto.
    $listResult = xui_call($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'get_categories');
    if (!$listResult['ok'] || !is_array($listResult['json'])) {
        respond_error('No se pudo leer la lista actual de categorías antes de reordenar.', 502);
    }
    $current = [];
    foreach ($listResult['json'] as $cat) {
        $current[(int)$cat['id']] = $cat;
    }

    $missing = array_diff($order, array_keys($current));
    if ($missing) {
        respond_error('Estos ids ya no existen en el panel: ' . implode(', ', $missing) . '. Vuelve a cargar la lista.', 409);
    }

    $failed = [];
    $position = 1;
    foreach ($order as $id) {
        $cat = $current[$id];
        $result = xui_call($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'edit_category', [
            'id' => $id,
            'category_name' => $cat['category_name'],
            'category_type' => $cat['category_type'],
            'parent_id' => $cat['parent_id'],
            'cat_order' => $position,
        ]);
        if (!$result['ok'] || ($result['json']['status'] ?? null) !== 'STATUS_SUCCESS') {
            $failed[] = ($cat['category_name'] ?? '(sin nombre)') . ' (id ' . $id . ')';
        }
        $position++;
    }

    if ($failed) {
        respond_error('No se pudo reordenar: ' . implode(', ', $failed), 502);
    }

    respond(['ok' => true]);
}

if ($action === 'rename_category') {
    $id = (int)($body['id'] ?? 0);
    $name = trim($body['name'] ?? '');
    if (!$id) {
        respond_error('Falta el id de la categoría.');
    }
    if ($name === '') {
        respond_error('El nombre no puede quedar vacío.');
    }

    session_write_close();

    // edit_category no es un update parcial (mismo patrón que create/reorder
    // de arriba) — se relee la categoría actual justo antes para no perder
    // type/parent_id/cat_order al cambiar solo el nombre.
    $listResult = xui_call($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'get_categories');
    if (!$listResult['ok'] || !is_array($listResult['json'])) {
        respond_error('No se pudo leer la categoría actual antes de renombrar.', 502);
    }
    $current = null;
    foreach ($listResult['json'] as $cat) {
        if ((int)$cat['id'] === $id) {
            $current = $cat;
            break;
        }
    }
    if (!$current) {
        respond_error('Esta categoría ya no existe en el panel. Vuelve a cargar la lista.', 409);
    }

    $result = xui_call($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'edit_category', [
        'id' => $id,
        'category_name' => $name,
        'category_type' => $current['category_type'],
        'parent_id' => $current['parent_id'],
        'cat_order' => $current['cat_order'],
    ]);

    if (!$result['ok']) {
        respond_error('No se pudo contactar al panel: ' . ($result['error'] ?? ('HTTP ' . $result['http_code'])), 502);
    }
    if (($result['json']['status'] ?? null) !== 'STATUS_SUCCESS') {
        respond_error('El panel no confirmó el cambio de nombre.', 502);
    }

    respond(['ok' => true, 'category_name' => $name]);
}

if ($action === 'get_channel_ids_by_category') {
    session_write_close();

    // xui_call_all_pages(): get_streams viene paginado a 50 fijos sin
    // importar "length" — sin esto, canales más allá de la primera página
    // no se detectarían. Se trae UNA SOLA VEZ y se agrupa localmente por
    // category_id, para que el frontend se lo pase de vuelta a cada llamada
    // de delete_category (stream_ids) sin repetir esta lectura por categoría.
    $streamsResult = xui_call_all_pages($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'get_streams');
    if (!$streamsResult['ok']) {
        respond_error('No se pudo obtener los canales del panel.', 502);
    }

    $channelsByCategory = [];
    $uncategorized = [];
    foreach (($streamsResult['json']['data'] ?? []) as $s) {
        $catIds = json_decode($s['category_id'] ?? '[]', true) ?: [];
        if (!$catIds) {
            $uncategorized[] = (int)$s['id'];
            continue;
        }
        foreach ($catIds as $catId) {
            $channelsByCategory[(string)$catId][] = (int)$s['id'];
        }
    }

    respond(['ok' => true, 'by_category' => $channelsByCategory, 'uncategorized' => $uncategorized]);
}

if ($action === 'delete_uncategorized') {
    $streamIds = array_values(array_filter(array_map('intval', $body['stream_ids'] ?? [])));
    if (!$streamIds) {
        respond_error('Falta stream_ids (usa get_channel_ids_by_category para calcularlos).');
    }

    session_write_close();

    // xui_call_batch(): igual que en delete_category, no existe un "borrar
    // varios" en la API — se manda en paralelo en vez de uno por uno.
    $paramsList = array_map(fn($streamId) => ['id' => $streamId], $streamIds);
    $delResults = xui_call_batch($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'delete_stream', $paramsList);
    $delStmt = $pdo->prepare("DELETE FROM xui_channel_cache WHERE xui_panel_id = ? AND stream_id = ?");
    $deletedChannels = 0;
    foreach ($streamIds as $i => $streamId) {
        if (($delResults[$i]['json']['status'] ?? null) === 'STATUS_SUCCESS') {
            $deletedChannels++;
            $delStmt->execute([$panel['id'], $streamId]);
        }
    }

    respond(['ok' => true, 'deleted_channels' => $deletedChannels, 'total' => count($streamIds)]);
}

if ($action === 'delete_category') {
    $id = (int)($body['id'] ?? 0);
    $cascade = !empty($body['cascade']);
    if (!$id) {
        respond_error('Falta el id a eliminar.');
    }

    session_write_close();

    $deletedChannels = 0;
    if ($cascade) {
        if (isset($body['stream_ids']) && is_array($body['stream_ids'])) {
            // Ya calculados por el llamador (ver get_channel_ids_by_category)
            // — se evita repetir get_streams por cada categoría al borrar
            // varias seguidas.
            $toDelete = array_values(array_filter(array_map('intval', $body['stream_ids'])));
        } else {
            // xui_call_all_pages(): get_streams viene paginado a 50 fijos sin
            // importar "length" — sin esto, canales de la categoría más allá
            // de la primera página no se detectaban ni se borraban.
            $streamsResult = xui_call_all_pages($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'get_streams');

            $toDelete = [];
            foreach (($streamsResult['json']['data'] ?? []) as $s) {
                $ids = json_decode($s['category_id'] ?? '[]', true) ?: [];
                if (in_array((string)$id, array_map('strval', $ids), true)) {
                    $toDelete[] = (int)$s['id'];
                }
            }
        }

        // xui_call_batch(): no existe un "borrar varios" en la API de
        // XUI·ONE — hay que llamar delete_stream una vez por canal, pero se
        // hace en paralelo (varias conexiones a la vez) en vez de una por
        // una, que es lo que hacía lento borrar categorías con muchos
        // canales.
        $paramsList = array_map(fn($streamId) => ['id' => $streamId], $toDelete);
        $delResults = xui_call_batch($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'delete_stream', $paramsList);
        $delStmt = $pdo->prepare("DELETE FROM xui_channel_cache WHERE xui_panel_id = ? AND stream_id = ?");
        foreach ($toDelete as $i => $streamId) {
            if (($delResults[$i]['json']['status'] ?? null) === 'STATUS_SUCCESS') {
                $deletedChannels++;
                $delStmt->execute([$panel['id'], $streamId]);
            }
        }
    }

    $result = xui_call($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'delete_category', [
        'id' => $id,
    ]);

    if (!$result['ok']) {
        respond_error('No se pudo contactar al panel: ' . ($result['error'] ?? ('HTTP ' . $result['http_code'])), 502);
    }
    if (($result['json']['status'] ?? null) !== 'STATUS_SUCCESS') {
        respond_error('El panel no confirmó la eliminación de la categoría.', 502);
    }

    respond(['ok' => true, 'deleted_channels' => $deletedChannels]);
}

respond_error('Acción no soportada.', 400);
