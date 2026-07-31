<?php
/**
 * CRUD de conexiones guardadas para la integración XUI·ONE.
 * GET                                     -> listar todas
 * POST {action:'create', ...}             -> crear
 * POST {action:'update', id, ...}         -> editar
 * POST {action:'delete', id}              -> eliminar
 * POST {action:'set_active', id}          -> marcar como la única conexión activa
 *
 * Campos de sesión web (session_access_code, panel_username,
 * panel_password) son OPCIONALES — habilitan la asignación automática de
 * servidor/on-demand al crear canales (ver includes/XuiSession.php), que
 * usa el login real del panel (usuario/contraseña), NO el api_key. Si se
 * dejan vacíos, la conexión sigue funcionando igual que antes (solo sin esa
 * asignación automática).
 *
 * "session_access_code" es DISTINTO del "access_code" de la API — se
 * comprobó que pueden ser valores diferentes en el mismo panel, así que
 * nunca se asume que coinciden.
 *
 * La contraseña de sesión se cifra (includes/Crypto.php) antes de guardarse
 * y NUNCA se devuelve en el GET — solo un booleano "has_session_login".
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/XuiClient.php';
require_once __DIR__ . '/../includes/XuiSession.php';
require_once __DIR__ . '/../includes/Crypto.php';

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'];
$userId = (int)$_SESSION['user_id'];

if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT id, name, panel_url, access_code, session_access_code, api_key, panel_username, panel_password_enc, is_active, created_at, updated_at FROM xui_panels WHERE user_id = ? ORDER BY name ASC");
    $stmt->execute([$userId]);
    $panels = $stmt->fetchAll();
    foreach ($panels as &$p) {
        $p['has_session_login'] = !empty($p['panel_username']) && !empty($p['panel_password_enc']);
        unset($p['panel_password_enc']);
    }
    unset($p);
    respond(['panels' => $panels]);
}

if ($method === 'POST') {
    $body = json_input();
    $action = $body['action'] ?? 'create';

    if ($action === 'delete') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) {
            respond_error('Falta el id a eliminar.');
        }
        $stmt = $pdo->prepare("DELETE FROM xui_panels WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        respond(['ok' => true]);
    }

    if ($action === 'set_active') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) {
            respond_error('Falta el id a activar.');
        }
        $stmt = $pdo->prepare("UPDATE xui_panels SET is_active = 0 WHERE user_id = ?");
        $stmt->execute([$userId]);
        $stmt = $pdo->prepare("UPDATE xui_panels SET is_active = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        respond(['ok' => true]);
    }

    // Prueba de conexión SIN guardar nada — usa lo que esté escrito en el
    // formulario en ese momento, para poder confirmar antes de "Guardar".
    if ($action === 'test_api') {
        $panelUrl = rtrim(trim($body['panel_url'] ?? ''), '/');
        $accessCode = trim($body['access_code'] ?? '');
        $apiKey = trim($body['api_key'] ?? '');
        if ($panelUrl === '' || $accessCode === '' || $apiKey === '') {
            respond_error('Completa URL del panel, Access Code y API Key.');
        }
        if (!filter_var($panelUrl, FILTER_VALIDATE_URL)) {
            respond_error('La URL del panel no es válida.');
        }
        session_write_close();
        $check = xui_call($panelUrl, $accessCode, $apiKey, 'user_info');
        if (!$check['ok']) {
            respond_error('No se pudo conectar: ' . ($check['error'] ?? ('HTTP ' . $check['http_code'])), 422);
        }
        // OJO: el panel responde HTTP 200 incluso con un api_key inválido —
        // el error viene en el cuerpo ("status":"STATUS_FAILURE"), no en el
        // código HTTP. Se comprobó en pruebas reales (api_key incorrecta
        // devolvía ok:true si solo se miraba el HTTP). Por eso hay que
        // revisar también el "status" del JSON, igual que en el resto de
        // las acciones de este proyecto.
        if (($check['json']['status'] ?? null) !== 'STATUS_SUCCESS') {
            respond_error('El panel rechazó esos datos: ' . ($check['json']['error'] ?? 'revisa Access Code y API Key.'), 422);
        }
        respond(['ok' => true, 'info' => $check['json'] ?? null]);
    }

    if ($action === 'test_session') {
        $panelUrl = rtrim(trim($body['panel_url'] ?? ''), '/');
        $sessionAccessCode = trim($body['session_access_code'] ?? '');
        $panelUsername = trim($body['panel_username'] ?? '');
        $panelPassword = (string)($body['panel_password'] ?? '');
        $id = (int)($body['id'] ?? 0);

        // Si se está editando una conexión ya guardada y se dejó la
        // contraseña en blanco (para no cambiarla), se prueba con la que
        // ya está guardada en vez de exigir escribirla de nuevo.
        if ($panelPassword === '' && $id) {
            $row = $pdo->prepare("SELECT panel_password_enc FROM xui_panels WHERE id = ? AND user_id = ?");
            $row->execute([$id, $userId]);
            $existingEnc = $row->fetchColumn();
            if ($existingEnc) {
                $panelPassword = xui_decrypt($existingEnc) ?? '';
            }
        }

        if ($panelUrl === '' || $sessionAccessCode === '' || $panelUsername === '' || $panelPassword === '') {
            respond_error('Completa URL del panel, Access Code de sesión, usuario y contraseña.');
        }
        if (!filter_var($panelUrl, FILTER_VALIDATE_URL)) {
            respond_error('La URL del panel no es válida.');
        }
        session_write_close();
        $loginCheck = xui_session_login($panelUrl, $sessionAccessCode, $panelUsername, $panelPassword);
        if (!$loginCheck['ok']) {
            respond_error('No se pudo iniciar sesión: ' . $loginCheck['error'], 422);
        }
        xui_session_cleanup($loginCheck['ch']);
        respond(['ok' => true]);
    }

    $name = trim($body['name'] ?? '');
    $panelUrl = rtrim(trim($body['panel_url'] ?? ''), '/');
    $accessCode = trim($body['access_code'] ?? '');
    $apiKey = trim($body['api_key'] ?? '');
    $sessionAccessCode = trim($body['session_access_code'] ?? '');
    $panelUsername = trim($body['panel_username'] ?? '');
    $panelPassword = (string)($body['panel_password'] ?? '');

    if ($name === '' || $panelUrl === '' || $accessCode === '' || $apiKey === '') {
        respond_error('Completa nombre, URL del panel, Access Code y API Key.');
    }
    if (!filter_var($panelUrl, FILTER_VALIDATE_URL)) {
        respond_error('La URL del panel no es válida.');
    }
    // Los 3 campos de sesión van juntos o no van (no tiene sentido guardar
    // solo el usuario sin contraseña, por ejemplo) — salvo en "update" sin
    // cambiar la contraseña, ver más abajo.
    $wantsSession = $sessionAccessCode !== '' || $panelUsername !== '' || $panelPassword !== '';

    // No se guarda (ni se crea ni se edita) una conexión que en realidad no
    // conecta: se comprobó que una URL mal escrita se guardaba igual y
    // quedaba marcada como si funcionara hasta que alguien la probaba a mano
    // desde "Probar conexión". session_write_close() libera el lock de
    // sesión mientras esperamos la respuesta del panel (puede tardar).
    session_write_close();
    $check = xui_call($panelUrl, $accessCode, $apiKey, 'user_info');
    if (!$check['ok']) {
        respond_error('No se pudo conectar con esos datos (' . ($check['error'] ?? ('HTTP ' . $check['http_code'])) . '). Revisa URL, Access Code y API Key antes de guardar.', 422);
    }
    // OJO: el panel responde HTTP 200 incluso con un api_key inválido — el
    // error viene en el cuerpo ("status":"STATUS_FAILURE"), no en el código
    // HTTP (comprobado en pruebas reales). Por eso también se revisa el
    // "status" del JSON, no solo que la conexión HTTP haya funcionado.
    if (($check['json']['status'] ?? null) !== 'STATUS_SUCCESS') {
        respond_error('El panel rechazó esos datos (' . ($check['json']['error'] ?? 'Access Code o API Key inválidos') . '). Revísalos antes de guardar.', 422);
    }

    if ($action === 'update') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) {
            respond_error('Falta el id a actualizar.');
        }
        $ownStmt = $pdo->prepare('SELECT id FROM xui_panels WHERE id = ? AND user_id = ?');
        $ownStmt->execute([$id, $userId]);
        if (!$ownStmt->fetch()) {
            respond_error('Conexión no encontrada.', 404);
        }

        if ($sessionAccessCode === '' && $panelUsername === '' && $panelPassword === '') {
            // No se tocó nada de sesión: conservar lo que ya había.
            $stmt = $pdo->prepare("UPDATE xui_panels SET name=?, panel_url=?, access_code=?, api_key=? WHERE id=? AND user_id=?");
            $stmt->execute([$name, $panelUrl, $accessCode, $apiKey, $id, $userId]);
            respond(['ok' => true]);
        }

        if ($sessionAccessCode === '' || $panelUsername === '') {
            respond_error('Para la sesión web del panel completa Access Code de sesión y usuario (además de la contraseña si la vas a cambiar).');
        }

        if ($panelPassword !== '') {
            $loginCheck = xui_session_login($panelUrl, $sessionAccessCode, $panelUsername, $panelPassword);
            if (!$loginCheck['ok']) {
                respond_error('No se pudo iniciar sesión en el panel con esos datos: ' . $loginCheck['error'], 422);
            }
            xui_session_cleanup($loginCheck['ch']);
            $passwordEnc = xui_encrypt($panelPassword);
            $stmt = $pdo->prepare("UPDATE xui_panels SET name=?, panel_url=?, access_code=?, api_key=?, session_access_code=?, panel_username=?, panel_password_enc=? WHERE id=? AND user_id=?");
            $stmt->execute([$name, $panelUrl, $accessCode, $apiKey, $sessionAccessCode, $panelUsername, $passwordEnc, $id, $userId]);
        } else {
            // Cambiaron access_code/usuario de sesión pero no la contraseña:
            // se mantiene la contraseña cifrada que ya había.
            $stmt = $pdo->prepare("UPDATE xui_panels SET name=?, panel_url=?, access_code=?, api_key=?, session_access_code=?, panel_username=? WHERE id=? AND user_id=?");
            $stmt->execute([$name, $panelUrl, $accessCode, $apiKey, $sessionAccessCode, $panelUsername, $id, $userId]);
        }
        respond(['ok' => true]);
    }

    // create
    if ($wantsSession) {
        if ($sessionAccessCode === '' || $panelUsername === '' || $panelPassword === '') {
            respond_error('Para habilitar la asignación de servidor/on-demand completa Access Code de sesión, usuario y contraseña del panel (los 3 juntos).');
        }
        $loginCheck = xui_session_login($panelUrl, $sessionAccessCode, $panelUsername, $panelPassword);
        if (!$loginCheck['ok']) {
            respond_error('No se pudo iniciar sesión en el panel con esos datos: ' . $loginCheck['error'], 422);
        }
        xui_session_cleanup($loginCheck['ch']);
    }

    // La primera conexión que crea ESTE usuario queda activa automáticamente.
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM xui_panels WHERE user_id = ?");
    $countStmt->execute([$userId]);
    $isFirst = (int)$countStmt->fetchColumn() === 0;

    $passwordEnc = $panelPassword !== '' ? xui_encrypt($panelPassword) : null;
    $stmt = $pdo->prepare("INSERT INTO xui_panels (user_id, name, panel_url, access_code, session_access_code, api_key, panel_username, panel_password_enc, is_active) VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $userId, $name, $panelUrl, $accessCode,
        $sessionAccessCode !== '' ? $sessionAccessCode : null,
        $apiKey,
        $panelUsername !== '' ? $panelUsername : null,
        $passwordEnc,
        $isFirst ? 1 : 0,
    ]);
    respond(['ok' => true, 'id' => (int)$pdo->lastInsertId()], 201);
}

respond_error('Método no soportado', 405);
