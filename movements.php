<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
requireAuth($pdo);

function parseItemCodes(string $input): array
{
    $parts = preg_split('/[\s,;]+/u', trim($input), -1, PREG_SPLIT_NO_EMPTY);
    $codes = array_values(array_unique(array_map('trim', $parts ?: [])));
    if ($codes === []) {
        throw new InvalidArgumentException('Ingresa al menos un código de artículo.');
    }
    if (count($codes) > 500) {
        throw new InvalidArgumentException('Procesa un máximo de 500 códigos de artículo por movimiento.');
    }
    foreach ($codes as $code) {
        if (strlen($code) > 120) {
            throw new InvalidArgumentException('Uno de los códigos de artículo supera el largo permitido.');
        }
    }
    return $codes;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $type = (string) ($_POST['type'] ?? '');
        if (!in_array($type, ['receive', 'transfer', 'dispatch', 'return', 'writeoff'], true)) {
            throw new InvalidArgumentException('Selecciona un tipo de movimiento válido.');
        }
        $codes = parseItemCodes((string) ($_POST['item_codes'] ?? ''));
        $reference = trim((string) ($_POST['reference'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));
        if (strlen($reference) > 120 || strlen($note) > 4000) {
            throw new InvalidArgumentException('La referencia o la nota supera el largo permitido.');
        }

        $pdo->beginTransaction();
        $newLocationId = null;
        if ($type === 'receive') {
            $buildingCode = strtoupper(trim((string) ($_POST['building_code'] ?? '')));
            if (!canViewBuilding($pdo, $buildingCode)) {
                throw new RuntimeException('No tienes permiso para registrar artículos en ese edificio.');
            }
            $newLocationId = getOrCreateLocation(
                $pdo,
                $buildingCode,
                strtoupper(trim((string) ($_POST['location_code'] ?? ''))),
                trim((string) ($_POST['location_name'] ?? '')),
            );
        } elseif (in_array($type, ['transfer', 'return'], true)) {
            $building = strtoupper(trim((string) ($_POST['building_code'] ?? '')));
            $locationCode = strtoupper(trim((string) ($_POST['location_code'] ?? '')));
            if ($building === '' || $locationCode === '') {
                throw new InvalidArgumentException('Indica el edificio y la ubicación de destino.');
            }
            if (!canViewBuilding($pdo, $building)) {
                throw new RuntimeException('No tienes permiso para trasladar artículos a ese edificio.');
            }
            $location = $pdo->prepare(
                'SELECT id FROM warehouse_locations WHERE building_code = :building AND code = :code AND is_active FOR UPDATE',
            );
            $location->execute(['building' => $building, 'code' => $locationCode]);
            $newLocationId = $location->fetchColumn();
            if ($newLocationId === false) {
                throw new RuntimeException('El destino debe ser una ubicación activa. Las ubicaciones nuevas se crean con una recepción.');
            }
            $newLocationId = (int) $newLocationId;
        }

        if ($type === 'receive') {
            ensureCapacity($pdo, $newLocationId, count($codes));
            $codePlaceholders = [];
            $codeParams = [];
            foreach ($codes as $index => $code) {
                $key = 'code' . $index;
                $codePlaceholders[] = ':' . $key;
                $codeParams[$key] = $code;
            }
            $query = $pdo->prepare(
                                'SELECT i.id, i.item_code, i.location_id, i.status, i.client_id, i.department_id,
                                    code_prefix.client_id AS prefix_client_id, code_prefix.prefix AS item_prefix,
                                    d.id AS validated_department_id
                                 FROM inventory_items i
                                 LEFT JOIN LATERAL (
                                         SELECT p.client_id
                                         FROM client_item_prefixes p
                                         WHERE LEFT(i.item_code, LENGTH(p.prefix)) = p.prefix
                                         ORDER BY LENGTH(p.prefix) DESC
                                         LIMIT 1
                                 ) code_prefix ON TRUE
                                 LEFT JOIN departaments d
                                     ON d.id = i.department_id AND d.client_id = code_prefix.client_id
                                 WHERE i.deleted_at IS NULL
                                     AND i.item_code IN (' . implode(', ', $codePlaceholders) . ')
                                 ORDER BY i.id FOR UPDATE OF i',
            );
            $query->execute($codeParams);
            $items = $query->fetchAll();
            $itemCodeCounts = array_count_values(array_column($items, 'item_code'));
            $ambiguousCodes = array_keys(array_filter($itemCodeCounts, static fn(int $count): bool => $count > 1));
            if ($ambiguousCodes !== []) {
                throw new RuntimeException(
                    'Hay códigos de artículo asociados a más de un registro: ' . implode(', ', array_slice($ambiguousCodes, 0, 8))
                    . (count($ambiguousCodes) > 8 ? '…' : ''),
                );
            }
            $missingCodes = array_values(array_diff($codes, array_keys($itemCodeCounts)));
            if ($missingCodes !== []) {
                throw new RuntimeException(
                    'No existen estos códigos de artículo: ' . implode(', ', array_slice($missingCodes, 0, 8))
                    . (count($missingCodes) > 8 ? '…' : ''),
                );
            }
            $prefixClientIds = [];
            $itemPrefixes = [];
            foreach ($items as $item) {
                if ($item['prefix_client_id'] === null) {
                    throw new RuntimeException('El prefijo del artículo ' . $item['item_code'] . ' no está configurado.');
                }
                if ($item['client_id'] === null || (int) $item['client_id'] !== (int) $item['prefix_client_id']) {
                    throw new RuntimeException('El cliente del artículo ' . $item['item_code'] . ' no coincide con su prefijo.');
                }
                if ($item['department_id'] !== null && $item['validated_department_id'] === null) {
                    throw new RuntimeException('El departamento del artículo ' . $item['item_code'] . ' no pertenece al cliente de su prefijo.');
                }
                $prefixClientIds[] = (int) $item['prefix_client_id'];
                $itemPrefixes[] = (string) $item['item_prefix'];
                if ($item['status'] !== 'unprocessed') {
                    throw new RuntimeException(
                        'El artículo ' . $item['item_code'] . ' no puede recibirse: está ' . statusLabel($item['status']) . '.',
                    );
                }
            }
            if (count(array_unique($itemPrefixes)) !== 1) {
                throw new RuntimeException('Todos los códigos de una recepción deben tener el mismo prefijo.');
            }
            if (count(array_unique($prefixClientIds)) !== 1) {
                throw new RuntimeException('Todos los códigos de una recepción deben pertenecer al mismo cliente.');
            }
        } else {
            $placeholders = [];
            $params = [];
            foreach ($codes as $index => $code) {
                $key = 'code' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $code;
            }
            $query = $pdo->prepare(
                'SELECT i.id, i.item_code, i.location_id, i.status FROM inventory_items i
                 LEFT JOIN warehouse_locations l ON l.id = i.location_id
                 WHERE i.deleted_at IS NULL
                   AND i.item_code IN (' . implode(', ', $placeholders) . ')
                   AND ' . inventoryAccessSql() . '
                 ORDER BY i.id FOR UPDATE OF i',
            );
            $query->execute($params + inventoryAccessParams());
            $items = $query->fetchAll();
            $itemCodeCounts = array_count_values(array_column($items, 'item_code'));
            $ambiguousCodes = array_keys(array_filter($itemCodeCounts, static fn(int $count): bool => $count > 1));
            if ($ambiguousCodes !== []) {
                throw new RuntimeException(
                    'Hay códigos de artículo asociados a más de un registro: ' . implode(', ', array_slice($ambiguousCodes, 0, 8))
                    . (count($ambiguousCodes) > 8 ? '…' : ''),
                );
            }
            $missingCodes = array_values(array_diff($codes, array_keys($itemCodeCounts)));
            if ($missingCodes !== []) {
                throw new RuntimeException(
                    'No se encontraron estos códigos de artículo o no tienes permiso para consultarlos: '
                    . implode(', ', array_slice($missingCodes, 0, 8)) . (count($missingCodes) > 8 ? '…' : ''),
                );
            }
            foreach ($items as $item) {
                $allowed = match ($type) {
                    'transfer', 'dispatch' => $item['status'] === 'in',
                    'return' => $item['status'] === 'out',
                    'writeoff' => $item['status'] !== 'permanent_out',
                    default => false,
                };
                if (!$allowed) {
                    throw new RuntimeException('El artículo ' . $item['item_code'] . ' no puede procesarse: está ' . statusLabel($item['status']) . '.');
                }
            }
            if (in_array($type, ['transfer', 'return'], true)) {
                $incoming = count($items);
                if ($type === 'transfer') {
                    $alreadyAtDestination = count(array_filter(
                        $items,
                        static fn(array $item): bool => (int) $item['location_id'] === $newLocationId,
                    ));
                    $incoming -= $alreadyAtDestination;
                }
                ensureCapacity($pdo, $newLocationId, $incoming);
            }
        }

        $destinationStatus = match ($type) {
            'receive', 'transfer', 'return' => 'in',
            'dispatch' => 'out',
            'writeoff' => 'permanent_out',
        };
        $movement = $pdo->prepare(
            'INSERT INTO movements (type, reference, note, created_by)
             VALUES (:type, :reference, :note, :user_id) RETURNING id',
        );
        $movement->execute([
            'type' => $type,
            'reference' => $reference,
            'note' => $note,
            'user_id' => $_SESSION['user_id'],
        ]);
        $movementId = (int) $movement->fetchColumn();
        $folio = sprintf('MOV-%s-%06d', date('Ymd'), $movementId);
        $updateFolio = $pdo->prepare('UPDATE movements SET folio = :folio WHERE id = :id');
        $updateFolio->execute(['folio' => $folio, 'id' => $movementId]);

        $updateItem = $pdo->prepare(
            'UPDATE inventory_items SET location_id = :location, status = :status, updated_at = NOW()
             WHERE id = :id',
        );
        $receiveItem = $pdo->prepare(
            'UPDATE inventory_items SET location_id = :location, status = :status, permitted = :permitted, updated_at = NOW()
             WHERE id = :id AND status = :from_status',
        );
        $line = $pdo->prepare(
            'INSERT INTO movement_lines (movement_id, item_id, from_location_id, to_location_id, from_status, to_status)
             VALUES (:movement, :item, :from_location, :to_location, :from_status, :to_status)',
        );
        foreach ($items as $item) {
            $fromLocation = $item['location_id'] !== null ? (int) $item['location_id'] : null;
            $fromStatus = $item['status'];
            $toLocation = in_array($type, ['receive', 'transfer', 'return'], true) ? $newLocationId : null;
            $itemId = (int) $item['id'];
            if ($type === 'receive') {
                $receiveItem->execute([
                    'location' => $toLocation,
                    'status' => $destinationStatus,
                    'permitted' => isset($_POST['permitted']) ? 'true' : 'false',
                    'id' => $itemId,
                    'from_status' => 'unprocessed',
                ]);
                if ($receiveItem->rowCount() !== 1) {
                    throw new RuntimeException('El artículo ' . $item['item_code'] . ' dejó de estar no procesado. No se guardó la recepción.');
                }
            } else {
                $updateItem->execute(['location' => $toLocation, 'status' => $destinationStatus, 'id' => $itemId]);
            }
            $line->execute([
                'movement' => $movementId,
                'item' => $itemId,
                'from_location' => $fromLocation,
                'to_location' => $toLocation,
                'from_status' => $fromStatus,
                'to_status' => $destinationStatus,
            ]);
        }
        $pdo->commit();
        $successLabel = match ($type) {
            'receive' => 'Recepción registrada',
            'transfer' => 'Traslado registrado',
            'dispatch' => 'Salida registrada',
            'return' => 'Devolución registrada',
            'writeoff' => 'Baja registrada',
        };
        flash('success', $successLabel . ': ' . $folio . ' · ' . count($codes) . ' artículo(s).');
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
        flash('error', $exception->getCode() === '23505'
            ? 'Uno de los códigos ya existe o el folio está duplicado; no se guardó ningún cambio.'
            : 'No se pudo registrar el movimiento. No se guardó ningún cambio; revisa los datos e inténtalo otra vez.');
    }
    header('Location: movements.php');
    exit;
}

