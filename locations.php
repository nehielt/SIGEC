<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
requireAuth($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $id = (int) ($_POST['id'] ?? 0);
        $building = $pdo->prepare('SELECT building_code FROM warehouse_locations WHERE id = :id');
        $building->execute(['id' => $id]);
        $buildingCode = $building->fetchColumn();
        if ($buildingCode === false || !canViewBuilding($pdo, (string) $buildingCode)) {
            throw new RuntimeException('No tienes permiso para modificar esa ubicación.');
        }
        $capacityInput = trim((string) ($_POST['capacity'] ?? ''));
        $capacity = $capacityInput === '' ? null : filter_var($capacityInput, FILTER_VALIDATE_INT);
        if ($capacityInput !== '' && ($capacity === false || $capacity < 1)) {
            throw new InvalidArgumentException('La capacidad debe ser un entero positivo o quedar vacía (sin límite configurado).');
        }
        if ($capacity !== null) {
            $occupied = $pdo->prepare("SELECT COUNT(*) FROM inventory_items WHERE location_id = :id AND status = 'in' AND deleted_at IS NULL");
            $occupied->execute(['id' => $id]);
            if ((int) $capacity < (int) $occupied->fetchColumn()) {
                throw new InvalidArgumentException('La capacidad no puede ser menor que la ocupación actual.');
            }
        }
        $statement = $pdo->prepare('UPDATE warehouse_locations SET capacity = :capacity WHERE id = :id');
        $statement->bindValue(':capacity', $capacity, $capacity === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('No se pudo actualizar la capacidad de esa ubicación.');
        }
        flash('success', 'Capacidad actualizada.');
    } catch (InvalidArgumentException|RuntimeException $exception) {
        flash('error', $exception->getMessage());
    }
    header('Location: locations.php');
    exit;
}

