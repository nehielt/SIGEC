<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
session_name('inventory_session');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

try {
    $pdo = database();
} catch (RuntimeException $exception) {
    http_response_code(500);
    exit(h($exception->getMessage()));
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    exit('No se pudo conectar con PostgreSQL. Revisa la configuración y que el servicio esté activo.');
}
