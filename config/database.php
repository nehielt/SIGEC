<?php
declare(strict_types=1);

function database(): PDO
{
    static $connection;
    if ($connection instanceof PDO) {
        return $connection;
    }

    foreach ([dirname(__DIR__, 2) . '/.inventory.env', dirname(__DIR__) . '/.env'] as $envFile) {
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                if (getenv(trim($key)) === false) {
                    putenv(trim($key) . '=' . trim($value));
                }
            }
        }
    }

    $password = getenv('INVENTORY_DB_PASSWORD');
    if ($password === false || $password === '') {
        throw new RuntimeException('Falta configurar INVENTORY_DB_PASSWORD.');
    }

    $host = getenv('INVENTORY_DB_HOST') ?: '127.0.0.1';
    $port = getenv('INVENTORY_DB_PORT') ?: '5432';
    $name = getenv('INVENTORY_DB_NAME') ?: 'inventory';
    $user = getenv('INVENTORY_DB_USER') ?: 'postgres';
    $connection = new PDO(
        "pgsql:host={$host};port={$port};dbname={$name}",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );

    return $connection;
}
