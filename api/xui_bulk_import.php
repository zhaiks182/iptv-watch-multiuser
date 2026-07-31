<?php
/**
 * Importación masiva: toma una categoría de un proveedor M3U ya
 * sincronizado (tablas locales categories/channels) y crea cada uno de
 * sus canales activos como stream en la conexión XUI·ONE activa.
 *
 * No existe una acción real de "importar M3U completo" en la API pública
 * de XUI·ONE (se probaron 13 nombres candidatos — import_m3u, bulk_import,
 * m3u_import, etc. — todos "Invalid action"), así que esto clasifica los
 * canales localmente primero (sin llamar al panel) y recién después manda
 * create_stream/edit_stream EN TANDAS (varias conexiones simultáneas, ver
 * includes/XuiClient.php::xui_call_batch) en vez de uno por uno — se probó
 * con datos reales (20 canales) que el panel los atiende en paralelo sin
 * mezclar datos entre canales, con velocidades de 5-7x sobre hacerlo en
 * serie. La asignación de servidor/on-demand (ver más abajo) también se
 * hace en una sola tanda al final, cubriendo todos los canales tocados.
 *
 * POST {action:'import_category', category_id, provider_id, bouquet_id}
 *   1. Busca la categoría local (nombre) y sus canales activos
 *      (provider_id + category_id, status='active').
 *   2. Busca una categoría XUI·ONE type=live con el mismo nombre
 *      (comparación case-insensitive); si no existe, la crea.
 *   3. Por cada canal: si su stream_url YA está en xui_channel_cache para
 *      esta conexión (de una importación anterior), NO se vuelve a crear
 *      — evita duplicar canales al volver a importar la misma categoría.
 *      La API de XUI·ONE no valida duplicados (get_streams ni siquiera
 *      devuelve stream_source para poder comparar del lado del panel),
 *      así que la única forma confiable de detectarlos es contra lo que
 *      esta misma herramienta ya creó antes.
 *      - Si el nombre local es igual al que ya estaba cacheado: no se
 *        toca (evita llamadas innecesarias en categorías grandes).
 *      - Si el nombre local CAMBIÓ (ej. el proveedor renombró "TCM" a
 *        "TCM !!"): se actualiza con edit_stream, reenviando también
 *        stream_source/category_id/stream_all/bouquets (edit_stream no es
 *        un update parcial — y "bouquets" es el caso más grave: si se
 *        omite, el canal se saca de TODOS los bouquets en los que estaba,
 *        no es aditivo, es "membresía exacta". Por eso se reenvía la
 *        unión de los bouquets ya cacheados + el bouquet elegido en esta
 *        importación, en vez de solo el nuevo).
 *   4. Si no está cacheada: create_stream con stream_display_name,
 *      stream_source (stream_url del canal), stream_icon (logo_url si
 *      existe), category_id=[xui_category_id], stream_all=1, y
 *      bouquets=[bouquet_id] si se indicó uno. Se cachea (con su nombre,
 *      provider_id y bouquet_ids) en xui_channel_cache para poder
 *      renombrarlo después sin perder sus bouquets, y para detectar
 *      duplicados/cambios en la próxima importación.
 *   5. Limpieza: cualquier canal que esta MISMA herramienta haya creado
 *      antes para este proveedor+categoría (por provider_id + category_ids
 *      en xui_channel_cache) pero que YA NO esté activo localmente (el
 *      proveedor lo quitó de su M3U) se borra también de XUI·ONE
 *      (delete_stream) y de la caché. Se filtra por provider_id para no
 *      tocar canales de otro proveedor que comparta el mismo nombre de
 *      categoría.
 *
 *   Nota sobre "sin cambios" + server_ids: si el canal ya estaba
 *   importado y el nombre no cambió, esta herramienta no llama
 *   create_stream/edit_stream (para no gastar tiempo en categorías
 *   grandes) — pero si se pidió server_ids, igual se revisa (con UNA sola
 *   lectura de get_streams para todo el lote) si a ESE canal ya se le
 *   asignó servidor/on-demand antes; si no, se asigna igual. Así,
 *   reimportar una categoría ya importada SÍ corrige canales a los que
 *   les faltaba la asignación (por haberse creado antes de tener esta
 *   función, por ejemplo).
 *   Devuelve {ok, xui_category_id, xui_category_name, created, updated,
 *   unchanged, removed, failed:[...], server_assign_failed}.
 *
 * Puede tardar bastante en categorías grandes (una llamada HTTP por canal,
 * hasta 15s de timeout cada una) — se sube max_execution_time para esta
 * request específica.
 *
 * Servidor + On-Demand (opcional, "server_ids": [...]): create_stream/
 * edit_stream (api_key) no guardan de verdad server_id/on_demand (ver nota
 * en api/xui_channels.php) — la única forma real es la sesión web del panel
 * (includes/XuiSession.php). Si la conexión activa tiene login de sesión
 * guardado y se manda server_ids: se junta la lista de TODOS los canales
 * que necesitan la asignación (creados + renombrados + "sin cambios" a los
 * que les faltaba) y se procesa en UNA sola tanda al final
 * (xui_session_assign_batch_using_panel: un solo login, lecturas de
 * formulario en paralelo, guardados en paralelo) — en vez de repetir
 * login+lectura+guardado canal por canal.
 *
 * Low Latency On-Demand (opcional, "llod": 0|1|2, requiere server_ids):
 * mismo mecanismo de sesión que servidor/on-demand (ver includes/
 * XuiSession.php). A diferencia de server_id/on_demand, get_streams NO
 * expone el valor actual de "llod" — no hay forma de saber si un canal
 * "sin cambios" ya lo tiene, así que si se manda "llod" se reasigna SIEMPRE
 * a todos los canales tocados (creados + renombrados + sin cambios), no
 * solo a los que "necesitaban" asignación de servidor.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/XuiClient.php';
require_once __DIR__ . '/../includes/XuiSession.php';

set_time_limit(600);

$pdo = get_pdo();
$userId = (int)$_SESSION['user_id'];
$panelStmt = $pdo->prepare("SELECT * FROM xui_panels WHERE is_active = 1 AND user_id = ? LIMIT 1");
$panelStmt->execute([$userId]);
$panel = $panelStmt->fetch();
if (!$panel) {
    respond_error('No hay ninguna conexión XUI·ONE activa. Agrega o activa una en "Integración XUI·ONE".', 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hasSessionLogin = !empty($panel['panel_username']) && !empty($panel['panel_password_enc']);
    $servers = $hasSessionLogin ? xui_session_list_servers($panel['panel_url'], $panel['access_code'], $panel['api_key']) : [];
    respond(['servers' => $servers, 'has_session_login' => $hasSessionLogin]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('Método no soportado', 405);
}

$body = json_input();
$action = $body['action'] ?? '';

if ($action === 'import_category') {
    $categoryId = (int)($body['category_id'] ?? 0);
    $providerId = (int)($body['provider_id'] ?? 0);
    $bouquetId = (int)($body['bouquet_id'] ?? 0);
    $serverIds = array_values(array_filter(array_map('intval', $body['server_ids'] ?? [])));
    $onDemand = !empty($body['on_demand']);
    $llod = array_key_exists('llod', $body) && $body['llod'] !== '' && $body['llod'] !== null ? (int)$body['llod'] : null;

    if (!$categoryId || !$providerId) {
        respond_error('Falta category_id o provider_id.');
    }

    $catStmt = $pdo->prepare("SELECT id, name FROM categories WHERE id = ? AND user_id = ?");
    $catStmt->execute([$categoryId, $userId]);
    $localCategory = $catStmt->fetch();
    if (!$localCategory) {
        respond_error('Categoría local no encontrada.', 404);
    }

    $chStmt = $pdo->prepare("SELECT id, name, stream_url, logo_url FROM channels WHERE provider_id = ? AND category_id = ? AND status = 'active' AND user_id = ? ORDER BY name ASC");
    $chStmt->execute([$providerId, $categoryId, $userId]);
    $localChannels = $chStmt->fetchAll();

    if (!$localChannels) {
        respond_error('Esta categoría no tiene canales activos para este proveedor.', 400);
    }

    session_write_close();

    // Cuántas conexiones simultáneas usar para crear/renombrar/asignar en
    // tandas (ver includes/XuiClient.php y includes/XuiSession.php) — se
    // probó con datos reales hasta 20 a la vez sin errores ni mezcla de
    // datos entre canales; 15 se usa como punto medio.
    $concurrency = 15;

    // Estado actual de server_id/on_demand por canal (UNA sola lectura para
    // todo el lote) — permite saltar la asignación en canales "sin cambios"
    // que ya la tienen, y corregirla en los que no.
    $currentServerState = [];
    if ($serverIds && !empty($panel['panel_username']) && !empty($panel['panel_password_enc'])) {
        $streamsNow = xui_call_all_pages($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'get_streams');
        foreach (($streamsNow['json']['data'] ?? []) as $s) {
            $currentServerState[(string)$s['id']] = [
                'server_id' => $s['server_id'] ?? null,
                'on_demand' => $s['on_demand'] ?? null,
            ];
        }
    }
    // get_streams no expone el valor actual de "llod" (se comprobó: no está
    // entre sus claves, a diferencia de server_id/on_demand) — así que no hay
    // forma de saber si un canal "sin cambios" ya tiene el LLOD pedido. Si se
    // pidió un valor de llod, se reasigna siempre (no se puede confiar en caché).
    $xuiChannelNeedsAssign = function (int $streamId) use (&$currentServerState, $onDemand, $llod): bool {
        if ($llod !== null) {
            return true;
        }
        $state = $currentServerState[(string)$streamId] ?? null;
        if (!$state || empty($state['server_id'])) {
            return true;
        }
        if ($onDemand && empty($state['on_demand'])) {
            return true;
        }
        return false;
    };

    // Paso 1: buscar o crear la categoría XUI·ONE con el mismo nombre.
    $existing = xui_call($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'get_categories');
    if (!$existing['ok']) {
        respond_error('No se pudo consultar las categorías del panel: ' . ($existing['error'] ?? ('HTTP ' . $existing['http_code'])), 502);
    }
    $xuiCategoryId = null;
    $xuiCategoryName = $localCategory['name'];
    foreach (($existing['json'] ?? []) as $c) {
        if (mb_strtolower(trim($c['category_name'] ?? '')) === mb_strtolower(trim($localCategory['name']))) {
            $xuiCategoryId = (int)$c['id'];
            $xuiCategoryName = $c['category_name'];
            break;
        }
    }
    if (!$xuiCategoryId) {
        $created = xui_call($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'create_category', [
            'category_name' => $localCategory['name'],
            'category_type' => 'live',
            'parent_id' => 0,
        ]);
        if (!$created['ok'] || ($created['json']['status'] ?? null) !== 'STATUS_SUCCESS') {
            respond_error('No se pudo crear la categoría "' . $localCategory['name'] . '" en XUI·ONE.', 502);
        }
        $xuiCategoryId = (int)($created['json']['data']['id'] ?? 0);
    }

    // Paso 2: crear/actualizar/omitir cada canal según lo que ya haya en caché
    // (solo lo que esta misma herramienta cacheó para este proveedor).
    $existingStmt = $pdo->prepare("SELECT stream_id, name, source_url, bouquet_ids FROM xui_channel_cache WHERE xui_panel_id = ? AND provider_id = ?");
    $existingStmt->execute([$panel['id'], $providerId]);
    $existingByUrl = [];
    foreach ($existingStmt->fetchAll() as $row) {
        $existingByUrl[trim($row['source_url'])] = $row;
    }

    $createdCount = 0;
    $updatedCount = 0;
    $failed = [];
    $insertCacheStmt = $pdo->prepare("INSERT INTO xui_channel_cache (user_id, xui_panel_id, provider_id, stream_id, name, source_url, logo, category_ids, bouquet_ids) VALUES (?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE provider_id=VALUES(provider_id), name=VALUES(name), source_url=VALUES(source_url), logo=VALUES(logo), category_ids=VALUES(category_ids), bouquet_ids=VALUES(bouquet_ids)");
    $updateCacheNameStmt = $pdo->prepare("UPDATE xui_channel_cache SET name = ?, category_ids = ?, bouquet_ids = ? WHERE xui_panel_id = ? AND stream_id = ?");

    // --- Paso 2a: clasificar cada canal SIN llamar todavía al panel — solo
    // se decide a qué "tanda" pertenece (crear / renombrar / sin cambios).
    $toCreate = []; // [ ['ch'=>..., 'source_url'=>...], ... ]
    $toUpdate = []; // [ ['ch'=>..., 'existing'=>..., 'source_url'=>...], ... ]
    $needsAssignIds = []; // stream_ids que deben pasar por la asignación de servidor/on-demand al final
    $assignIdToName = []; // stream_id => nombre del canal, para poder reportar cuáles fallaron por nombre (no solo un total)
    // stream_id => params completos de edit_stream (incluye "bouquets"), para poder
    // reenviarlos UNO POR UNO si el panel pierde la membresía del bouquet por la
    // condición de carrera descrita más abajo (Paso 2d-bis).
    $bouquetCandidates = [];
    $unchangedCount = 0;

    foreach ($localChannels as $ch) {
        $sourceUrl = trim($ch['stream_url'] ?? '');
        if ($sourceUrl === '' || !filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
            $failed[] = $ch['name'] . ' (URL inválida)';
            continue;
        }

        $existing = $existingByUrl[$sourceUrl] ?? null;
        if ($existing) {
            if ($existing['name'] === $ch['name']) {
                $unchangedCount++;
                if ($serverIds && $xuiChannelNeedsAssign((int)$existing['stream_id'])) {
                    $needsAssignIds[] = (int)$existing['stream_id'];
                    $assignIdToName[(int)$existing['stream_id']] = $ch['name'];
                }
            } else {
                $toUpdate[] = ['ch' => $ch, 'existing' => $existing, 'source_url' => $sourceUrl];
            }
        } else {
            $toCreate[] = ['ch' => $ch, 'source_url' => $sourceUrl];
        }
    }

    // --- Paso 2b: crear en tanda (varias conexiones a la vez en vez de una
    // por una) — ver includes/XuiClient.php::xui_call_batch().
    if ($toCreate) {
        $createParamsList = array_map(function ($item) use ($xuiCategoryId, $bouquetId) {
            $params = [
                'stream_display_name' => $item['ch']['name'],
                'stream_source' => [$item['source_url']],
                'stream_all' => 1,
                'probesize_ondemand' => 512000,
                'category_id' => [$xuiCategoryId],
            ];
            if (!empty($item['ch']['logo_url'])) {
                $params['stream_icon'] = $item['ch']['logo_url'];
            }
            if ($bouquetId) {
                $params['bouquets'] = [$bouquetId];
            }
            return $params;
        }, $toCreate);

        $createResults = xui_call_batch($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'create_stream', $createParamsList, $concurrency);

        foreach ($toCreate as $i => $item) {
            $result = $createResults[$i];
            $newId = (int)($result['json']['data']['id'] ?? 0);
            if (($result['json']['status'] ?? null) === 'STATUS_SUCCESS' && $newId) {
                $createdCount++;
                $insertCacheStmt->execute([$userId, $panel['id'], $providerId, $newId, $item['ch']['name'], $item['source_url'], $item['ch']['logo_url'] ?: null, (string)$xuiCategoryId, $bouquetId ? (string)$bouquetId : null]);
                if ($serverIds) {
                    $needsAssignIds[] = $newId;
                    $assignIdToName[$newId] = $item['ch']['name'];
                }
                if ($bouquetId) {
                    $bouquetCandidates[$newId] = array_merge(['id' => $newId], $createParamsList[$i]);
                }
            } else {
                $failed[] = $item['ch']['name'];
            }
        }
    }

    // --- Paso 2c: renombrar en tanda. "bouquets" es CRÍTICO reenviarlo: se
    // verificó que omitirlo saca al canal de TODOS los bouquets en los que
    // estaba (no es aditivo, es "membresía exacta"). Se reenvía la unión de
    // lo ya cacheado + el bouquet elegido ahora, para no perder membresías
    // de importaciones/ediciones anteriores.
    if ($toUpdate) {
        $updateParamsList = array_map(function ($item) use ($xuiCategoryId, $bouquetId) {
            $cachedBouquetIds = !empty($item['existing']['bouquet_ids']) ? array_map('intval', explode(',', $item['existing']['bouquet_ids'])) : [];
            $finalBouquetIds = array_values(array_unique(array_filter(array_merge($cachedBouquetIds, $bouquetId ? [$bouquetId] : []))));
            $params = [
                'id' => $item['existing']['stream_id'],
                'stream_display_name' => $item['ch']['name'],
                'stream_source' => [$item['source_url']],
                'stream_all' => 1,
                'probesize_ondemand' => 512000,
                'category_id' => [$xuiCategoryId],
            ];
            if (!empty($item['ch']['logo_url'])) {
                $params['stream_icon'] = $item['ch']['logo_url'];
            }
            if ($finalBouquetIds) {
                $params['bouquets'] = $finalBouquetIds;
            }
            return $params;
        }, $toUpdate);

        $updateResults = xui_call_batch($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'edit_stream', $updateParamsList, $concurrency);

        foreach ($toUpdate as $i => $item) {
            $result = $updateResults[$i];
            if (($result['json']['status'] ?? null) === 'STATUS_SUCCESS') {
                $updatedCount++;
                $finalBouquetIds = $updateParamsList[$i]['bouquets'] ?? [];
                $updateCacheNameStmt->execute([$item['ch']['name'], (string)$xuiCategoryId, $finalBouquetIds ? implode(',', $finalBouquetIds) : null, $panel['id'], $item['existing']['stream_id']]);
                if ($serverIds) {
                    $needsAssignIds[] = (int)$item['existing']['stream_id'];
                    $assignIdToName[(int)$item['existing']['stream_id']] = $item['ch']['name'];
                }
                if ($bouquetId && $finalBouquetIds) {
                    $bouquetCandidates[(int)$item['existing']['stream_id']] = $updateParamsList[$i];
                }
            } else {
                $failed[] = $item['ch']['name'] . ' (no se pudo actualizar el nombre)';
            }
        }
    }

    // --- Paso 2c-bis: verificar y reparar la membresía REAL del bouquet.
    // Se comprobó en producción (2026-07-26, categoría "Latinos", 139
    // canales): crear muchos streams EN PARALELO con "bouquets" puede perder
    // algunos por una condición de carrera del lado del panel — al crear un
    // stream con bouquets=[N], el panel lee la lista de miembros actual del
    // bouquet N, agrega el nuevo id, y guarda la lista completa. Si dos
    // creaciones hacen esa lectura casi al mismo tiempo (algo esperable con
    // la concurrencia usada aquí), la segunda sobreescribe la lista sin lo
    // que la primera acababa de agregar — el canal queda creado y con
    // category_id correcto, pero fuera del bouquet, aunque create_stream
    // respondió STATUS_SUCCESS. De 139 canales creados así, 13 quedaron
    // fuera del bouquet real pese a que todos "reportaron éxito".
    // Por eso, tras crear/renombrar en paralelo, se relee la membresía real
    // del bouquet elegido (UNA sola llamada) y se corrige — UNO POR UNO, sin
    // concurrencia, para no repetir la misma carrera — cualquier canal que
    // se haya perdido, reenviando los mismos parámetros que ya se armaron
    // arriba (evita tener que reconstruirlos).
    $bouquetRepaired = 0;
    $bouquetStillFailedNames = [];
    if ($bouquetId && $bouquetCandidates) {
        $bouquetsNow = xui_call($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'get_bouquets');
        $actualMemberIds = [];
        foreach (($bouquetsNow['json'] ?? []) as $b) {
            if ((int)($b['id'] ?? 0) === $bouquetId) {
                $decoded = json_decode($b['bouquet_channels'] ?? '[]', true);
                $actualMemberIds = is_array($decoded) ? array_map('intval', $decoded) : [];
                break;
            }
        }
        $actualMemberSet = array_flip($actualMemberIds);
        foreach ($bouquetCandidates as $streamId => $editParams) {
            if (isset($actualMemberSet[$streamId])) {
                continue; // ya está en el bouquet, no hace falta tocarlo
            }
            $retry = xui_call($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'edit_stream', $editParams);
            if (($retry['json']['status'] ?? null) === 'STATUS_SUCCESS') {
                $bouquetRepaired++;
            } else {
                $bouquetStillFailedNames[] = $assignIdToName[$streamId] ?? ($editParams['stream_display_name'] ?? ('id ' . $streamId));
            }
            usleep(200000); // 200ms entre reintentos: no repetir la misma condición de carrera
        }
    }

    // --- Paso 2d: asignar servidor/on-demand a TODOS los canales que lo
    // necesitan (creados + renombrados + "sin cambios" a los que les
    // faltaba) EN UNA SOLA TANDA — lee los formularios de todos en paralelo
    // y guarda todos en paralelo, en vez de repetir login+lectura+guardado
    // canal por canal (ver xui_session_assign_batch_using_panel).
    $serverAssignFailed = 0;
    $serverAssignCount = 0; // canales a los que SÍ se les asignó servidor/on-demand/llod con éxito
    $serverAssignFailedNames = [];
    $serverAssignError = null;
    if ($serverIds && $needsAssignIds) {
        $uniqueAssignIds = array_unique($needsAssignIds);
        $assignResult = xui_session_assign_batch_using_panel($panel, $uniqueAssignIds, $serverIds, $onDemand, $concurrency, $llod);
        if (!$assignResult['ok']) {
            $serverAssignError = $assignResult['error'];
            $serverAssignFailed = count($uniqueAssignIds);
            foreach ($uniqueAssignIds as $id) {
                $serverAssignFailedNames[] = $assignIdToName[$id] ?? ('id ' . $id);
            }
        } else {
            // $assignResult['results'] viene indexado por stream_id (ver
            // xui_session_assign_stream_batch), así que se puede recuperar el
            // nombre de cada canal que falló en vez de solo contar cuántos.
            foreach ($assignResult['results'] as $id => $r) {
                if (empty($r['ok'])) {
                    $serverAssignFailed++;
                    $serverAssignFailedNames[] = $assignIdToName[$id] ?? ('id ' . $id);
                } else {
                    $serverAssignCount++;
                }
            }
        }
    }

    // Paso 3: borrar de XUI los canales que esta herramienta creó antes para
    // este proveedor+categoría, pero que el proveedor ya quitó de su M3U
    // (ya no están en $localChannels, que solo trae status='active').
    $removedCount = 0;
    $localUrlSet = [];
    foreach ($localChannels as $ch) {
        $localUrlSet[trim($ch['stream_url'])] = true;
    }
    $staleStmt = $pdo->prepare("SELECT stream_id, name, source_url, category_ids FROM xui_channel_cache WHERE xui_panel_id = ? AND provider_id = ?");
    $staleStmt->execute([$panel['id'], $providerId]);
    $staleRows = [];
    foreach ($staleStmt->fetchAll() as $row) {
        $catIds = array_map('intval', explode(',', (string)$row['category_ids']));
        if (!in_array($xuiCategoryId, $catIds, true)) {
            continue; // cacheado para otra categoría, no tocar aquí
        }
        if (isset($localUrlSet[trim($row['source_url'])])) {
            continue; // sigue activo localmente
        }
        $staleRows[] = $row;
    }
    // xui_call_batch(): igual que en api/xui_import.php, delete_stream no
    // tiene versión "varios a la vez" en la API — se manda en paralelo en
    // vez de uno por uno para no hacer lenta la limpieza en categorías con
    // muchos canales descontinuados.
    if ($staleRows) {
        $paramsList = array_map(fn($row) => ['id' => $row['stream_id']], $staleRows);
        $delResults = xui_call_batch($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'delete_stream', $paramsList, $concurrency);
        $delCacheStmt = $pdo->prepare("DELETE FROM xui_channel_cache WHERE xui_panel_id = ? AND stream_id = ?");
        foreach ($staleRows as $i => $row) {
            if (($delResults[$i]['json']['status'] ?? null) === 'STATUS_SUCCESS') {
                $removedCount++;
                $delCacheStmt->execute([$panel['id'], $row['stream_id']]);
            } else {
                $failed[] = ($row['name'] ?: ('id ' . $row['stream_id'])) . ' (no se pudo borrar el canal descontinuado)';
            }
        }
    }

    respond([
        'ok' => true,
        'xui_category_id' => $xuiCategoryId,
        'xui_category_name' => $xuiCategoryName,
        'total' => count($localChannels),
        'created' => $createdCount,
        'updated' => $updatedCount,
        'unchanged' => $unchangedCount,
        'removed' => $removedCount,
        'bouquet_repaired' => $bouquetRepaired,
        'bouquet_still_failed_names' => $bouquetStillFailedNames,
        'server_assign_count' => $serverAssignCount,
        'server_assign_failed' => $serverAssignFailed,
        'server_assign_failed_names' => $serverAssignFailedNames,
        'server_assign_error' => $serverAssignError,
        'failed' => $failed,
    ]);
}

respond_error('Acción no soportada.', 400);
