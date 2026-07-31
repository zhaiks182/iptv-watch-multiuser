<?php
/**
 * Identidad de la sesión activa. El dashboard lo usa para decidir si
 * mostrar el botón de administración de usuarios (solo role='admin').
 *
 * GET -> {username, role}
 */

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('Método no soportado', 405);
}

respond([
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
]);
