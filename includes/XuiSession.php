<?php
/**
 * Automatización de la sesión web del panel XUI·ONE (usuario/contraseña,
 * NO el api_key) — la única forma de asignar "servidor" y "on-demand" a un
 * canal, confirmado empíricamente: create_stream/edit_stream (api_key)
 * aceptan esos parámetros sin quejarse (STATUS_SUCCESS) pero NUNCA los
 * guardan de verdad. El panel guarda esto por una ruta totalmente distinta:
 *
 *   1. Login: POST {panel_url}/{session_access_code}/login
 *      con username, password, referrer='' y login='Login' (el botón de
 *      submit tiene name="login" — sin ese campo el panel ignora el intento
 *      de login sin ningún error, solo re-muestra la misma página).
 *
 *   2. El formulario de edición de un canal (GET .../stream?id=X) es UNA
 *      sola página con TODAS las pestañas (detalles, fuente, avanzado,
 *      mapa, EPG, RTMP push, balanceo de carga) en un solo <form
 *      id="stream_form">. Al guardar se reenvían TODOS los campos del
 *      formulario a la vez (no es un update parcial — mismo patrón que ya
 *      conocíamos de edit_stream vía api_key), así que hay que leer el
 *      formulario actual primero y solo pisar los 2-3 campos que nos
 *      interesan.
 *
 *   3. El guardado real NO es el <form action="./stream?id=X"> — ese submit
 *      se intercepta con preventDefault() y en su lugar se manda un POST
 *      multipart/form-data (via FormData de JS) a:
 *        {panel_url}/{session_access_code}/post.php?action=stream&referer=
 *      Confirmado con curl puro (sin navegador): un POST normal
 *      application/x-www-form-urlencoded a la URL del <form> no hace nada
 *      (200 OK, contenido idéntico a un GET, sin error visible) — hay que
 *      usar multipart y esta URL exacta.
 *
 *   4. "server_tree_data" es un campo JSON que representa un árbol
 *      arrastrar-y-soltar: cada servidor vive bajo el nodo "source"
 *      (rama "Online" = asignado a este canal) o bajo "offline" (rama sin
 *      asignar). Asignar un servidor es "moverlo" a la rama source. En vez
 *      de leer el árbol actual del HTML, esta implementación siempre manda
 *      el estado COMPLETO deseado (todos los servidores conocidos vía
 *      get_servers, marcando como asignados solo los pedidos) — más simple
 *      y no depende de parsear el HTML del árbol.
 *
 *   5. "on_demand[]" SÍ es un <select multiple> normal (ids de servidor) —
 *      no un booleano. Un canal puede ser "en vivo" en un servidor y
 *      "on-demand" en otro; en este proyecto se asignan los mismos
 *      servidores a ambos por simplicidad (así es como se hacía a mano).
 *
 *   6. "llod" (pestaña "Servers", campo "Low Latency On-Demand") es un
 *      <select name="llod"> simple (no multiple) en ESE MISMO stream_form:
 *      value="0" Disabled, "1" LLOD v2 - FFMPEG, "2" LLOD v3 - PHP.
 *      Confirmado empíricamente (2026-07-31) creando un canal de prueba e
 *      inspeccionando su formulario — nunca se había expuesto antes porque
 *      hasta ahora esta función solo tocaba server_tree_data/on_demand[] y
 *      reenviaba "llod" tal cual venía en el baseline (sin cambiarlo).
 *
 * Sesión/cookies: el cookie-jar de curl basado en ARCHIVO no funciona en
 * este servidor (se probó: curl_setopt CURLOPT_COOKIEJAR/COOKIEFILE nunca
 * escribe el archivo aunque curl_error() no reporte nada — probablemente
 * una restricción del entorno). La solución que sí funciona: mantener
 * abierto UN MISMO $ch (CurlHandle) para todo el login + lecturas + POST de
 * una tanda de canales, con CURLOPT_COOKIEFILE = '' (activa el motor de
 * cookies de curl en memoria, sin tocar disco) y reutilizando ese mismo
 * handle en cada llamada siguiente. Por eso todas las funciones de este
 * archivo reciben un $ch ya autenticado (xui_session_login lo crea).
 *
 * Verificado en pruebas reales: se creó un canal de prueba, se le asignó
 * servidor + on-demand con este mismo mecanismo, se confirmó vía
 * get_streams (api_key) que server_id/on_demand quedaron guardados, y se
 * borró el canal de prueba.
 */

