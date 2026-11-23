<?php
declare(strict_types=1);

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrfToken(), $token)) {
        throw new InvalidArgumentException('La sesión del formulario venció. Actualiza la página e inténtalo otra vez.');
    }
}

function requireAuth(PDO $pdo): void
{
    if (isset($_SESSION['user_id'], $_SESSION['username'])) {
        $statement = $pdo->prepare('SELECT is_admin FROM app_users WHERE id = :id');
        $statement->execute(['id' => $_SESSION['user_id']]);
        $isAdmin = $statement->fetchColumn();
        if ($isAdmin !== false) {
            $_SESSION['is_admin'] = databaseBoolean($isAdmin);
            return;
        }
        unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['is_admin']);
    }

    $hasUsers = (int) $pdo->query('SELECT COUNT(*) FROM app_users')->fetchColumn() > 0;
    header('Location: ' . ($hasUsers ? 'login.php' : 'setup.php'));
    exit;
}

function databaseBoolean(mixed $value): bool
{
    return in_array($value, [true, 1, '1', 't', 'true'], true);
}

function base32Encode(string $value): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($value) as $character) {
        $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
    }

    $encoded = '';
    foreach (str_split($bits, 5) as $chunk) {
        $encoded .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }

    return $encoded;
}

function base32Decode(string $value): string
{
    $value = strtoupper(rtrim($value, '='));
    if ($value === '' || preg_match('/^[A-Z2-7]+$/', $value) !== 1) {
        throw new InvalidArgumentException('El secreto de autenticación no es válido.');
    }

    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($value) as $character) {
        $bits .= str_pad(decbin(strpos($alphabet, $character)), 5, '0', STR_PAD_LEFT);
    }

    $decoded = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $decoded .= chr(bindec($chunk));
        }
    }

    return $decoded;
}

function generateTotpSecret(): string
{
    return base32Encode(random_bytes(20));
}

function totpCode(string $secret, int $step): string
{
    $counter = pack('N2', intdiv($step, 0x100000000), $step % 0x100000000);
    $hash = hash_hmac('sha1', $counter, base32Decode($secret), true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
    $number = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;

    return str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
}

function verifyTotp(string $secret, string $code, ?int $timestamp = null): ?int
{
    if (preg_match('/^[0-9]{6}$/', $code) !== 1) {
        return null;
    }

    $currentStep = intdiv($timestamp ?? time(), 30);
    foreach ([$currentStep, $currentStep - 1, $currentStep + 1] as $step) {
        if ($step >= 0 && hash_equals(totpCode($secret, $step), $code)) {
            return $step;
        }
    }

    return null;
}

function isAdmin(): bool
{
    return ($_SESSION['is_admin'] ?? false) === true;
}

function inventoryAccessSql(string $itemAlias = 'i', string $locationAlias = 'l', string $suffix = ''): string
{
    if (isAdmin()) {
        return 'TRUE';
    }

    $suffix = $suffix === '' ? '' : '_' . $suffix;
    return "EXISTS (
            SELECT 1 FROM user_building_permissions ubp
            WHERE ubp.user_id = :building_user_id{$suffix}
              AND ubp.building_code = COALESCE(
                  {$locationAlias}.building_code,
                  (
                      SELECT COALESCE(last_to.building_code, last_from.building_code)
                      FROM movement_lines last_ml
                      LEFT JOIN warehouse_locations last_from ON last_from.id = last_ml.from_location_id
                      LEFT JOIN warehouse_locations last_to ON last_to.id = last_ml.to_location_id
                      WHERE last_ml.item_id = {$itemAlias}.id
                        AND (last_ml.to_location_id IS NOT NULL OR last_ml.from_location_id IS NOT NULL)
                      ORDER BY last_ml.movement_id DESC
                      LIMIT 1
                  )
              )
        )";
}

function inventoryAccessParams(string $suffix = ''): array
{
    if (isAdmin()) {
        return [];
    }

    $suffix = $suffix === '' ? '' : '_' . $suffix;
    return [
        'building_user_id' . $suffix => $_SESSION['user_id'],
    ];
}

