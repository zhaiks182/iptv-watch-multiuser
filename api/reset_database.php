<?php

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('Método no soportado', 405);
}

$body = json_input();
if (empty($body['confirm'])) {
    respond_error('Falta confirmación explícita (confirm: true).');
}

$pdo = get_pdo();
$userId = (int)$_SESSION['user_id'];

// TRUNCATE (usado antes) vacía la tabla ENTERA — ahora que hay varios
// usuarios, borraría los datos de todos. Se cambia a DELETE acotado por
// user_id en cada tabla; el orden respeta las FK (hijos antes que padres).
try {
    foreach (['channel_changes', 'sync_runs', 'channels', 'providers', 'categories'] as $table) {
        $stmt = $pdo->prepare("DELETE FROM `$table` WHERE user_id = ?");
        $stmt->execute([$userId]);
    }
} catch (Throwable $e) {
    respond_error('Error al limpiar la base de datos: ' . $e->getMessage(), 500);
}

respond([
    'ok' => true,
    'message' => 'Base de datos limpiada. No quedan proveedores, categorías, canales ni historial.',
]);