require_once __DIR__ . '/XuiClient.php'; // xui_call(), xui_adaptive_multi_exec()

/**
 * Crea un handle de curl con el motor de cookies activo y hace login.
 * Devuelve el handle (para reutilizar en llamadas siguientes) o null si
 * falló. El llamador debe cerrarlo con xui_session_cleanup() al terminar.
 */
function xui_session_login(string $panelUrl, string $sessionAccessCode, string $username, string $password): array
{
    $loginUrl = rtrim($panelUrl, '/') . '/' . trim($sessionAccessCode, '/') . '/login';
    $body = http_build_query([
        'username' => $username,
        'password' => $password,
        'referrer' => '',
        'login' => 'Login',
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $loginUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_COOKIEFILE => '', // activa el motor de cookies en memoria (sin archivo)
    ]);
    $htmlBody = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($htmlBody === false) {
        curl_close($ch);
        return ['ok' => false, 'ch' => null, 'error' => 'No se pudo conectar al panel: ' . $err];
    }
    // Se comprobó en producción (2026-07-26): un Access Code de sesión mal
    // escrito (por ejemplo, con un carácter faltante) devuelve HTTP 404 sin
    // ninguno de los mensajes de abajo, y ANTES de este chequeo quedaba
    // marcado como "login exitoso" por defecto — el probador de conexión
    // reportaba éxito incluso con usuario/contraseña incorrectos, porque el
    // verdadero problema (URL/Access Code de sesión inválido) no se
    // detectaba en absoluto. Por eso ahora se exige HTTP 2xx antes de mirar
    // el contenido.
    if ($httpCode < 200 || $httpCode >= 300) {
        curl_close($ch);
        return ['ok' => false, 'ch' => null, 'error' => "El panel respondió HTTP $httpCode al intentar iniciar sesión (revisa la URL del panel y el Access Code de sesión)."];
    }
    if (stripos($htmlBody, 'Incorrect username or password') !== false) {
        curl_close($ch);
        return ['ok' => false, 'ch' => null, 'error' => 'Usuario o contraseña del panel incorrectos.'];
    }
    // Antes se asumía éxito por defecto salvo que apareciera 'Login' sin '|
    // Dashboard' — ahora se exige encontrar la marca real del dashboard
    // para considerar el login exitoso, en vez de asumirlo por defecto
    // (mismo espíritu del chequeo de arriba: solo se confía en una
    // confirmación positiva, no en la ausencia de un mensaje de error
    // conocido).
    if (stripos($htmlBody, '| Dashboard') === false) {
        curl_close($ch);
        return ['ok' => false, 'ch' => null, 'error' => 'El panel no confirmó el login (revisa la URL/Access Code de sesión).'];
    }

    return ['ok' => true, 'ch' => $ch, 'error' => null];
}

function xui_session_cleanup($ch): void
{
    if ($ch) {
        curl_close($ch);
    }
}

/**
 * GET del formulario de edición de un canal (reutilizando el handle ya
 * logueado) y extracción de todos sus campos actuales (name => value), para
 * poder reenviarlos completos y no pisar configuración que no nos interesa
 * tocar.
 */
function xui_session_get_stream_fields($ch, string $panelUrl, string $sessionAccessCode, int $streamId): array
{
    $url = rtrim($panelUrl, '/') . '/' . trim($sessionAccessCode, '/') . '/stream?id=' . $streamId;
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $html = curl_exec($ch);
    $err = curl_error($ch);

    if ($html === false) {
        return ['ok' => false, 'fields' => [], 'error' => 'No se pudo leer el formulario del canal: ' . $err];
    }
    return xui_session_parse_stream_form_html($html, $streamId);
}

/**
 * Parsea el HTML del formulario de edición de un canal (compartido entre la
 * lectura secuencial y la lectura en tanda/paralelo de más abajo) y extrae
 * todos sus campos actuales (name => value), para poder reenviarlos
 * completos y no pisar configuración que no nos interesa tocar.
 */