$locationStatement = $pdo->prepare(
    'SELECT id, building_code, code, name FROM warehouse_locations l WHERE l.is_active AND ' . buildingAccessSql('l') . ' ORDER BY building_code, code',
);
$locationParams = isAdmin() ? [] : ['building_user_id' => $_SESSION['user_id']];
$locationStatement->execute($locationParams);
$locations = $locationStatement->fetchAll();
$prefillItem = null;
$itemCode = trim((string) ($_GET['item_code'] ?? ''));
if ($itemCode !== '') {
    $itemStatement = $pdo->prepare(
        'SELECT i.item_code, i.description, i.account_code, i.account_name,
            i.client_id, i.department_id, i.alternate_code, i.status, i.permitted, i.added_date, i.source_sheet,
            l.building_code, l.code AS location_code, l.name AS location_name,
            c.code AS client_code, c.name AS client_name,
            d.department_code, d.name AS department_name
         FROM inventory_items i
         LEFT JOIN warehouse_locations l ON l.id = i.location_id
         LEFT JOIN clients c ON c.id = i.client_id
         LEFT JOIN departaments d ON d.id = i.department_id AND d.client_id = i.client_id
         WHERE i.item_code = :code AND ' . inventoryAccessSql(),
    );
    $itemStatement->execute(['code' => $itemCode] + inventoryAccessParams());
    $prefillItem = $itemStatement->fetch() ?: null;
    if ($prefillItem === null) {
        flash('error', 'No se encontró el artículo o no tienes permiso para consultarlo.');
    }
}
$defaultMovementType = match ($prefillItem['status'] ?? '') {
    'in' => 'transfer',
    'out' => 'return',
    default => '',
};
$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 30;
$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(m.folio ILIKE :search_folio OR m.reference ILIKE :search_reference OR u.username ILIKE :search_username)';
    $term = '%' . $search . '%';
    $params = ['search_folio' => $term, 'search_reference' => $term, 'search_username' => $term];
}
$movementCountScope = inventoryAccessSql('i', 'l', 'history_count');
$movementPrivacy = isAdmin() ? '' : '
       AND NOT EXISTS (
           SELECT 1 FROM movement_lines hidden_ml
           JOIN inventory_items hidden_i ON hidden_i.id = hidden_ml.item_id
           LEFT JOIN warehouse_locations hidden_l ON hidden_l.id = hidden_i.location_id
           WHERE hidden_ml.movement_id = m.id
             AND NOT ' . inventoryAccessSql('hidden_i', 'hidden_l', 'history_hidden') . '
       )
       AND NOT EXISTS (
           SELECT 1 FROM movement_lines route_ml
           LEFT JOIN warehouse_locations route_from ON route_from.id = route_ml.from_location_id
           LEFT JOIN warehouse_locations route_to ON route_to.id = route_ml.to_location_id
           WHERE route_ml.movement_id = m.id
             AND (
                 (route_ml.from_location_id IS NOT NULL AND NOT ' . buildingAccessSql('route_from', 'movement_from') . ')
                 OR (route_ml.to_location_id IS NOT NULL AND NOT ' . buildingAccessSql('route_to', 'movement_to') . ')
             )
       )';
