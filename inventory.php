<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
requireAuth($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isAdmin()) {
        http_response_code(403);
        exit('No tienes permiso para eliminar bajas permanentes.');
    }

    try {
        verifyCsrf();
        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action !== 'soft_delete_batch') {
            throw new InvalidArgumentException('La operación solicitada no es válida.');
        }
        $submittedIds = $_POST['external_ids'] ?? null;
        if (!is_array($submittedIds)) {
            throw new InvalidArgumentException('Selecciona al menos una baja permanente.');
        }
        if (count($submittedIds) > 500) {
            throw new InvalidArgumentException('Selecciona entre 1 y 500 bajas permanentes por lote.');
        }
        $externalIds = [];
        foreach ($submittedIds as $submittedId) {
            if (!is_string($submittedId)) {
                throw new InvalidArgumentException('La selección contiene un ID inválido.');
            }
            $externalId = trim($submittedId);
            if ($externalId === '' || strlen($externalId) > 100) {
                throw new InvalidArgumentException('La selección contiene un ID inválido.');
            }
            $externalIds[] = $externalId;
        }
        $externalIds = array_values(array_unique($externalIds));
        if ($externalIds === [] || count($externalIds) > 500) {
            throw new InvalidArgumentException('Selecciona entre 1 y 500 bajas permanentes por lote.');
        }

        sort($externalIds, SORT_STRING);
        $pdo->beginTransaction();
        $placeholders = [];
        $params = ['status' => 'permanent_out'];
        foreach ($externalIds as $index => $externalId) {
            $key = 'external_id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $externalId;
        }
        $selectedStatement = $pdo->prepare(
            'SELECT external_id FROM inventory_items
             WHERE external_id IN (' . implode(', ', $placeholders) . ')
               AND status = :status AND deleted_at IS NULL
             ORDER BY id FOR UPDATE',
        );
        $selectedStatement->execute($params);
        $eligibleIds = $selectedStatement->fetchAll(PDO::FETCH_COLUMN);
        if (count($eligibleIds) !== count($externalIds)) {
            throw new RuntimeException('El lote incluye artículos que no son bajas permanentes activas. No se eliminó ningún artículo.');
        }

        $updateStatement = $pdo->prepare(
            'UPDATE inventory_items SET deleted_at = NOW()
             WHERE external_id IN (' . implode(', ', $placeholders) . ')
               AND status = :status AND deleted_at IS NULL',
        );
        $updateStatement->execute($params);
        if ($updateStatement->rowCount() !== count($externalIds)) {
            throw new RuntimeException('No se pudo completar el lote. No se eliminó ningún artículo.');
        }
        $pdo->commit();
        flash('success', number_format(count($externalIds)) . ' bajas permanentes eliminadas del inventario activo.');
    } catch (InvalidArgumentException|RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        flash('error', 'No se pudo completar el lote de bajas permanentes. Inténtalo otra vez.');
    }
    header('Location: inventory.php?status=permanent_out');
    exit;
}