function xui_session_parse_stream_form_html(string $html, int $streamId): array
{
    if (stripos($html, 'id="stream_form"') === false) {
        return ['ok' => false, 'fields' => [], 'error' => 'No se encontró el canal (id ' . $streamId . ') o la sesión ya no es válida.'];
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $fields = [];
    foreach ($xpath->query('//form[@id="stream_form"]//input') as $el) {
        $name = $el->getAttribute('name');
        if ($name === '') {
            continue;
        }
        $type = $el->getAttribute('type');
        if ($type === 'checkbox' || $type === 'radio') {
            if ($el->hasAttribute('checked')) {
                $fields[] = [$name, $el->getAttribute('value') ?: 'on'];
            }
            continue;
        }
        $fields[] = [$name, $el->getAttribute('value')];
    }
    foreach ($xpath->query('//form[@id="stream_form"]//select') as $el) {
        $name = $el->getAttribute('name');
        if ($name === '') {
            continue;
        }
        $multiple = $el->hasAttribute('multiple');
        $selected = [];
        foreach ($xpath->query('.//option[@selected]', $el) as $opt) {
            $selected[] = $opt->getAttribute('value');
        }
        // Un <select> simple sin ninguna opción "selected" explícita en el
        // HTML igual tiene un valor real (la primera opción) — el navegador
        // lo manda así, y si lo omitimos el panel puede rechazar el guardado
        // entero por un campo "faltante" (se comprobó en pruebas).
        if (!$selected && !$multiple) {
            $firstOpt = $xpath->query('.//option', $el)->item(0);
            if ($firstOpt) {
                $selected[] = $firstOpt->getAttribute('value');
            }
        }
        foreach ($selected as $v) {
            $fields[] = [$name, $v];
        }
    }
    foreach ($xpath->query('//form[@id="stream_form"]//textarea') as $el) {
        $name = $el->getAttribute('name');
        if ($name === '') {
            continue;
        }
        $fields[] = [$name, trim($el->textContent)];
    }

    return ['ok' => true, 'fields' => $fields, 'error' => null];
}

/**
 * Extrae la cookie de sesión (PHPSESSID) de un handle ya logueado, como
 * string lista para usar en un header "Cookie:" manual. Se necesita porque
 * las funciones "_batch" de abajo no reutilizan el mismo $ch (curl_multi
 * necesita un handle por conexión simultánea) — en vez de eso, todas las
 * conexiones en paralelo mandan esta misma cookie a mano.
 */
function xui_session_extract_cookie($ch): ?string
{
    $list = curl_getinfo($ch, CURLINFO_COOKIELIST);
    if (!is_array($list)) {
        return null;
    }
    foreach ($list as $line) {
        if (preg_match('/PHPSESSID\t([^\t\r\n]+)/', $line, $m)) {
            return 'PHPSESSID=' . $m[1];
        }
    }
    return null;
}

/**
 * Ejecuta muchas peticiones HTTP EN PARALELO contra el panel usando una
 * cookie de sesión ya autenticada, con CONCURRENCIA ADAPTATIVA (ver
 * xui_adaptive_multi_exec en includes/XuiClient.php) — la base de
 * xui_session_get_stream_fields_batch() y xui_session_assign_stream_batch()
 * de más abajo. Se probó en el panel real (lecturas y guardados de 20
 * canales a la vez) que el panel SÍ los atiende en paralelo de verdad (no
 * los pone en fila por compartir la misma sesión), con velocidades de 5-7x
 * sobre hacerlo uno por uno y sin mezclar datos entre canales.
 *
 * $requests: [key => ['url' => string, 'post' => array|null]] (post=null
 * significa GET). Devuelve [key => ['ok', 'body', 'http_code', 'error']].
 */
function xui_session_parallel_requests(array $requests, string $cookie, int $startConcurrency = 15): array
{
    $curlOptsList = [];
    foreach ($requests as $key => $req) {
        $opts = [
            CURLOPT_URL => $req['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Cookie: ' . $cookie],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ];
        if ($req['post'] !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $req['post'];
        }
        $curlOptsList[$key] = $opts;
    }

    $raw = xui_adaptive_multi_exec($curlOptsList, $startConcurrency);

    $results = [];
    foreach ($requests as $key => $req) {
        $r = $raw[$key] ?? null;
        $results[$key] = $r
            ? ['ok' => $r['ok'], 'body' => $r['body'], 'http_code' => $r['http_code'], 'error' => $r['error']]
            : ['ok' => false, 'body' => null, 'http_code' => 0, 'error' => 'Sin respuesta del panel.'];
    }
    return $results;
}

/**
 * Como xui_session_get_stream_fields(), pero para muchos canales A LA VEZ
 * (ventana de $concurrency). $streamIds: array de ids. Devuelve
 * [streamId => ['ok','fields','error']].
 */
function xui_session_get_stream_fields_batch(string $panelUrl, string $sessionAccessCode, string $cookie, array $streamIds, int $concurrency = 15): array
{
    $base = rtrim($panelUrl, '/') . '/' . trim($sessionAccessCode, '/') . '/stream?id=';
    $requests = [];
    foreach ($streamIds as $id) {
        $requests[$id] = ['url' => $base . $id, 'post' => null];
    }
    $raw = xui_session_parallel_requests($requests, $cookie, $concurrency);

    $out = [];
    foreach ($streamIds as $id) {
        $r = $raw[$id] ?? null;
        if (!$r || !$r['ok']) {
            $out[$id] = ['ok' => false, 'fields' => [], 'error' => $r['error'] ?? 'Sin respuesta del panel.'];
            continue;
        }
        $out[$id] = xui_session_parse_stream_form_html($r['body'], $id);
    }
    return $out;
}

/**
 * Como xui_session_assign_stream(), pero para muchos canales A LA VEZ: lee
 * el formulario de todos en paralelo, arma el POST de cada uno, y manda
 * todos los guardados en paralelo también (2 tandas en vez de 2×N pasos
 * secuenciales). $servers/$liveServerIds/$onDemandServerIds: igual que en
 * xui_session_assign_stream(). Devuelve [streamId => ['ok','error']].
 */
function xui_session_assign_stream_batch(
    string $panelUrl,
    string $sessionAccessCode,
    string $cookie,
    array $streamIds,
    array $servers,
    array $liveServerIds,
    array $onDemandServerIds,
    int $concurrency = 15,
    ?int $llod = null
): array {
    $out = [];
    if (!$streamIds) {
        return $out;
    }

    $baselines = xui_session_get_stream_fields_batch($panelUrl, $sessionAccessCode, $cookie, $streamIds, $concurrency);

    $serverTree = xui_session_build_server_tree($servers, $liveServerIds);
    $strip = ['server_tree_data', 'od_tree_data', 'on_demand[]'];
    if ($llod !== null) {
        $strip[] = 'llod';
    }
    $postRequests = [];
    $url = rtrim($panelUrl, '/') . '/' . trim($sessionAccessCode, '/') . '/post.php?action=stream&referer=';
    foreach ($streamIds as $id) {
        $baseline = $baselines[$id];
        if (!$baseline['ok']) {
            $out[$id] = ['ok' => false, 'error' => $baseline['error']];
            continue;
        }
        $fields = array_values(array_filter($baseline['fields'], function ($f) use ($strip) {
            return !in_array($f[0], $strip, true);
        }));
        $fields[] = ['server_tree_data', $serverTree];
        $fields[] = ['od_tree_data', ''];
        foreach ($onDemandServerIds as $sid) {
            $fields[] = ['on_demand[]', (string)$sid];
        }
        if ($llod !== null) {
            $fields[] = ['llod', (string)$llod];
        }

        $counts = [];
        $post = [];
        foreach ($fields as [$name, $value]) {
            if (substr($name, -2) === '[]') {
                $b = substr($name, 0, -2);
                $i = $counts[$b] = ($counts[$b] ?? -1) + 1;
                $post[$b . '[' . $i . ']'] = $value;
            } else {
                $post[$name] = $value;
            }
        }
        $postRequests[$id] = ['url' => $url, 'post' => $post];
    }

    if ($postRequests) {
        $saveResults = xui_session_parallel_requests($postRequests, $cookie, $concurrency);
        foreach ($postRequests as $id => $_) {
            $r = $saveResults[$id] ?? null;
            if (!$r || !$r['ok']) {
                $out[$id] = ['ok' => false, 'error' => $r['error'] ?? 'Sin respuesta del panel.'];
                continue;
            }
            $json = json_decode($r['body'], true);
            $out[$id] = (is_array($json) && !empty($json['result']))
                ? ['ok' => true, 'error' => null]
                : ['ok' => false, 'error' => 'El panel no confirmó la asignación de servidor/on-demand.'];
        }
    }

    return $out;
}

/**
 * Construye el JSON de "server_tree_data" (ver nota de arriba): el nodo
 * raíz "source" (rama Online) más un nodo por cada servidor asignado.
 * $servers es la lista completa de get_servers (id => nombre); $assignedIds
 * son los ids que deben quedar asignados a este canal.
 */
function xui_session_build_server_tree(array $servers, array $assignedIds): string
{
    $assignedIds = array_map('strval', $assignedIds);
    $tree = [[
        'id' => 'source',
        'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Online</strong>",
        'icon' => 'mdi mdi-play',
        'li_attr' => ['id' => 'source'],
        'a_attr' => ['href' => '#', 'id' => 'source_anchor'],
        'state' => ['loaded' => true, 'opened' => true, 'selected' => false, 'disabled' => false],
        'data' => new stdClass(),
        'parent' => '#',
    ]];
    foreach ($servers as $id => $name) {
        if (!in_array((string)$id, $assignedIds, true)) {
            continue;
        }
        $tree[] = [
            'id' => (string)$id,
            'text' => $name,
            'icon' => 'mdi mdi-server-network',
            'li_attr' => ['id' => (string)$id],
            'a_attr' => ['href' => '#', 'id' => $id . '_anchor'],
            'state' => ['loaded' => true, 'opened' => true, 'selected' => true, 'disabled' => false],
            'data' => new stdClass(),
            'parent' => 'source',
        ];
    }
    return json_encode($tree);
}

/**
 * Asigna servidor(es) live + servidor(es) on-demand a un canal ya creado.
 * $ch debe venir de xui_session_login() ya autenticado. $servers:
 * [id => server_name] (de get_servers, api_key). $liveServerIds y
 * $onDemandServerIds: ids de servidor a asignar (en este proyecto siempre
 * se usan los mismos para ambos, ver nota de arriba).
 */
function xui_session_assign_stream(
    $ch,
    string $panelUrl,
    string $sessionAccessCode,
    int $streamId,
    array $servers,
    array $liveServerIds,
    array $onDemandServerIds,
    ?int $llod = null
): array {
    $baseline = xui_session_get_stream_fields($ch, $panelUrl, $sessionAccessCode, $streamId);
    if (!$baseline['ok']) {
        return ['ok' => false, 'error' => $baseline['error']];
    }

    $strip = ['server_tree_data', 'od_tree_data', 'on_demand[]'];
    if ($llod !== null) {
        $strip[] = 'llod';
    }
    $fields = array_filter($baseline['fields'], function ($f) use ($strip) {
        return !in_array($f[0], $strip, true);
    });
    $fields = array_values($fields);
    $fields[] = ['server_tree_data', xui_session_build_server_tree($servers, $liveServerIds)];
    $fields[] = ['od_tree_data', ''];
    foreach ($onDemandServerIds as $id) {
        $fields[] = ['on_demand[]', (string)$id];
    }
    if ($llod !== null) {
        $fields[] = ['llod', (string)$llod];
    }

    // curl multipart necesita un arreglo asociativo; los campos repetidos
    // (arrays tipo "nombre[]") se indexan a mano para no perder ninguno —
    // el panel los interpreta igual que "nombre[]" repetido.
    $counts = [];
    $post = [];
    foreach ($fields as [$name, $value]) {
        if (substr($name, -2) === '[]') {
            $base = substr($name, 0, -2);
            $i = $counts[$base] = ($counts[$base] ?? -1) + 1;
            $post[$base . '[' . $i . ']'] = $value;
        } else {
            $post[$name] = $value;
        }
    }

    $url = rtrim($panelUrl, '/') . '/' . trim($sessionAccessCode, '/') . '/post.php?action=stream&referer=';
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => 'No se pudo contactar al panel (sesión): ' . $err];
    }
    $json = json_decode($raw, true);
    if (!is_array($json) || empty($json['result'])) {
        return ['ok' => false, 'error' => 'El panel no confirmó la asignación de servidor/on-demand.'];
    }
    return ['ok' => true, 'error' => null];
}

