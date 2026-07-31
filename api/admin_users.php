<?php
/**
 * Administración de cuentas — SOLO para usuarios con role='admin'
 * (ver includes/auth.php::auth_require_admin()). Un usuario normal no
 * puede llegar aquí ni por la interfaz (el botón 🛡️ solo se muestra si
 * api/me.php dice role='admin') ni llamando la API directo (esta línea
 * corta con 403 antes de tocar cualquier dato). A propósito NO expone ni
 * modifica los datos privados de cada usuario (proveedores, canales,
 * conexión XUI·ONE, Telegram) — solo la cuenta en sí (usuario, rol, estado).
 *
 * GET                                            -> lista todos los usuarios
 * POST {action:'create', username, password, role}
 *   -> crea una cuenta nueva directamente en status='approved' (a
 *      diferencia de api/register.php, que nace 'pending' — si el admin la
 *      está creando él mismo, no hace falta el paso de aprobación).
 * POST {action:'update', user_id, username, password?, role}
 *   -> edita usuario/rol; password es opcional (vacío = no cambiarla). No
 *      permite quitarle el rol admin al último administrador que queda.
 * POST {action:'approve', user_id}               -> status='approved'
 * POST {action:'disable', user_id}               -> status='disabled'
 * POST {action:'delete', user_id}                -> borra la cuenta (cascada
 *   por FK: se lleva también todos sus datos). No permite borrarse a sí
 *   mismo, y NUNCA permite borrar una cuenta con role='admin' (sin importar
 *   cuántos otros admins existan) — un admin solo se puede modificar
 *   (ej. bajarle el rol a "user"), nunca eliminar directamente.
 */

require_once __DIR__ . '/bootstrap.php';

auth_require_admin();

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Admins siempre arriba (sin importar cuándo se crearon); dentro de cada
    // grupo (admin / resto), pendientes primero para que salten a la vista,
    // luego por fecha de creación descendente.
    $stmt = $pdo->query("SELECT id, username, role, status, created_at FROM users ORDER BY role = 'admin' DESC, status = 'pending' DESC, created_at DESC");
    respond(['users' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('Método no soportado', 405);
}

const ADMIN_USERS_ROLES = ['admin', 'user'];

$body = json_input();
$action = $body['action'] ?? '';
$currentUserId = (int)$_SESSION['user_id'];

/** Cuenta cuántos admins existen aparte de $excludeUserId (o de nadie, si es null). */
function admin_users_other_admins_count(PDO $pdo, ?int $excludeUserId = null): int
{
    if ($excludeUserId !== null) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND id != ?");
        $stmt->execute([$excludeUserId]);
    } else {
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    }
    return (int)$stmt->fetchColumn();
}

if ($action === 'create') {
    $username = trim($body['username'] ?? '');
    $password = (string)($body['password'] ?? '');
    $role = trim($body['role'] ?? 'user');

    if (mb_strlen($username) < 3) {
        respond_error('El usuario debe tener al menos 3 caracteres.');
    }
    if (strlen($password) < 8) {
        respond_error('La contraseña debe tener al menos 8 caracteres.');
    }
    if (!in_array($role, ADMIN_USERS_ROLES, true)) {
        respond_error('Rol inválido.');
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        respond_error('Ese usuario ya existe.', 409);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, status) VALUES (?, ?, ?, 'approved')");
    $stmt->execute([$username, $hash, $role]);
    respond(['ok' => true, 'id' => (int)$pdo->lastInsertId()], 201);
}

// A partir de aquí todas las acciones necesitan un user_id existente.
$userId = (int)($body['user_id'] ?? 0);
if (!$userId) {
    respond_error('Falta user_id.');
}
$stmt = $pdo->prepare('SELECT id, role, status FROM users WHERE id = ?');
$stmt->execute([$userId]);
$target = $stmt->fetch();
if (!$target) {
    respond_error('Usuario no encontrado.', 404);
}

if ($action === 'update') {
    $username = trim($body['username'] ?? '');
    $password = (string)($body['password'] ?? '');
    $role = trim($body['role'] ?? $target['role']);

    if (mb_strlen($username) < 3) {
        respond_error('El usuario debe tener al menos 3 caracteres.');
    }
    if (!in_array($role, ADMIN_USERS_ROLES, true)) {
        respond_error('Rol inválido.');
    }
    if ($password !== '' && strlen($password) < 8) {
        respond_error('La contraseña debe tener al menos 8 caracteres.');
    }
    if ($target['role'] === 'admin' && $role !== 'admin' && admin_users_other_admins_count($pdo, $userId) === 0) {
        respond_error('No puedes quitarle el rol de administrador al único admin que queda.', 400);
    }

    $dupStmt = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
    $dupStmt->execute([$username, $userId]);
    if ($dupStmt->fetch()) {
        respond_error('Ese usuario ya existe.', 409);
    }

    if ($password !== '') {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET username = ?, password_hash = ?, role = ? WHERE id = ?')
            ->execute([$username, $hash, $role, $userId]);
    } else {
        $pdo->prepare('UPDATE users SET username = ?, role = ? WHERE id = ?')
            ->execute([$username, $role, $userId]);
    }
    respond(['ok' => true]);
}

if ($action === 'approve') {
    $pdo->prepare("UPDATE users SET status = 'approved' WHERE id = ?")->execute([$userId]);
    respond(['ok' => true]);
}

if ($action === 'disable') {
    if ($userId === $currentUserId) {
        respond_error('No puedes deshabilitarte a ti mismo.', 400);
    }
    if ($target['role'] === 'admin' && admin_users_other_admins_count($pdo, $userId) === 0) {
        respond_error('No puedes deshabilitar al único administrador que queda.', 400);
    }
    $pdo->prepare("UPDATE users SET status = 'disabled' WHERE id = ?")->execute([$userId]);
    respond(['ok' => true]);
}

if ($action === 'delete') {
    if ($userId === $currentUserId) {
        respond_error('No puedes eliminarte a ti mismo.', 400);
    }
    // Una cuenta admin NUNCA se elimina, sin importar cuántos otros admins
    // existan — solo se puede modificar (ej. bajarle el rol a "user" con
    // action:'update', y a partir de ahí sí se puede eliminar como usuario
    // normal). Evita perder por accidente una cuenta administradora.
    if ($target['role'] === 'admin') {
        respond_error('No se puede eliminar una cuenta de administrador. Cámbiale el rol a "user" primero si de verdad quieres eliminarla.', 400);
    }
    // Cascada por FK: se lleva también todos los datos de ese usuario
    // (proveedores, canales, conexión XUI·ONE, Telegram, etc.).
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    respond(['ok' => true]);
}

respond_error('Acción no soportada.', 400);