$search = trim((string) ($_GET['q'] ?? ''));
$location = trim((string) ($_GET['location'] ?? ''));
$buildingCode = trim((string) ($_GET['building_code'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$listAll = ($_GET['all'] ?? '') === '1';
$hasFilters = $search !== '' || $location !== '' || $buildingCode !== ''
    || in_array($status, ['unprocessed', 'in', 'out', 'permanent_out'], true);
$showResults = $listAll || $hasFilters;
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = isAdmin() && $status === 'permanent_out' ? 500 : 50;
$where = [inventoryAccessSql(), 'i.deleted_at IS NULL'];
$params = inventoryAccessParams();
if ($search !== '') {
    $where[] = "(i.external_id ILIKE :search_id OR i.item_code ILIKE :search_code OR COALESCE(c.name, i.account_name) ILIKE :search_account OR d.name ILIKE :search_department OR d.department_code ILIKE :search_department_code)";
    $term = '%' . $search . '%';
    $params += [
        'search_id' => $term, 'search_code' => $term, 'search_account' => $term,
        'search_department' => $term, 'search_department_code' => $term,
    ];
}
if ($location !== '') {
    $where[] = 'l.code ILIKE :location';
    $params['location'] = '%' . $location . '%';
}
if ($buildingCode !== '') {
    $where[] = 'l.building_code = :building_code';
    $params['building_code'] = $buildingCode;
}
if (in_array($status, ['unprocessed', 'in', 'out', 'permanent_out'], true)) {
    $where[] = 'i.status = :status';
    $params['status'] = $status;
}
$filter = ' WHERE ' . implode(' AND ', $where);
$total = 0;
$pages = 1;
$items = [];
if ($showResults) {
    $count = $pdo->prepare("SELECT COUNT(*) FROM inventory_items i LEFT JOIN warehouse_locations l ON l.id = i.location_id LEFT JOIN clients c ON c.id = i.client_id LEFT JOIN departaments d ON d.id = i.department_id AND d.client_id = i.client_id{$filter}");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $pageSize));
    $page = min($page, $pages);
    $statement = $pdo->prepare(
        "SELECT i.external_id, i.item_code, COALESCE(c.name, i.account_name) AS account_name,
                d.name AS department_name, d.department_code, i.status, l.building_code, l.code AS location_code
         FROM inventory_items i LEFT JOIN warehouse_locations l ON l.id = i.location_id
         LEFT JOIN clients c ON c.id = i.client_id
         LEFT JOIN departaments d ON d.id = i.department_id AND d.client_id = i.client_id{$filter}
         ORDER BY i.external_id LIMIT :limit OFFSET :offset",
    );
    foreach ($params as $key => $value) {
        $statement->bindValue(':' . $key, $value);
    }
    $statement->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $statement->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
    $statement->execute();
    $items = $statement->fetchAll();
}

$buildingScope = isAdmin()
    ? 'TRUE'
    : 'EXISTS (SELECT 1 FROM user_building_permissions ubp WHERE ubp.user_id = :building_user_id AND ubp.building_code = b.code)';
$buildingParams = isAdmin() ? [] : ['building_user_id' => $_SESSION['user_id']];
$buildingStatement = $pdo->prepare(
    "SELECT b.code, b.description FROM building b WHERE {$buildingScope} ORDER BY b.code",
);
$buildingStatement->execute($buildingParams);
$buildings = $buildingStatement->fetchAll();

pageHeader('Inventario', 'inventario', 'CONTROL OPERATIVO DE ELEMENTOS EN CUSTODIA');
?>
<section class="panel">
    <div class="panel-heading"><div><h2>Existencias</h2><?php if ($showResults): ?><p><?= number_format($total) ?> artículos · página <?= $page ?> de <?= $pages ?></p><?php endif; ?></div><a class="button primary" href="movements.php">Registrar movimiento</a></div>
    <form class="filter-form" method="get">
        <label class="search-field">Buscar<input type="search" name="q" value="<?= h($search) ?>" placeholder="ID, artículo o cliente"></label>
        <label>Edificio<select name="building_code"><option value="">Todos</option><?php foreach ($buildings as $building): ?><option value="<?= h($building['code']) ?>" <?= $buildingCode === $building['code'] ? 'selected' : '' ?>><?= h($building['code']) ?><?= $building['description'] !== '' ? ' · ' . h($building['description']) : '' ?></option><?php endforeach; ?></select></label>
        <label>Ubicación<input type="search" name="location" value="<?= h($location) ?>" placeholder="Código de ubicación"></label>
        <label>Estado<select name="status"><option value="">Todos</option><?php foreach (['unprocessed' => 'No procesado', 'in' => 'En almacén', 'out' => 'En salida', 'permanent_out' => 'Baja permanente'] as $value => $label): ?><option value="<?= h($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
        <button class="button secondary" type="submit">Filtrar</button>
        <a class="button secondary" href="inventory.php?all=1">Listar todos</a>
    </form>
    <?php if (!$showResults): ?>
        <div class="empty-state"><span class="empty-icon">⌕</span><strong>Busca artículos o lista todos los registros</strong><p>Usa los filtros para consultar coincidencias, o selecciona «Listar todos» para ver el inventario completo.</p></div>
    <?php elseif ($total === 0): ?>
        <div class="empty-state"><span class="empty-icon">⌕</span><strong><?= $hasFilters ? 'No encontramos resultados' : 'Todavía no hay artículos' ?></strong><p><?= $hasFilters ? 'Prueba con otros criterios de búsqueda o filtros.' : 'Carga el archivo de capacidad desde la terminal para comenzar.' ?></p><?php if (!$hasFilters): ?><code>python3 bin/import_inventory.py "/ruta/al/archivo.xlsx"</code><?php endif; ?></div>
    <?php else: ?>
        <?php $canBatchDelete = isAdmin() && $status === 'permanent_out'; ?>
        <?php if ($canBatchDelete): ?>
            <form method="post" onsubmit="return confirm('¿Eliminar del inventario activo las bajas permanentes seleccionadas?');">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="soft_delete_batch">
                <p class="batch-actions">Selecciona hasta 500 bajas permanentes para procesarlas juntas.</p>
                <button class="button secondary" type="submit">Eliminar seleccionadas con soft delete</button>
        <?php endif; ?>
        <div class="table-wrap"><table><thead><tr><?php if ($canBatchDelete): ?><th><input class="batch-checkbox" type="checkbox" id="select-all-permanent-out" aria-label="Seleccionar todas las bajas permanentes de esta página"></th><?php endif; ?><th>Artículo</th><th>Cliente / cuenta</th><th>Departamento</th><th>Edificio</th><th>Ubicación</th><th>Estado</th><th></th></tr></thead><tbody>
        <?php foreach ($items as $item): ?><tr>
            <?php if ($canBatchDelete): ?><td><input class="batch-checkbox" type="checkbox" name="external_ids[]" value="<?= h($item['external_id']) ?>" aria-label="Seleccionar baja permanente <?= h($item['external_id']) ?>"></td><?php endif; ?>
            <td class="mono"><?= h($item['item_code']) ?></td><td><?= h($item['account_name']) ?></td>
            <td><?= h($item['department_name'] ? $item['department_name'] . ' · ' . $item['department_code'] : '—') ?></td>
            <td class="mono"><?= h($item['building_code'] ?? '—') ?></td><td class="mono"><?= h($item['location_code'] ?? '—') ?></td>
            <td><span class="status-badge status-<?= h($item['status']) ?>"><?= h(statusLabel($item['status'])) ?></span></td>
            <td><a class="table-link" href="item.php?id=<?= urlencode($item['external_id']) ?>">Ver historial</a></td>
        </tr><?php endforeach; ?>
        </tbody></table></div>
        <?php if ($canBatchDelete): ?></form><?php endif; ?>
        <?php if ($canBatchDelete): ?>
            <script>
                document.getElementById('select-all-permanent-out').addEventListener('change', function () {
                    document.querySelectorAll('input[name="external_ids[]"]').forEach(function (checkbox) {
                        checkbox.checked = this.checked;
                    }, this);
                });
            </script>
        <?php endif; ?>
        <?php if ($pages > 1): ?><div class="pagination"><?php if ($page > 1): ?><a href="?<?= http_build_query(['q' => $search, 'location' => $location, 'building_code' => $buildingCode, 'status' => $status, 'all' => $listAll ? '1' : null, 'page' => $page - 1]) ?>">← Anterior</a><?php endif; ?><span><?= $page ?> / <?= $pages ?></span><?php if ($page < $pages): ?><a href="?<?= http_build_query(['q' => $search, 'location' => $location, 'building_code' => $buildingCode, 'status' => $status, 'all' => $listAll ? '1' : null, 'page' => $page + 1]) ?>">Siguiente →</a><?php endif; ?></div><?php endif; ?>
    <?php endif; ?>
</section>
<?php pageFooter(); ?>