/**
 * Trae la lista de servidores (id => nombre) usando el mismo api_key ya
 * guardado — get_servers es de solo lectura y ya está permitida en
 * api/xui_test.php.
 */
function xui_session_list_servers(string $panelUrl, string $accessCode, string $apiKey): array
{
    $result = xui_call($panelUrl, $accessCode, $apiKey, 'get_servers');
    if (!$result['ok'] || !is_array($result['json'])) {
        return [];
    }
    $servers = [];
    foreach ($result['json'] as $s) {
        if (isset($s['id'])) {
            $servers[(string)$s['id']] = $s['server_name'] ?? ('Servidor #' . $s['id']);
        }
    }
    return $servers;
}

/**
 * Envoltorio de conveniencia para los endpoints (xui_channels.php,
 * xui_bulk_import.php): hace login con las credenciales de sesión guardadas
 * en $panel (fila de xui_panels), asigna $serverIds como servidor "en vivo"
 * y, si $onDemand es true, también como "on-demand" (mismos ids), y cierra
 * la sesión. Si $panel no tiene login de sesión guardado o $serverIds viene
 * vacío, no hace nada (no es un error — la asignación es opcional).
 * $llod: 0=Disabled, 1=LLOD v2 - FFMPEG, 2=LLOD v3 - PHP, null=no tocarlo.
 */
