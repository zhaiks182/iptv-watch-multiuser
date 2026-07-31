<?php
/**
 * Cliente HTTP compartido para hablar con la API de administración de un
 * panel XUI·ONE. Usado por api/xui_panels.php (validar antes de guardar),
 * api/xui_test.php (probador de solo lectura), api/xui_resources.php
 * (recursos de hardware) y api/xui_import.php (acciones que escriben).
 */

function xui_call(string $panelUrl, string $accessCode, string $apiKey, string $action, array $extraParams = []): array
{
    $qs = http_build_query(array_merge(['api_key' => $apiKey, 'action' => $action], $extraParams));
    $targetUrl = rtrim($panelUrl, '/') . '/' . $accessCode . '/?' . $qs;

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'header' => "User-Agent: IPTV-Watch/1.0\r\n",
            'follow_location' => 1,
            'max_redirects' => 5,
            'ignore_errors' => true, // conservar el cuerpo aunque el status sea 4xx/5xx
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $raw = @file_get_contents($targetUrl, false, $ctx);

    if ($raw === false) {
        return [
            'ok' => false,
            'http_code' => 0,
            'raw' => null,
            'json' => null,
            'error' => 'No se pudo conectar al panel. Revisa la URL, el puerto (ej. 9000/8000) y que el servidor esté accesible desde este servidor.',
            'target_url' => $targetUrl,
        ];
    }

    $httpCode = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
                $httpCode = (int)$m[1];
            }
        }
    }

    $json = json_decode($raw, true);
    $ok = $httpCode >= 200 && $httpCode < 300;

    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'raw' => $raw,
        'json' => (json_last_error() === JSON_ERROR_NONE) ? $json : null,
        'error' => $ok ? null : ('El panel respondió HTTP ' . $httpCode . '.'),
        'target_url' => $targetUrl,
    ];
}

/**
 * Como xui_call(), pero para acciones tipo DataTables que traen "data" +
 * "recordsTotal" (get_streams, get_movies) — se verificó que estas
 * acciones SIEMPRE paginan a 50 resultados por página sin importar qué se
 * mande en "length" (probado con -1, 1000, 10000: ninguno cambió el
 * tamaño de página), pero "start" sí funciona para moverse entre páginas.
 * Esta función recorre todas las páginas necesarias y devuelve el mismo
 * formato de xui_call() con "json.data" ya combinado completo.
 */
function xui_call_all_pages(string $panelUrl, string $accessCode, string $apiKey, string $action, array $extraParams = [], int $pageSize = 50): array
{
    $start = 0;
    $all = [];
    $last = null;
    do {
        $result = xui_call($panelUrl, $accessCode, $apiKey, $action, array_merge($extraParams, ['start' => $start, 'length' => $pageSize]));
        if (!$result['ok'] || !is_array($result['json']['data'] ?? null)) {
            return $result;
        }
        $last = $result;
        $page = $result['json']['data'];
        $all = array_merge($all, $page);
        $total = (int)($result['json']['recordsTotal'] ?? count($all));
        $start += $pageSize;
    } while (count($page) > 0 && count($all) < $total);

    $last['json']['data'] = $all;
    return $last;
}

/**
 * Motor genérico de ejecución en paralelo con CONCURRENCIA ADAPTATIVA —
 * usado tanto por xui_call_batch() (de aquí abajo) como por las llamadas en
 * tanda de sesión (includes/XuiSession.php). En vez de un número fijo de
 * conexiones simultáneas adivinado de antemano, arranca en $start y:
 *   - SUBE de a 1 cada vez que las últimas respuestas fueron rápidas y sin
 *     errores (parecido al "slow start" de TCP) — aprovecha lo que el panel
 *     realmente pueda dar en ese momento.
 *   - BAJA a la mitad en cuanto una respuesta tarda de más o falla — para
 *     no seguir insistiendo si el panel (o su propia carga de espectadores
 *     reales viendo TV) ya está exigido.
 * Se probó en el panel real hasta 20 conexiones a la vez sin errores ni
 * mezcla de datos entre canales — $max (50) se deja con margen por encima
 * de eso a propósito, aprovechando que el servidor mostró tener CPU de
 * sobra (htop: load average bajo en una máquina de 4 núcleos); el propio
 * mecanismo de bajada protege si en algún momento no aguanta tanto.
 *
 * $curlOptsList: [key => arreglo de CURLOPT_* => valor] (uno por petición,
 * ya armado por el llamador — así sirve tanto para las llamadas por
 * querystring con api_key como para las de sesión con cookie/POST).
 * Devuelve [key => ['ok','http_code','body','error','elapsed']] con las
 * mismas claves que $curlOptsList.
 */