$count = $pdo->prepare(
    'SELECT COUNT(*) FROM movements m JOIN app_users u ON u.id = m.created_by
     WHERE EXISTS (
         SELECT 1 FROM movement_lines ml
         JOIN inventory_items i ON i.id = ml.item_id
         LEFT JOIN warehouse_locations l ON l.id = i.location_id
         WHERE ml.movement_id = m.id AND ' . $movementCountScope . '
     )' . $movementPrivacy . ($where === [] ? '' : ' AND ' . implode(' AND ', $where)),
);
$countParams = inventoryAccessParams('history_count');
if (!isAdmin()) {
    $countParams += inventoryAccessParams('history_hidden');
    $countParams['building_user_id_movement_from'] = $_SESSION['user_id'];
    $countParams['building_user_id_movement_to'] = $_SESSION['user_id'];
}
$count->execute($countParams + $params);
$total = (int) $count->fetchColumn();
$pages = max(1, (int) ceil($total / $pageSize));
$page = min($page, $pages);
$statement = $pdo->prepare(
    "SELECT m.id, m.folio, m.type, m.reference, m.note, m.created_at, u.username, scoped.line_count
     FROM movements m JOIN app_users u ON u.id = m.created_by
     CROSS JOIN LATERAL (
         SELECT COUNT(*) AS line_count
         FROM movement_lines ml
         JOIN inventory_items i ON i.id = ml.item_id
         LEFT JOIN warehouse_locations l ON l.id = i.location_id
         WHERE ml.movement_id = m.id AND " . inventoryAccessSql('i', 'l', 'history_list') . "
     ) scoped
     WHERE scoped.line_count > 0" . $movementPrivacy . ($where === [] ? '' : ' AND ' . implode(' AND ', $where)) . "
     ORDER BY m.id DESC LIMIT :limit OFFSET :offset",
);
$listParams = inventoryAccessParams('history_list') + $params;
if (!isAdmin()) {
    $listParams += inventoryAccessParams('history_hidden');
    $listParams['building_user_id_movement_from'] = $_SESSION['user_id'];
    $listParams['building_user_id_movement_to'] = $_SESSION['user_id'];
}
foreach ($listParams as $key => $value) {
    $statement->bindValue(':' . $key, $value);
}
$statement->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$statement->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
$statement->execute();
$history = $statement->fetchAll();