function xui_session_assign_using_panel(array $panel, int $streamId, array $serverIds, bool $onDemand = true, ?int $llod = null): array
{
    if (empty($serverIds) || empty($panel['panel_username']) || empty($panel['panel_password_enc'])) {
        return ['ok' => true, 'skipped' => true, 'error' => null];
    }
    require_once __DIR__ . '/Crypto.php';
    $password = xui_decrypt($panel['panel_password_enc']);
    if ($password === null) {
        return ['ok' => false, 'skipped' => false, 'error' => 'No se pudo descifrar la contraseña de sesión del panel.'];
    }

    $login = xui_session_login($panel['panel_url'], $panel['session_access_code'], $panel['panel_username'], $password);
    if (!$login['ok']) {
        return ['ok' => false, 'skipped' => false, 'error' => 'Login de sesión falló: ' . $login['error']];
    }

    $servers = xui_session_list_servers($panel['panel_url'], $panel['access_code'], $panel['api_key']);
    $onDemandServerIds = $onDemand ? $serverIds : [];
    $result = xui_session_assign_stream($login['ch'], $panel['panel_url'], $panel['session_access_code'], $streamId, $servers, $serverIds, $onDemandServerIds, $llod);
    xui_session_cleanup($login['ch']);

    return ['ok' => $result['ok'], 'skipped' => false, 'error' => $result['error'] ?? null];
}

