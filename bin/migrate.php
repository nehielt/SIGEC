<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';

$sql = file_get_contents(__DIR__ . '/../database/schema.sql');
if ($sql === false) {
    fwrite(STDERR, "No se pudo leer database/schema.sql.\n");
    exit(1);
}

database()->exec($sql);
fwrite(STDOUT, "Esquema de inventario listo.\n");