pageHeader('Movimientos', 'movimientos');
?>
<div class="content-grid movement-layout">
<section class="panel"><div class="panel-heading"><div><h2>Registrar operación</h2><p>Una operación puede incluir hasta 500 artículos.</p></div></div>
<form method="post" class="form-stack movement-form">
    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
    <?php if ($prefillItem !== null): ?>
        <div class="item-prefill">
            <p class="eyebrow">DATOS DE LA FICHA</p>
            <div class="detail-grid">
                <div><span>Artículo / código</span><strong><?= h($prefillItem['item_code']) ?></strong></div>
                <div><span>Descripción</span><strong><?= h($prefillItem['description'] ?: '—') ?></strong></div>
                <div><span>Cliente / cuenta</span><strong><?= h(($prefillItem['client_name'] ?? $prefillItem['account_name']) ?: '—') ?></strong></div>
                <div><span>Código de cuenta</span><strong><?= h(($prefillItem['client_code'] ?? $prefillItem['account_code']) ?: '—') ?></strong></div>
                <div><span>Departamento</span><strong><?= h(($prefillItem['department_name'] ?? '') ?: '—') ?><?= !empty($prefillItem['department_code']) ? ' · ' . h($prefillItem['department_code']) : '' ?></strong></div>
                <div><span>Código alternativo</span><strong><?= h($prefillItem['alternate_code'] ?: '—') ?></strong></div>
                <div><span>Ubicación actual</span><strong><?= $prefillItem['location_code'] ? h($prefillItem['building_code'] . ' · ' . $prefillItem['location_code']) : '—' ?></strong></div>
                <div><span>Estado actual</span><strong><?= h(statusLabel($prefillItem['status'])) ?></strong></div>
                <div><span>Permiso</span><strong><?= databaseBoolean($prefillItem['permitted']) ? 'Sí' : 'No' ?></strong></div>
                <div><span>Fecha de alta</span><strong><?= h($prefillItem['added_date'] ?: '—') ?></strong></div>
                <div><span>Origen del registro</span><strong><?= h($prefillItem['source_sheet']) ?></strong></div>
            </div>
        </div>
    <?php endif; ?>
    <label>Tipo de movimiento<select name="type" required><?php if ($prefillItem !== null && $defaultMovementType === ''): ?><option value="" selected disabled>Selecciona una operación válida para el estado actual</option><?php endif; ?><option value="receive" <?= $defaultMovementType === 'receive' ? 'selected' : '' ?>>Alta · procesar artículos</option><option value="transfer" <?= $defaultMovementType === 'transfer' ? 'selected' : '' ?>>Traslado · cambio de ubicación</option><option value="dispatch" <?= $defaultMovementType === 'dispatch' ? 'selected' : '' ?>>Salida · despacho temporal</option><option value="return" <?= $defaultMovementType === 'return' ? 'selected' : '' ?>>Devolución · reingreso de salida</option><option value="writeoff" <?= $defaultMovementType === 'writeoff' ? 'selected' : '' ?>>Baja permanente · retiro definitivo</option></select></label>
    <label>Códigos de artículos<textarea name="item_codes" rows="3" required placeholder="Uno por línea, o separados por coma"><?= h($prefillItem['item_code'] ?? '') ?></textarea><small>La operación completa se revierte si un código no existe, no es único, no cumple el estado esperado o excede capacidad.</small></label>
    <div class="form-row">
        <label>Edificio<input name="building_code" maxlength="40" list="buildings" placeholder="Ej.: GUA"></label>
        <label>Código de ubicación<input name="location_code" maxlength="100" list="location-codes" placeholder="Ej.: CTJ081"></label>
    </div>
    <datalist id="buildings"><?php foreach (array_unique(array_column($locations, 'building_code')) as $building): ?><option value="<?= h($building) ?>"><?php endforeach; ?></datalist>
    <datalist id="location-codes"><?php foreach ($locations as $location): ?><option value="<?= h($location['code']) ?>" data-building="<?= h($location['building_code']) ?>"><?= h($location['building_code']) ?> · <?= h($location['name']) ?></option><?php endforeach; ?></datalist>
    <label>Nombre / descripción de ubicación<input name="location_name" maxlength="200" placeholder="Opcional"></label>
    <label class="checkbox-label"><input type="checkbox" name="permitted" <?= $prefillItem !== null && databaseBoolean($prefillItem['permitted']) ? 'checked' : '' ?>> Artículo con permiso</label>
    <label>Referencia<input name="reference" maxlength="120" placeholder="Orden, vale o documento"></label>
    <label>Notas<textarea name="note" rows="2" maxlength="4000" placeholder="Observaciones opcionales"></textarea></label>
    <button class="button primary full" type="submit">Guardar movimiento</button>