$search = trim((string) ($_GET['q'] ?? ''));
$buildingCode = trim((string) ($_GET['building_code'] ?? ''));
$occupancy = trim((string) ($_GET['occupancy'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 25;
if (!in_array($occupancy, ['occupied', 'available', 'full'], true)) {
    $occupancy = '';
}

$locationScope = inventoryAccessSql('i', 'l', 'location');
$occupiedExpression = "COUNT(i.id) FILTER (WHERE i.status = 'in')";
$where = ['l.is_active', buildingAccessSql('l')];
$locationParams = inventoryAccessParams('location');
if (!isAdmin()) {
    $locationParams['building_user_id'] = $_SESSION['user_id'];
}
if ($search !== '') {
    $where[] = '(l.building_code ILIKE :search_building OR l.code ILIKE :search_code OR l.name ILIKE :search_name)';
    $term = '%' . $search . '%';
    $locationParams += [
        'search_building' => $term,
        'search_code' => $term,
        'search_name' => $term,
    ];
}
if ($buildingCode !== '') {
    $where[] = 'l.building_code = :building_code';
    $locationParams['building_code'] = $buildingCode;
}
$having = '';
if ($occupancy === 'occupied') {
    $having = " HAVING {$occupiedExpression} > 0";
} elseif ($occupancy === 'available') {
    $having = " HAVING l.capacity IS NULL OR {$occupiedExpression} < l.capacity";
} elseif ($occupancy === 'full') {
    $having = " HAVING l.capacity IS NOT NULL AND {$occupiedExpression} >= l.capacity";
}
$countStatement = $pdo->prepare(
    "SELECT COUNT(*) FROM (
        SELECT l.id
        FROM warehouse_locations l
        LEFT JOIN inventory_items i ON i.location_id = l.id AND i.deleted_at IS NULL AND {$locationScope}
        WHERE " . implode(' AND ', $where) . "
        GROUP BY l.id{$having}
     ) matching_locations",
);
$countStatement->execute($locationParams);
$total = (int) $countStatement->fetchColumn();
$pages = max(1, (int) ceil($total / $pageSize));
$page = min($page, $pages);
$statement = $pdo->prepare(
    "SELECT l.id, l.building_code, l.code, l.name, l.capacity,
        {$occupiedExpression} AS occupied,
        COUNT(i.id) FILTER (WHERE i.status = 'out') AS out_count
     FROM warehouse_locations l LEFT JOIN inventory_items i ON i.location_id = l.id AND i.deleted_at IS NULL AND {$locationScope}
     WHERE " . implode(' AND ', $where) . "
     GROUP BY l.id{$having} ORDER BY l.building_code, l.code
     LIMIT :limit OFFSET :offset",
);
$listParams = $locationParams;
foreach ($listParams as $key => $value) {
    $statement->bindValue(':' . $key, $value);
}
$statement->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$statement->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
$statement->execute();
$locations = $statement->fetchAll();

$buildingScope = buildingAccessSql('l', 'locations_filter');
$buildingParams = isAdmin() ? [] : ['building_user_id_locations_filter' => $_SESSION['user_id']];
$buildingStatement = $pdo->prepare(
    "SELECT DISTINCT b.code, b.description
     FROM building b JOIN warehouse_locations l ON l.building_code = b.code
     WHERE l.is_active AND {$buildingScope}
     ORDER BY b.code",
);
$buildingStatement->execute($buildingParams);
$buildings = $buildingStatement->fetchAll();

pageHeader('Ubicaciones', 'ubicaciones');
?>
<section class="panel"><div class="panel-heading"><div><h2>Capacidad y ocupación</h2><p>La capacidad no se infiere del archivo: establece el límite físico real de cada ubicación.</p></div><span class="count-pill"><?= number_format($total) ?> ubicaciones</span></div>
<form class="filter-form" method="get">
    <label class="search-field">Buscar<input type="search" name="q" value="<?= h($search) ?>" placeholder="Edificio, ubicación o descripción"></label>
    <label>Edificio<select name="building_code"><option value="">Todos</option><?php foreach ($buildings as $building): ?><option value="<?= h($building['code']) ?>" <?= $buildingCode === $building['code'] ? 'selected' : '' ?>><?= h($building['code']) ?><?= $building['description'] !== '' ? ' · ' . h($building['description']) : '' ?></option><?php endforeach; ?></select></label>
    <label>Ocupación<select name="occupancy"><option value="">Todas</option><option value="occupied" <?= $occupancy === 'occupied' ? 'selected' : '' ?>>Ocupadas</option><option value="available" <?= $occupancy === 'available' ? 'selected' : '' ?>>Con espacio disponible</option><option value="full" <?= $occupancy === 'full' ? 'selected' : '' ?>>Capacidad completa</option></select></label>
    <button class="button secondary" type="submit">Filtrar</button>
    <?php if ($search !== '' || $buildingCode !== '' || $occupancy !== ''): ?><a class="button secondary" href="locations.php">Limpiar</a><?php endif; ?>
</form>
<?php if ($locations === []): ?><div class="empty-state"><strong><?= $search !== '' || $buildingCode !== '' || $occupancy !== '' ? 'No encontramos ubicaciones' : 'Sin ubicaciones registradas' ?></strong><p><?= $search !== '' || $buildingCode !== '' || $occupancy !== '' ? 'Prueba con otros criterios o limpia los filtros.' : 'Importa el archivo de inventario o crea una al registrar una recepción.' ?></p></div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Edificio</th><th>Ubicación</th><th>Descripción</th><th>En almacén</th><th>En salida</th><th>Capacidad (unidades)</th></tr></thead><tbody><?php foreach ($locations as $location): ?><tr>
<td class="mono"><?= h($location['building_code']) ?></td><td class="mono"><?= h($location['code']) ?></td><td><?= h($location['name'] ?: '—') ?></td><td><?= number_format((int) $location['occupied']) ?></td><td><?= number_format((int) $location['out_count']) ?></td>
<td><form class="inline-form" method="post"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int) $location['id'] ?>"><input class="capacity-input" type="number" min="1" name="capacity" value="<?= h($location['capacity'] ?? '') ?>" placeholder="Sin límite" aria-label="Capacidad de <?= h($location['code']) ?>"><button class="button small secondary" type="submit">Guardar</button></form></td></tr><?php endforeach; ?></tbody></table></div>
<?php if ($pages > 1): ?><div class="pagination"><?php if ($page > 1): ?><a href="?<?= h(http_build_query(['q' => $search, 'building_code' => $buildingCode, 'occupancy' => $occupancy, 'page' => $page - 1])) ?>">← Anterior</a><?php endif; ?><span><?= $page ?> / <?= $pages ?></span><?php if ($page < $pages): ?><a href="?<?= h(http_build_query(['q' => $search, 'building_code' => $buildingCode, 'occupancy' => $occupancy, 'page' => $page + 1])) ?>">Siguiente →</a><?php endif; ?></div><?php endif; ?>
<?php endif; ?></section>
<?php pageFooter(); ?>
