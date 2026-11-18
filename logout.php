<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}
try {
    verifyCsrf();
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    exit(h($exception->getMessage()));
}
$_SESSION = [];
session_destroy();
header('Location: login.php');