function buildingAccessSql(string $locationAlias = 'l', string $suffix = ''): string
{
    if (isAdmin()) {
        return 'TRUE';
    }

    $suffix = $suffix === '' ? '' : '_' . $suffix;
    return "EXISTS (
        SELECT 1 FROM user_building_permissions ubp
        WHERE ubp.user_id = :building_user_id{$suffix}
          AND ubp.building_code = {$locationAlias}.building_code
    )";
}

function canViewBuilding(PDO $pdo, string $buildingCode): bool
{
    if (isAdmin()) {
        return true;
    }

    $statement = $pdo->prepare(
        'SELECT EXISTS (
            SELECT 1 FROM user_building_permissions
            WHERE user_id = :user_id AND building_code = :building_code
        )',
    );
    $statement->execute(['user_id' => $_SESSION['user_id'], 'building_code' => $buildingCode]);
    return (bool) $statement->fetchColumn();
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }

    $value = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return is_string($value) ? $value : null;
}

function pageHeader(string $title, string $active = '', string $eyebrow = 'CONTROL OPERATIVO'): void
{
    $links = [
        'Inicio' => ['index.php', 'inicio'],
        'Custodia' => [
            'type' => 'group',
            'active_keys' => ['inventario', 'movimientos', 'remesas'],
            'children' => [
                'Inventario' => ['inventory.php', 'inventario'],
                'Movimientos' => ['movements.php', 'movimientos'],
                'Remesas' => ['remesas.php', 'remesas'],
            ],
        ],
        'Configuración' => [
            'type' => 'group',
            'active_keys' => ['ubicaciones'],
            'children' => [
                'Ubicaciones' => ['locations.php', 'ubicaciones'],
            ],
        ],
    ];
    if (isAdmin()) {
        $links['Reportes'] = ['reports.php', 'reportes'];
        $links['Configuración']['children']['Códigos'] = ['codes.php', 'codigos'];
        $links['Configuración']['children']['Clientes'] = ['clients.php', 'clientes'];
        $links['Configuración']['children']['Edificios'] = ['buildings.php', 'edificios'];
        $links['Configuración']['children']['Usuarios y permisos'] = ['permissions.php', 'permisos'];
        $links['Configuración']['active_keys'] = ['ubicaciones', 'codigos', 'clientes', 'edificios', 'permisos'];
    }
    $links['2FA'] = ['security.php', 'seguridad'];
    ?>
    <!doctype html>
    <html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?> · SIG</title>
        <link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/../assets/app.css') ?>">
    </head>
    <body>
    <div class="app-shell">
        <aside class="sidebar">
            <a class="brand" href="index.php"><img class="brand-logo" src="logo_setecsa.png" alt="Logo de SETECSA"></a>
            <nav aria-label="Navegación principal">
                <?php foreach ($links as $label => $link): ?>
                    <?php if (($link['type'] ?? '') === 'group'): ?>
                        <div class="nav-group <?= in_array($active, $link['active_keys'], true) ? 'active' : '' ?>">
                            <div class="nav-group-label"><span class="nav-dot"></span><?= h($label) ?></div>
                            <div class="nav-submenu">
                                <?php foreach ($link['children'] as $childLabel => [$childHref, $childKey]): ?>
                                    <a class="nav-link nav-sublink <?= $active === $childKey ? 'active' : '' ?>" href="<?= h($childHref) ?>"><?= h($childLabel) ?></a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php [$href, $key] = $link; ?>
                        <a class="nav-link <?= $active === $key ? 'active' : '' ?>" href="<?= h($href) ?>"><span class="nav-dot"></span><?= h($label) ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
            <div class="sidebar-bottom">
                <span class="user-chip"><span class="user-avatar"><?= h(strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1))) ?></span><?= h($_SESSION['username'] ?? '') ?></span>
                <form action="logout.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <button class="logout-button" type="submit">Cerrar sesión</button>
                </form>
                <span class="sidebar-footnote">Operación de almacén · local</span>
            </div>
        </aside>
        <main class="main-content">
            <header class="topbar"><div class="topbar-brand"><strong>SIG</strong><span>Sistema Integrado de Gestión</span></div><span class="topbar-date"><?= h(date('d/m/Y')) ?></span></header>
            <div class="page-content">
                <div class="page-title-row"><div><p class="eyebrow"><?= h($eyebrow) ?></p><h1><?= h($title) ?></h1></div></div>
                <?php if ($message = flash('success')): ?><div class="alert success"><?= h($message) ?></div><?php endif; ?>
                <?php if ($message = flash('error')): ?><div class="alert error"><?= h($message) ?></div><?php endif; ?>
    <?php
}

