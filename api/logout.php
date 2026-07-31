<?php

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('Método no soportado', 405);
}

auth_logout();
respond(['ok' => true]);
