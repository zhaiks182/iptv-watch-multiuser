<?php
/**
 * Búsqueda y asignación manual de logos desde DOS fuentes públicas:
 * github.com/tv-logo/tv-logos (ver includes/GithubLogos.php) y
 * github.com/iptv-org/database (ver includes/IptvOrgLogos.php, agregada
 * 2026-07-31 tras comparar varios repos — ~4x más logos, a cambio de vivir
 * en hosts externos variados en vez de un solo repo de imágenes). Cada
 * fuente tiene su propio caché e índice independientes; 'search' combina
 * resultados de ambas.
 *
 * GET -> estado de los dos índices cacheados:
 *   {count, updated_at, iptvorg_count, iptvorg_updated_at}.
 *
 * POST {action:'refresh_index'}
 *   -> descarga de nuevo AMBOS índices y reemplaza los cachés locales.
 *      Puede tardar varios segundos — no hay problema en que cualquier
 *      usuario logueado lo dispare, son cachés compartidos de repos
 *      públicos, sin datos por usuario. Si una fuente falla, la otra igual
 *      se actualiza (no es todo o nada).
 *
 * POST {action:'search', query}
 *   -> hasta 50 coincidencias combinadas de ambos índices cacheados (ver
 *      GithubLogos::github_logos_search e
 *      IptvOrgLogos::iptvorg_logos_search), cada una marcada con
 *      `source: 'tv-logo'|'iptv-org'`. Si una fuente no tiene índice
 *      todavía, se ignora su error mientras la otra sí traiga resultados.
 *      No re-descarga nada.
 *
 * POST {action:'assign', channel_id, logo_url}
 *   -> fija channels.logo_url y marca logo_manual=1 para ese canal (dueño
 *      = usuario logueado). "logo_manual" es lo que le dice a
 *      includes/Sync.php que NO pise este logo en la próxima
 *      sincronización del proveedor — de lo contrario, el próximo
 *      Sync::syncProvider() lo volvería a dejar vacío/con lo que traiga el
 *      M3U, deshaciendo la asignación manual sin avisar.
 *      logo_url debe ser https y terminar en una extensión de imagen
 *      conocida (ya no se exige un solo prefijo fijo, porque iptv-org/database
 *      reparte sus logos en decenas de hosts distintos) — no se valida que
 *      la imagen exista de verdad server-side (ver nota sobre Wikimedia en
 *      IptvOrgLogos.php: eso se comprobó que no es viable), el navegador la
 *      oculta sola si no carga.
 *
 * POST {action:'clear', channel_id}
 *   -> quita logo_manual (vuelve a dejar que el próximo sync controle el
 *      logo de este canal). No borra el logo_url actual por sí solo.
 *
 * POST {action:'bulk_auto_assign_from_search', provider_id?, category_id?}
 *   -> a todo canal activo del usuario sin logo_url, O con el ícono
 *      genérico de 'bulk_fill_generic' (assets/generic-logos/ — se
 *      considera "mejorable"), limitado a category_id si se manda (botón
 *      por categoría) o a provider_id si no (botón global + filtro de
 *      proveedor activo) — son alcances independientes, no se combinan.
 *      Busca el NOMBRE del canal en
 *      las dos fuentes (mismas funciones que action:'search', tv-logo
 *      primero) y asigna automáticamente el mejor resultado SOLO si es
 *      coincidencia completa (match_words === match_total, ninguna palabra
 *      del nombre se quedó sin matchear) — sin revisión humana de por
 *      medio, así que el umbral es deliberadamente estricto para evitar
 *      asignar el logo equivocado. Canales sin una coincidencia así de
 *      buena quedan sin tocar (candidatos para 'bulk_fill_generic' después,
 *      no para esta acción). Marca logo_manual=1 igual que una asignación
 *      manual. Devuelve {ok, assigned, skipped, by_source:{source:count}}.
 *
 * POST {action:'bulk_fill_generic', provider_id?}
 *   -> a todo canal activo del usuario sin logo_url (y, si se manda
 *      provider_id, limitado a ese proveedor) le asigna un ícono genérico
 *      según su categoría (ver includes/GithubLogos.php:
 *      github_logos_generic_bucket_for_category — clasifica por palabra
 *      clave en el NOMBRE DE LA CATEGORÍA, no del canal) y marca
 *      logo_manual=1, igual que una asignación manual: Sync.php no lo
 *      pisará después. Es un fallback deliberado para canales que ni
 *      siquiera aparecieron en la búsqueda combinada de las dos fuentes
 *      (action:'search') — no la reemplaza, es para lo que quedó sin nada
 *      tras probarla.
 *      Devuelve {ok, filled, by_bucket:{bucket:count}}.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/GithubLogos.php';
require_once __DIR__ . '/../includes/IptvOrgLogos.php';

$pdo = get_pdo();
$userId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tvLogo = github_logos_index_status();
    $iptvOrg = iptvorg_logos_index_status();
    respond([
        'count' => $tvLogo['count'],
        'updated_at' => $tvLogo['updated_at'],
        'iptvorg_count' => $iptvOrg['count'],
        'iptvorg_updated_at' => $iptvOrg['updated_at'],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('Método no soportado', 405);
}

$body = json_input();
$action = $body['action'] ?? '';

if ($action === 'refresh_index') {
    session_write_close();
    $result = ['ok' => true];
    $errors = [];
    try {
        $result['tv_logo'] = github_logos_refresh_index();
    } catch (Throwable $e) {
        $errors[] = 'tv-logo/tv-logos: ' . $e->getMessage();
    }
    try {
        $result['iptv_org'] = iptvorg_logos_refresh_index();
    } catch (Throwable $e) {
        $errors[] = 'iptv-org/database: ' . $e->getMessage();
    }
    if ($errors) {
        $result['ok'] = false;
        $result['error'] = implode(' | ', $errors);
    }
    respond($result, $errors ? 502 : 200);
}

if ($action === 'search') {
    $query = trim((string)($body['query'] ?? ''));
    if ($query === '') {
        respond_error('Escribe algo para buscar.');
    }
    $results = [];
    $errors = [];
    try {
        foreach (github_logos_search($query, 25) as $r) {
            $r['source'] = 'tv-logo';
            $results[] = $r;
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
    try {
        $results = array_merge($results, iptvorg_logos_search($query, 25));
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
    if (!$results && $errors) {
        respond_error(implode(' | ', $errors), 409);
    }
    usort($results, fn($a, $b) => $b['match_words'] <=> $a['match_words']);
    respond(['ok' => true, 'results' => array_slice($results, 0, 50)]);
}

if ($action === 'assign') {
    $channelId = (int)($body['channel_id'] ?? 0);
    $logoUrl = trim((string)($body['logo_url'] ?? ''));
    if (!$channelId) {
        respond_error('Falta channel_id.');
    }
    if ($logoUrl === '' || !preg_match('#^https://[^\s]+\.(png|jpe?g|svg|webp|gif)(\?[^\s]*)?$#i', $logoUrl)) {
        respond_error('URL de logo inválida.');
    }

    $stmt = $pdo->prepare("UPDATE channels SET logo_url = ?, logo_manual = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$logoUrl, $channelId, $userId]);
    if ($stmt->rowCount() === 0) {
        respond_error('Canal no encontrado.', 404);
    }

    respond(['ok' => true, 'logo_url' => $logoUrl]);
}

if ($action === 'clear') {
    $channelId = (int)($body['channel_id'] ?? 0);
    if (!$channelId) {
        respond_error('Falta channel_id.');
    }
    $stmt = $pdo->prepare("UPDATE channels SET logo_manual = 0 WHERE id = ? AND user_id = ?");
    $stmt->execute([$channelId, $userId]);
    respond(['ok' => true]);
}

if ($action === 'bulk_auto_assign_from_search') {
    $providerId = (int)($body['provider_id'] ?? 0);
    $categoryId = (int)($body['category_id'] ?? 0);

    // Incluye también los canales que ya tienen el ícono genérico de
    // 'bulk_fill_generic' (assets/generic-logos/) — son candidatos a
    // "mejorar" con un logo real si aparece una coincidencia segura, no
    // solo los que están completamente vacíos.
    $sql = "SELECT id, name FROM channels
            WHERE user_id = ? AND status = 'active'
            AND (logo_url IS NULL OR logo_url = '' OR logo_url LIKE '%/assets/generic-logos/%')";
    $params = [$userId];
    if ($categoryId) {
        // El botón por categoría manda category_id y no provider_id —
        // son alcances independientes, no se combinan (ver
        // index.html: runAutoAssignLogos envía uno u otro).
        $sql .= " AND category_id = ?";
        $params[] = $categoryId;
    } elseif ($providerId) {
        $sql .= " AND provider_id = ?";
        $params[] = $providerId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        respond(['ok' => true, 'assigned' => 0, 'skipped' => 0, 'by_source' => []]);
    }

    $updateStmt = $pdo->prepare("UPDATE channels SET logo_url = ?, logo_manual = 1 WHERE id = ?");
    $assigned = 0;
    $skipped = 0;
    $bySource = [];
    foreach ($rows as $row) {
        $best = null;
        try {
            foreach (github_logos_search($row['name'], 5) as $r) {
                if ($r['match_words'] === $r['match_total']) {
                    $best = $r;
                    $best['source'] = 'tv-logo';
                    break;
                }
            }
        } catch (Throwable $e) {
            // índice de tv-logo no disponible — seguimos con la otra fuente
        }
        if ($best === null) {
            try {
                foreach (iptvorg_logos_search($row['name'], 5) as $r) {
                    if ($r['match_words'] === $r['match_total']) {
                        $best = $r;
                        break;
                    }
                }
            } catch (Throwable $e) {
                // índice de iptv-org no disponible tampoco — este canal queda sin asignar
            }
        }
        if ($best !== null) {
            $updateStmt->execute([$best['raw_url'], $row['id']]);
            $assigned++;
            $bySource[$best['source']] = ($bySource[$best['source']] ?? 0) + 1;
        } else {
            $skipped++;
        }
    }

    respond(['ok' => true, 'assigned' => $assigned, 'skipped' => $skipped, 'by_source' => $bySource]);
}

if ($action === 'bulk_fill_generic') {
    $providerId = (int)($body['provider_id'] ?? 0);

    $sql = "SELECT ch.id, c.name AS category_name FROM channels ch
            LEFT JOIN categories c ON c.id = ch.category_id
            WHERE ch.user_id = ? AND ch.status = 'active' AND (ch.logo_url IS NULL OR ch.logo_url = '')";
    $params = [$userId];
    if ($providerId) {
        $sql .= " AND ch.provider_id = ?";
        $params[] = $providerId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        respond(['ok' => true, 'filled' => 0, 'by_bucket' => []]);
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $appBaseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];

    $updateStmt = $pdo->prepare("UPDATE channels SET logo_url = ?, logo_manual = 1 WHERE id = ?");
    $byBucket = [];
    foreach ($rows as $row) {
        $bucket = github_logos_generic_bucket_for_category($row['category_name']);
        $url = github_logos_generic_url($bucket, $appBaseUrl);
        $updateStmt->execute([$url, $row['id']]);
        $byBucket[$bucket] = ($byBucket[$bucket] ?? 0) + 1;
    }

    respond(['ok' => true, 'filled' => count($rows), 'by_bucket' => $byBucket]);
}

respond_error('Acción no soportada.', 400);