function pageFooter(): void
{
    ?>
            </div>
        </main>
    </div>
    </body>
    </html>
    <?php
}

function statusLabel(string $status): string
{
    return match ($status) {
        'unprocessed' => 'No procesado',
        'in' => 'En almacén',
        'out' => 'En salida',
        'permanent_out' => 'Baja permanente',
        default => $status,
    };
}

function movementLabel(string $type): string
{
    return match ($type) {
        'receive' => 'Recepción',
        'transfer' => 'Traslado',
        'dispatch' => 'Salida',
        'return' => 'Devolución',
        'writeoff' => 'Baja',
        default => $type,
    };
}

function ensureCapacity(PDO $pdo, int $locationId, int $incoming): void
{
    if ($incoming <= 0) {
        return;
    }

    $statement = $pdo->prepare('SELECT capacity FROM warehouse_locations WHERE id = :id FOR UPDATE');
    $statement->execute(['id' => $locationId]);
    $capacity = $statement->fetchColumn();
    if ($capacity === false) {
        throw new RuntimeException('La ubicación seleccionada ya no está disponible.');
    }
    if ($capacity === null) {
        return;
    }

    $statement = $pdo->prepare("SELECT COUNT(*) FROM inventory_items WHERE location_id = :id AND status = 'in' AND deleted_at IS NULL");
    $statement->execute(['id' => $locationId]);
    $occupied = (int) $statement->fetchColumn();
    if ($occupied + $incoming > (int) $capacity) {
        throw new RuntimeException("La ubicación excedería su capacidad: {$occupied}/{$capacity} ocupados.");
    }
}

function getOrCreateLocation(PDO $pdo, string $building, string $code, string $name): int
{
    if ($building === '' || $code === '') {
        throw new InvalidArgumentException('El edificio y el código de ubicación son obligatorios.');
    }

    $buildingStatement = $pdo->prepare('INSERT INTO building (code) VALUES (:building) ON CONFLICT (code) DO NOTHING');
    $buildingStatement->execute(['building' => $building]);

    $statement = $pdo->prepare(
        'INSERT INTO warehouse_locations (building_code, code, name)
         VALUES (:building, :code, :name)
         ON CONFLICT (building_code, code) DO UPDATE SET
            name = CASE WHEN EXCLUDED.name <> \'\' THEN EXCLUDED.name ELSE warehouse_locations.name END
         RETURNING id',
    );
    $statement->execute(['building' => $building, 'code' => $code, 'name' => $name]);
    return (int) $statement->fetchColumn();
}

function parseExternalIds(string $input): array
{
    $parts = preg_split('/[\s,;]+/u', trim($input), -1, PREG_SPLIT_NO_EMPTY);
    $ids = array_values(array_unique(array_map('trim', $parts ?: [])));
    if ($ids === []) {
        throw new InvalidArgumentException('Ingresa al menos un ID de inventario.');
    }
    if (count($ids) > 500) {
        throw new InvalidArgumentException('Procesa un máximo de 500 IDs por movimiento.');
    }
    foreach ($ids as $id) {
        if (strlen($id) > 100) {
            throw new InvalidArgumentException('Uno de los IDs supera el largo permitido.');
        }
    }
    return $ids;
}