function xui_adaptive_multi_exec(
    array $curlOptsList,
    int $start = 8,
    int $min = 4,
    int $max = 50,
    float $fastThreshold = 0.35,
    float $slowThreshold = 1.2
): array {
    $keys = array_keys($curlOptsList);
    $total = count($keys);
    $results = [];
    if ($total === 0) {
        return $results;
    }

    $concurrency = max($min, min($start, $max));
    $mh = curl_multi_init();
    $inFlight = []; // spl_object_id($ch) => ['key' => mixed, 'start' => float]
    $nextIndex = 0;
    $recentLatencies = [];
    $recentErrors = 0;
    $windowSize = 6;

    $addHandle = function () use (&$nextIndex, $total, $keys, $curlOptsList, $mh, &$inFlight) {
        if ($nextIndex >= $total) {
            return false;
        }
        $key = $keys[$nextIndex++];
        $ch = curl_init();
        curl_setopt_array($ch, $curlOptsList[$key]);
        curl_multi_add_handle($mh, $ch);
        $inFlight[spl_object_id($ch)] = ['key' => $key, 'start' => microtime(true)];
        return true;
    };

    for ($i = 0; $i < $concurrency; $i++) {
        if (!$addHandle()) {
            break;
        }
    }

    $active = null;
    do {
        curl_multi_exec($mh, $active);
        curl_multi_select($mh, 1.0);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $id = spl_object_id($ch);
            $meta = $inFlight[$id] ?? null;
            if ($meta) {
                $elapsed = microtime(true) - $meta['start'];
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $body = curl_multi_getcontent($ch);
                $curlErr = curl_error($ch);
                $ok = $httpCode >= 200 && $httpCode < 300 && $body !== '';
                $results[$meta['key']] = [
                    'ok' => $ok,
                    'http_code' => $httpCode,
                    'body' => $body !== '' ? $body : null,
                    'error' => $ok ? null : ($curlErr !== '' ? $curlErr : ('HTTP ' . $httpCode)),
                    'elapsed' => $elapsed,
                ];

                // --- Señal adaptativa: ajustar la concurrencia según cómo
                // respondieron las últimas peticiones (ventana móvil). ---
                $recentLatencies[] = $elapsed;
                if (count($recentLatencies) > $windowSize) {
                    array_shift($recentLatencies);
                }
                $recentErrors = $ok ? max(0, $recentErrors - 1) : $recentErrors + 1;

                if (count($recentLatencies) >= 3) {
                    $avg = array_sum($recentLatencies) / count($recentLatencies);
                    if ($recentErrors > 0 || $avg > $slowThreshold) {
                        $concurrency = max($min, intdiv($concurrency, 2));
                        $recentErrors = 0;
                        $recentLatencies = [];
                    } elseif ($avg < $fastThreshold) {
                        $concurrency = min($max, $concurrency + 1);
                    }
                }

                unset($inFlight[$id]);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
        }
        // Rellenar hasta el nivel de concurrencia vigente (puede haber
        // subido o bajado desde la última vuelta).
        while (count($inFlight) < $concurrency && $nextIndex < $total) {
            if (!$addHandle()) {
                break;
            }
        }
    } while (count($inFlight) > 0 || $nextIndex < $total);

    curl_multi_close($mh);
    return $results;
}

/**
 * Como xui_call(), pero dispara muchas llamadas EN PARALELO con concurrencia
 * ADAPTATIVA (ver xui_adaptive_multi_exec) en vez de una por una — pensado
 * para acciones tipo delete_stream/create_stream/edit_stream, donde no
 * existe un "hacer varios a la vez" en la API de XUI·ONE y hay que repetir
 * la misma acción una vez por id.
 *
 * $paramsList es un arreglo de arreglos de parámetros extra (uno por
 * llamada, ej. [['id'=>101], ['id'=>102], ...]). Devuelve un arreglo con el
 * mismo tamaño y orden que $paramsList, cada posición con el mismo formato
 * que xui_call() ({ok, http_code, raw, json, error, target_url}).
 */
function xui_call_batch(string $panelUrl, string $accessCode, string $apiKey, string $action, array $paramsList, int $startConcurrency = 8): array
{
    $base = rtrim($panelUrl, '/') . '/' . $accessCode . '/?';
    $curlOptsList = [];
    foreach ($paramsList as $idx => $extraParams) {
        $qs = http_build_query(array_merge(['api_key' => $apiKey, 'action' => $action], $extraParams));
        $curlOptsList[$idx] = [
            CURLOPT_URL => $base . $qs,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'IPTV-Watch/1.0',
        ];
    }

    $raw = xui_adaptive_multi_exec($curlOptsList, $startConcurrency);

    $results = array_fill(0, count($paramsList), null);
    foreach ($paramsList as $idx => $extraParams) {
        $r = $raw[$idx] ?? null;
        if (!$r) {
            $results[$idx] = ['ok' => false, 'http_code' => 0, 'raw' => null, 'json' => null, 'error' => 'Sin respuesta del panel.', 'target_url' => null];
            continue;
        }
        $json = $r['body'] !== null ? json_decode($r['body'], true) : null;
        $results[$idx] = [
            'ok' => $r['ok'],
            'http_code' => $r['http_code'],
            'raw' => $r['body'],
            'json' => (json_last_error() === JSON_ERROR_NONE) ? $json : null,
            'error' => $r['error'],
            'target_url' => $curlOptsList[$idx][CURLOPT_URL],
        ];
    }
    return $results;
}

function xui_mask_api_key(string $url, string $apiKey): string
{
    if ($apiKey === '') {
        return $url;
    }
    $visible = 4;
    $len = strlen($apiKey);
    $masked = $len <= $visible * 2
        ? str_repeat('*', $len)
        : substr($apiKey, 0, $visible) . str_repeat('*', $len - $visible * 2) . substr($apiKey, -$visible);
    return str_replace($apiKey, $masked, $url);
}