/**
 * Como xui_session_assign_using_panel(), pero para MUCHOS canales a la vez
 * (usado en la importación masiva) — hace login UNA sola vez para todo el
 * lote y usa xui_session_assign_stream_batch() (lecturas y guardados en
 * paralelo) en vez de repetir login+lectura+guardado por cada canal.
 * Devuelve ['ok', 'skipped', 'error', 'results' => [streamId => ['ok','error']]].
 * $llod: 0=Disabled, 1=LLOD v2 - FFMPEG, 2=LLOD v3 - PHP, null=no tocarlo.
 */
function xui_session_assign_batch_using_panel(array $panel, array $streamIds, array $serverIds, bool $onDemand = true, int $concurrency = 15, ?int $llod = null): array
{
    if (empty($streamIds) || empty($serverIds) || empty($panel['panel_username']) || empty($panel['panel_password_enc'])) {
        return ['ok' => true, 'skipped' => true, 'error' => null, 'results' => []];
    }
    require_once __DIR__ . '/Crypto.php';
    $password = xui_decrypt($panel['panel_password_enc']);
    if ($password === null) {
        return ['ok' => false, 'skipped' => false, 'error' => 'No se pudo descifrar la contraseña de sesión del panel.', 'results' => []];
    }

    $login = xui_session_login($panel['panel_url'], $panel['session_access_code'], $panel['panel_username'], $password);
    if (!$login['ok']) {
        return ['ok' => false, 'skipped' => false, 'error' => 'Login de sesión falló: ' . $login['error'], 'results' => []];
    }
    $cookie = xui_session_extract_cookie($login['ch']);
    xui_session_cleanup($login['ch']); // ya no hace falta el handle: las llamadas en tanda usan la cookie a mano
    if (!$cookie) {
        return ['ok' => false, 'skipped' => false, 'error' => 'No se pudo obtener la cookie de sesión tras el login.', 'results' => []];
    }

    $servers = xui_session_list_servers($panel['panel_url'], $panel['access_code'], $panel['api_key']);
    $onDemandServerIds = $onDemand ? $serverIds : [];
    $results = xui_session_assign_stream_batch($panel['panel_url'], $panel['session_access_code'], $cookie, $streamIds, $servers, $serverIds, $onDemandServerIds, $concurrency, $llod);

    return ['ok' => true, 'skipped' => false, 'error' => null, 'results' => $results];
}