</form></section>
<section class="panel"><div class="panel-heading"><div><h2>Historial</h2><p><?= number_format($total) ?> movimientos registrados</p></div></div>
    <form class="filter-form compact-filter" method="get"><label class="search-field">Buscar folio, referencia o usuario<input type="search" name="q" value="<?= h($search) ?>" placeholder="MOV-..."></label><button class="button secondary" type="submit">Buscar</button></form>
    <?php if ($history === []): ?><div class="empty-state compact"><strong><?= $total === 0 ? 'Sin movimientos registrados' : 'Sin coincidencias' ?></strong><p>El inventario inicial no se registra como movimiento.</p></div><?php else: ?>
    <div class="movement-list"><?php foreach ($history as $movement): ?><article class="movement-card"><div class="movement-top"><span class="movement-kind"><?= h(movementLabel($movement['type'])) ?></span><span class="mono"><?= h($movement['folio'] ?: ('MOV-' . $movement['id'])) ?></span></div><div class="movement-meta"><span><?= number_format((int) $movement['line_count']) ?> artículo(s)</span><span><?= h($movement['reference'] ?: 'Sin referencia') ?></span></div><?php if ($movement['note'] !== ''): ?><p class="movement-note"><?= h($movement['note']) ?></p><?php endif; ?><div class="movement-foot"><span><?= h($movement['username']) ?></span><span><?= h(date('d/m/Y H:i', strtotime($movement['created_at']))) ?></span></div></article><?php endforeach; ?></div>
    <?php if ($pages > 1): ?><div class="pagination"><?php if ($page > 1): ?><a href="?<?= http_build_query(['q' => $search, 'page' => $page - 1]) ?>">← Anterior</a><?php endif; ?><span><?= $page ?> / <?= $pages ?></span><?php if ($page < $pages): ?><a href="?<?= http_build_query(['q' => $search, 'page' => $page + 1]) ?>">Siguiente →</a><?php endif; ?></div><?php endif; ?>
    <?php endif; ?>
</section></div>
<script>
    const buildingInput = document.querySelector('input[name="building_code"]');
    const locationInput = document.querySelector('input[name="location_code"]');
    const locationList = document.getElementById('location-codes');
    const allLocationOptions = Array.from(locationList.options);
    buildingInput.addEventListener('input', function () {
        const buildingCode = this.value.trim().toUpperCase();
        const matchingOptions = buildingCode === ''
            ? allLocationOptions
            : allLocationOptions.filter(option => option.dataset.building === buildingCode);

        locationList.replaceChildren(...matchingOptions);
        const locationCode = locationInput.value.trim().toUpperCase();
        if (locationCode && !matchingOptions.some(option => option.value === locationCode)) {
            locationInput.value = '';
        }
    });
</script>
<?php pageFooter(); ?>
