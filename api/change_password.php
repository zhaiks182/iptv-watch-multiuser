<?php

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('Método no soportado', 405);
}

$body = json_input();
$currentPassword = (string)($body['current_password'] ?? '');
$newPassword = (string)($body['new_password'] ?? '');

if ($currentPassword === '' || $newPassword === '') {
    respond_error('Completa la contraseña actual y la nueva.');
}
if (strlen($newPassword) < 8) {
    respond_error('La nueva contraseña debe tener al menos 8 caracteres.');
}

$pdo = get_pdo();
$userId = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
    respond_error('La contraseña actual no es correcta.', 401);
}

$newHash = password_hash($newPassword, PASSWORD_DEFAULT);
$stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
$stmt->execute([$newHash, $userId]);

respond(['ok' => true, 'message' => 'Contraseña actualizada correctamente.']);