/**
 * "Channel Order" del panel (botón "A to Z"), reversado igual que el resto
 * de este archivo: NO existe en la API pública (api_key) — el panel guarda
 * UN SOLO orden global por tipo de contenido (streams/movies/series/radio),
 * mezclando TODAS las categorías live en una sola lista. La página
 * {panel_url}/{session_access_code}/channel_order tiene 4 pares de
 * <select multiple id="sort_<tipo>_l"/"sort_<tipo>_r">: el botón "A to Z"
 * (función JS AtoZ()) ordena SOLO la lista "_l" (por texto, case-insensitive)
 * y copia ese mismo HTML a "_r" (que es puramente un espejo visual, nunca se
 * lee al guardar). Al hacer submit, el JS junta las 4 listas "_l" (stream,
 * movie, series, radio, EN ESE ORDEN) en un solo arreglo y lo manda como
 * "stream_order_array" (JSON) a post.php?action=channel_order&referer= —
 * sin token/CSRF adicional. Por eso xui_session_save_channel_order() exige
 * las 4 listas juntas: si se omiten movie/series/radio, el panel las
 * interpretaría como "vaciar" esas listas (no es un update parcial, mismo
 * patrón que server_tree_data/bouquets en el resto de este archivo).
 */
function xui_session_get_channel_order($ch, string $panelUrl, string $sessionAccessCode): array
{
    $url = rtrim($panelUrl, '/') . '/' . trim($sessionAccessCode, '/') . '/channel_order';
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $html = curl_exec($ch);
    $err = curl_error($ch);
    if ($html === false) {
        return ['ok' => false, 'order' => [], 'error' => 'No se pudo leer el orden de canales: ' . $err];
    }
    return xui_session_parse_channel_order_html($html);
}

function xui_session_parse_channel_order_html(string $html): array
{
    if (stripos($html, 'sort_stream_l') === false) {
        return ['ok' => false, 'order' => [], 'error' => 'No se encontró la página de orden de canales (sesión inválida o vista distinta a la esperada).'];
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $readList = function (string $selectId) use ($xpath): array {
        $ids = [];
        $select = $xpath->query("//select[@id='$selectId']")->item(0);
        if (!$select) {
            return $ids;
        }
        foreach ($xpath->query('.//option', $select) as $opt) {
            $ids[] = $opt->getAttribute('value');
        }
        return $ids;
    };

    return [
        'ok' => true,
        'error' => null,
        'order' => [
            'stream' => $readList('sort_stream_l'),
            'movie' => $readList('sort_movie_l'),
            'series' => $readList('sort_series_l'),
            'radio' => $readList('sort_radio_l'),
        ],
    ];
}

/**
 * Guarda un nuevo orden global (ver nota de arriba: SIEMPRE hay que mandar
 * las 4 listas juntas, aunque solo se haya modificado "stream"). $order
 * debe traer las 4 claves (stream/movie/series/radio), cada una un arreglo
 * de ids en el orden deseado — normalmente se parte de
 * xui_session_get_channel_order() y solo se reordena la clave que interesa.
 */
function xui_session_save_channel_order($ch, string $panelUrl, string $sessionAccessCode, array $order): array
{
    $combined = array_merge(
        array_values($order['stream'] ?? []),
        array_values($order['movie'] ?? []),
        array_values($order['series'] ?? []),
        array_values($order['radio'] ?? [])
    );
    $post = ['stream_order_array' => json_encode($combined)];

    $url = rtrim($panelUrl, '/') . '/' . trim($sessionAccessCode, '/') . '/post.php?action=channel_order&referer=';
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    if ($raw === false) {
        return ['ok' => false, 'error' => 'No se pudo guardar el nuevo orden (sesión): ' . $err];
    }
    $json = json_decode($raw, true);
    if (!is_array($json) || empty($json['result'])) {
        return ['ok' => false, 'error' => 'El panel no confirmó el guardado del nuevo orden de canales.'];
    }
    return ['ok' => true, 'error' => null];
}

/**
 * Clave de comparación para ordenar nombres de canal A-Z: quita un sufijo
 * final entre corchetes (ej. "AXN [Vodafone ES]" -> "AXN") antes de
 * comparar, porque "[" pesa más que las letras en ASCII y por eso el
 * ordenamiento por texto completo (el que hace el botón "A to Z" nativo del
 * panel) deja canales como "AXN Movies [Vodafone ES]" ANTES que
 * "AXN [Vodafone ES]" — no es el orden que un humano espera. Solo afecta la
 * comparación; el nombre real del canal no se toca.
 */
function xui_channel_sort_key(string $name): string
{
    $stripped = preg_replace('/\s*\[[^\]]*\]\s*$/', '', trim($name));
    return mb_strtoupper(trim($stripped));
}

/**
 * Ordena alfabéticamente (A-Z, mismo criterio que el botón "A to Z" del
 * panel: comparación case-insensitive por nombre visible) los canales EN
 * VIVO de una o más categorías XUI·ONE, SIN afectar el orden ni la
 * agrupación de las demás categorías (ni de movies/series/radio, que se
 * reenvían intactas) — y SIN tocar nada de la herramienta "Categories"
 * (orden/nombre de categorías), solo el "Channel Order" (orden de canales).
 *
 * Cómo se logra sin desordenar el resto: el orden global de "streams" es
 * UNA sola lista que mezcla todas las categorías. Se recorren las
 * posiciones tal cual están y se anota en qué posiciones aparece un canal
 * de CUALQUIERA de las categorías pedidas (esas posiciones, como conjunto,
 * nunca cambian — solo se decide qué canal va en cada una); todos los ids
 * encontrados en esas posiciones se juntan en UN SOLO grupo y se ordenan
 * por nombre entre sí (no por categoría), para que quede una sola
 * secuencia A-Z continua en vez de bloques separados por categoría. Los
 * canales que no son de ninguna categoría pedida nunca cambian de posición
 * ni de vecino.
 */
function xui_session_sort_live_categories_az(array $panel, array $xuiCategoryIds): array
{
    if (empty($xuiCategoryIds) || empty($panel['panel_username']) || empty($panel['panel_password_enc'])) {
        return ['ok' => true, 'skipped' => true, 'error' => null, 'sorted_count' => 0];
    }
    require_once __DIR__ . '/Crypto.php';
    $password = xui_decrypt($panel['panel_password_enc']);
    if ($password === null) {
        return ['ok' => false, 'skipped' => false, 'error' => 'No se pudo descifrar la contraseña de sesión del panel.', 'sorted_count' => 0];
    }

    // Nombre + categorías de cada canal, vía api_key (fuente de verdad para
    // comparar nombres y saber a qué categoría pertenece cada id).
    $streamsRes = xui_call_all_pages($panel['panel_url'], $panel['access_code'], $panel['api_key'], 'get_streams');
    if (!$streamsRes['ok']) {
        return ['ok' => false, 'skipped' => false, 'error' => 'No se pudo leer los canales del panel: ' . ($streamsRes['error'] ?? ''), 'sorted_count' => 0];
    }
    $nameById = [];
    $catIdsById = [];
    foreach (($streamsRes['json']['data'] ?? []) as $s) {
        $id = (string)($s['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $nameById[$id] = $s['stream_display_name'] ?? '';
        $catIdsById[$id] = array_map('strval', json_decode($s['category_id'] ?? '[]', true) ?: []);
    }

    $login = xui_session_login($panel['panel_url'], $panel['session_access_code'], $panel['panel_username'], $password);
    if (!$login['ok']) {
        return ['ok' => false, 'skipped' => false, 'error' => 'Login de sesión falló: ' . $login['error'], 'sorted_count' => 0];
    }

    $current = xui_session_get_channel_order($login['ch'], $panel['panel_url'], $panel['session_access_code']);
    if (!$current['ok']) {
        xui_session_cleanup($login['ch']);
        return ['ok' => false, 'skipped' => false, 'error' => $current['error'], 'sorted_count' => 0];
    }
    $streamOrder = $current['order']['stream'];

    $targetSet = array_map('strval', $xuiCategoryIds);
    $positions = []; // posiciones (dentro de $streamOrder) de CUALQUIER canal de las categorías pedidas
    foreach ($streamOrder as $pos => $id) {
        foreach ($catIdsById[(string)$id] ?? [] as $cid) {
            if (in_array($cid, $targetSet, true)) {
                $positions[] = $pos;
                break; // un canal solo cuenta una vez aunque esté en varias categorías pedidas
            }
        }
    }

    $ids = array_map(fn($p) => $streamOrder[$p], $positions);
    usort($ids, function ($a, $b) use ($nameById) {
        $an = xui_channel_sort_key($nameById[(string)$a] ?? '');
        $bn = xui_channel_sort_key($nameById[(string)$b] ?? '');
        return $an <=> $bn;
    });
    foreach ($positions as $i => $pos) {
        $streamOrder[$pos] = $ids[$i];
    }
    $sortedCount = count($ids);

    $current['order']['stream'] = $streamOrder;
    $save = xui_session_save_channel_order($login['ch'], $panel['panel_url'], $panel['session_access_code'], $current['order']);
    xui_session_cleanup($login['ch']);

    if (!$save['ok']) {
        return ['ok' => false, 'skipped' => false, 'error' => $save['error'], 'sorted_count' => 0];
    }
    return ['ok' => true, 'skipped' => false, 'error' => null, 'sorted_count' => $sortedCount];
}
