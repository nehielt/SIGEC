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
        $reference = in_array($type, ['receive', 'writeoff'], true) ? '' : trim((string) ($_POST['reference'] ?? ''));
        $note = in_array($type, ['receive', 'writeoff'], true) ? '' : trim((string) ($_POST['note'] ?? ''));
        if (strlen($reference) > 120 || strlen($note) > 4000) {
            throw new InvalidArgumentException('La referencia o la nota supera el largo permitido.');
        }
        $contactId = 0;
        if ($type === 'dispatch') {
            $contactId = (int) ($_POST['contact_id'] ?? 0);
            if ($contactId < 1) {
                throw new InvalidArgumentException('Selecciona a la persona responsable de recibir la remesa.');
            }
        }

        $pdo->beginTransaction();
        $newLocationId = null;
        $dispatchContact = null;
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
                                         SELECT p.client_id, p.prefix
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
                'SELECT i.id, i.item_code, i.location_id, i.status, i.client_id,
                        code_prefix.client_id AS prefix_client_id
                 FROM inventory_items i
                 LEFT JOIN warehouse_locations l ON l.id = i.location_id
                 LEFT JOIN LATERAL (
                     SELECT p.client_id
                     FROM client_item_prefixes p
                     WHERE LEFT(i.item_code, LENGTH(p.prefix)) = p.prefix
                     ORDER BY LENGTH(p.prefix) DESC
                     LIMIT 1
                 ) code_prefix ON TRUE
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
            if ($type === 'dispatch') {
                $contactStatement = $pdo->prepare(
                    'SELECT cc.client_id, cc.name, cc.job_title, c.name AS company_name
                     FROM client_contacts cc
                     JOIN clients c ON c.id = cc.client_id
                     WHERE cc.id = :id
                     FOR KEY SHARE OF cc',
                );
                $contactStatement->execute(['id' => $contactId]);
                $dispatchContact = $contactStatement->fetch();
                if (!$dispatchContact) {
                    throw new InvalidArgumentException('La persona responsable seleccionada ya no está disponible.');
                }

                $itemClientIds = [];
                foreach ($items as $item) {
                    if ($item['client_id'] === null) {
                        throw new RuntimeException('Todos los artículos de una remesa deben estar asociados a una empresa.');
                    }
                    if ($item['prefix_client_id'] === null) {
                        throw new RuntimeException('No hay una empresa configurada para el prefijo del artículo ' . $item['item_code'] . '.');
                    }
                    if ((int) $item['prefix_client_id'] !== (int) $item['client_id']) {
                        throw new RuntimeException('El prefijo del artículo ' . $item['item_code'] . ' no corresponde a la empresa registrada.');
                    }
                    $itemClientIds[(int) $item['prefix_client_id']] = true;
                }
                if (count($itemClientIds) !== 1 || !isset($itemClientIds[(int) $dispatchContact['client_id']])) {
                    throw new RuntimeException('Todos los artículos deben pertenecer a la empresa de la persona responsable de recibir la remesa.');
                }
            }
            if ($type === 'return') {
                $itemClientIds = [];
                foreach ($items as $item) {
                    if ($item['client_id'] === null) {
                        throw new RuntimeException('Todos los artículos de una devolución deben estar asociados a una empresa.');
                    }
                    if ($item['prefix_client_id'] === null) {
                        throw new RuntimeException('No hay una empresa configurada para el prefijo del artículo ' . $item['item_code'] . '.');
                    }
                    if ((int) $item['prefix_client_id'] !== (int) $item['client_id']) {
                        throw new RuntimeException('El prefijo del artículo ' . $item['item_code'] . ' no corresponde a la empresa registrada.');
                    }
                    $itemClientIds[(int) $item['prefix_client_id']] = true;
                }
                if (count($itemClientIds) !== 1) {
                    throw new RuntimeException('Todos los artículos de una devolución deben pertenecer al mismo cliente según su prefijo.');
                }
                ensureCapacity($pdo, $newLocationId, count($items));
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
        $transferItem = $pdo->prepare(
            'UPDATE inventory_items SET location_id = :location, updated_at = NOW()
             WHERE id = :id AND status = :from_status',
        );
        $writeoffItem = $pdo->prepare(
            'UPDATE inventory_items SET status = :status, updated_at = NOW()
             WHERE id = :id AND status = :from_status',
        );
        $receiveItem = $pdo->prepare(
            'UPDATE inventory_items SET location_id = :location, status = :status, updated_at = NOW()
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
                    'id' => $itemId,
                    'from_status' => 'unprocessed',
                ]);
                if ($receiveItem->rowCount() !== 1) {
                    throw new RuntimeException('El artículo ' . $item['item_code'] . ' dejó de estar no procesado. No se guardó la recepción.');
                }
            } elseif ($type === 'transfer') {
                $transferItem->execute([
                    'location' => $toLocation,
                    'id' => $itemId,
                    'from_status' => 'in',
                ]);
                if ($transferItem->rowCount() !== 1) {
                    throw new RuntimeException('El artículo ' . $item['item_code'] . ' dejó de estar En almacén. No se guardó el traslado.');
                }
            } elseif ($type === 'writeoff') {
                $writeoffItem->execute([
                    'status' => $destinationStatus,
                    'id' => $itemId,
                    'from_status' => $fromStatus,
                ]);
                if ($writeoffItem->rowCount() !== 1) {
                    throw new RuntimeException('El artículo ' . $item['item_code'] . ' cambió de estado. No se guardó la baja.');
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
        $remesaNumber = null;
        if ($type === 'dispatch') {
            $remesaNumber = sprintf('REM-%s-%06d', date('Ymd'), $movementId);
            $remesaStatement = $pdo->prepare(
                'INSERT INTO remesa (
                    number, movement_id, contact_id, company_name, contact_name, contact_job_title
                 ) VALUES (
                    :number, :movement_id, :contact_id, :company_name, :contact_name, :contact_job_title
                 ) RETURNING id',
            );
            $remesaStatement->execute([
                'number' => $remesaNumber,
                'movement_id' => $movementId,
                'contact_id' => $contactId,
                'company_name' => $dispatchContact['company_name'],
                'contact_name' => $dispatchContact['name'],
                'contact_job_title' => $dispatchContact['job_title'],
            ]);
            $remesaId = (int) $remesaStatement->fetchColumn();
            $pendingItemStatement = $pdo->prepare(
                'INSERT INTO remesa_pending_items (remesa_id, item_id)
                 VALUES (:remesa_id, :item_id)',
            );
            foreach ($items as $item) {
                $pendingItemStatement->execute([
                    'remesa_id' => $remesaId,
                    'item_id' => (int) $item['id'],
                ]);
            }
        }
        if ($type === 'return') {
            $resolvePendingStatement = $pdo->prepare(
                'UPDATE remesa_pending_items
                 SET resolved_by_movement_id = :movement_id, resolved_at = NOW()
                 WHERE id = (
                     SELECT rp.id
                     FROM remesa_pending_items rp
                     JOIN remesa r ON r.id = rp.remesa_id
                     JOIN movements dispatch_m ON dispatch_m.id = r.movement_id
                     WHERE rp.item_id = :item_id
                       AND rp.resolved_at IS NULL
                     ORDER BY dispatch_m.created_at DESC, rp.id DESC
                     LIMIT 1
                 )',
            );
            foreach ($items as $item) {
                $resolvePendingStatement->execute([
                    'movement_id' => $movementId,
                    'item_id' => (int) $item['id'],
                ]);
            }
        }
        $pdo->commit();
        $successLabel = match ($type) {
            'receive' => 'Recepción registrada',
            'transfer' => 'Traslado registrado',
            'dispatch' => 'Salida registrada',
            'return' => 'Devolución registrada',
            'writeoff' => 'Baja registrada',
        };
        flash(
            'success',
            $successLabel . ': ' . $folio
                . ($remesaNumber !== null ? ' · Remesa: ' . $remesaNumber : '')
                . ' · ' . count($codes) . ' artículo(s).',
        );
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        flash('error', $exception->getCode() === '23505'
            ? 'Uno de los códigos ya existe o el folio/número de remesa está duplicado; no se guardó ningún cambio.'
            : 'No se pudo registrar el movimiento. No se guardó ningún cambio; revisa los datos e inténtalo otra vez.');
    } catch (InvalidArgumentException|RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $exception->getMessage());
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
$contactStatement = $pdo->query(
    'SELECT cc.id, cc.client_id, cc.name, cc.job_title, c.name AS company_name
     FROM client_contacts cc
     JOIN clients c ON c.id = cc.client_id
     ORDER BY c.name, cc.name, cc.id',
);
$contacts = $contactStatement->fetchAll();
$prefixStatement = $pdo->query(
    'SELECT p.prefix, p.client_id, c.name AS company_name
     FROM client_item_prefixes p
     JOIN clients c ON c.id = p.client_id
     ORDER BY LENGTH(p.prefix) DESC, p.prefix',
);
$codePrefixes = array_map(
    static fn(array $prefix): array => [
        'prefix' => $prefix['prefix'],
        'client_id' => (int) $prefix['client_id'],
        'company_name' => $prefix['company_name'],
    ],
    $prefixStatement->fetchAll(),
);
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
    $where[] = '(m.folio ILIKE :search_folio OR r.number ILIKE :search_remesa
                 OR m.reference ILIKE :search_reference OR u.username ILIKE :search_username)';
    $term = '%' . $search . '%';
    $params = [
        'search_folio' => $term,
        'search_remesa' => $term,
        'search_reference' => $term,
        'search_username' => $term,
    ];
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
     LEFT JOIN remesa r ON r.movement_id = m.id
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
    "SELECT m.id, m.folio, m.type, m.reference, m.note, m.created_at, u.username,
            r.number AS remesa_number, scoped.line_count
     FROM movements m JOIN app_users u ON u.id = m.created_by
     LEFT JOIN remesa r ON r.movement_id = m.id
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
    <label>Códigos de artículos<textarea name="item_codes" rows="3" required placeholder="Uno por línea, o separados por coma"><?= h($prefillItem['item_code'] ?? '') ?></textarea><small id="item-codes-hint">La operación completa se revierte si un código no existe, no es único, no cumple el estado esperado o excede capacidad.</small></label>
    <label id="dispatch-contact-field" hidden>Persona responsable de recibir la remesa
        <select name="contact_id" disabled>
            <option value="">Selecciona un contacto</option>
            <?php foreach ($contacts as $contact): ?>
                <option value="<?= (int) $contact['id'] ?>" data-client-id="<?= (int) $contact['client_id'] ?>"><?= h($contact['company_name'] . ' · ' . $contact['name'] . ' — ' . $contact['job_title']) ?></option>
            <?php endforeach; ?>
        </select>
        <small id="dispatch-company-status">El código del artículo determinará la empresa y sus contactos disponibles.</small>
        <?php if ($contacts === []): ?><small>No hay personas de contacto registradas. <?php if (isAdmin()): ?><a class="table-link" href="clients.php">Agregar contactos</a><?php else: ?>Solicita al administrador que las registre en Clientes.<?php endif; ?></small><?php endif; ?>
        <?php if ($contacts !== []): ?><small>Todos los artículos de la remesa deben pertenecer a la empresa detectada.</small><?php endif; ?>
    </label>
    <div id="destination-location-fields">
        <div class="form-row">
            <label>Edificio<input name="building_code" maxlength="40" list="buildings" placeholder="Ej.: GUA"></label>
            <label>Código de ubicación<input name="location_code" maxlength="100" list="location-codes" placeholder="Ej.: CTJ081"></label>
        </div>
        <datalist id="buildings"><?php foreach (array_unique(array_column($locations, 'building_code')) as $building): ?><option value="<?= h($building) ?>"><?php endforeach; ?></datalist>
        <datalist id="location-codes"><?php foreach ($locations as $location): ?><option value="<?= h($location['code']) ?>" data-building="<?= h($location['building_code']) ?>"><?= h($location['building_code']) ?> · <?= h($location['name']) ?></option><?php endforeach; ?></datalist>
        <label id="location-name-field">Nombre / descripción de ubicación<input name="location_name" maxlength="200" placeholder="Opcional"></label>
    </div>
    <label class="checkbox-label" id="permitted-field"><input type="checkbox" name="permitted" <?= $prefillItem !== null && databaseBoolean($prefillItem['permitted']) ? 'checked' : '' ?>> Artículo con permiso</label>
    <label>Referencia<input name="reference" maxlength="120" placeholder="Orden, vale o documento"></label>
    <label>Notas<textarea name="note" rows="2" maxlength="4000" placeholder="Observaciones opcionales"></textarea></label>
    <button class="button primary full" type="submit">Guardar movimiento</button>
</form></section>
<section class="panel"><div class="panel-heading"><div><h2>Historial</h2><p><?= number_format($total) ?> movimientos registrados</p></div></div>
    <form class="filter-form compact-filter" method="get"><label class="search-field">Buscar folio, remesa, referencia o usuario<input type="search" name="q" value="<?= h($search) ?>" placeholder="MOV-... / REM-..."></label><button class="button secondary" type="submit">Buscar</button></form>
    <?php if ($history === []): ?><div class="empty-state compact"><strong><?= $total === 0 ? 'Sin movimientos registrados' : 'Sin coincidencias' ?></strong><p>El inventario inicial no se registra como movimiento.</p></div><?php else: ?>
    <div class="movement-list"><?php foreach ($history as $movement): ?><article class="movement-card"><div class="movement-top"><span class="movement-kind"><?= h(movementLabel($movement['type'])) ?></span><span class="mono"><?= h($movement['folio'] ?: ('MOV-' . $movement['id'])) ?></span></div><div class="movement-meta"><span><?= number_format((int) $movement['line_count']) ?> artículo(s)</span><span><?= h($movement['reference'] ?: 'Sin referencia') ?></span><?php if ($movement['remesa_number'] !== null): ?><span>Remesa: <strong class="mono"><?= h($movement['remesa_number']) ?></strong></span><?php endif; ?></div><?php if ($movement['note'] !== ''): ?><p class="movement-note"><?= h($movement['note']) ?></p><?php endif; ?><div class="movement-foot"><span><?= h($movement['username']) ?></span><span><?= h(date('d/m/Y H:i', strtotime($movement['created_at']))) ?></span></div></article><?php endforeach; ?></div>
    <?php if ($pages > 1): ?><div class="pagination"><?php if ($page > 1): ?><a href="?<?= http_build_query(['q' => $search, 'page' => $page - 1]) ?>">← Anterior</a><?php endif; ?><span><?= $page ?> / <?= $pages ?></span><?php if ($page < $pages): ?><a href="?<?= http_build_query(['q' => $search, 'page' => $page + 1]) ?>">Siguiente →</a><?php endif; ?></div><?php endif; ?>
    <?php endif; ?>
</section></div>
<script>
    const movementTypeSelect = document.querySelector('select[name="type"]');
    const dispatchContactField = document.getElementById('dispatch-contact-field');
    const dispatchContactSelect = dispatchContactField.querySelector('select');
    const itemCodesInput = document.querySelector('textarea[name="item_codes"]');
    const dispatchCompanyStatus = document.getElementById('dispatch-company-status');
    const contactPlaceholder = dispatchContactSelect.options[0].cloneNode(true);
    const contactOptions = Array.from(dispatchContactSelect.options).slice(1);
    const itemCodePrefixes = <?= json_encode($codePrefixes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const itemCodesHint = document.getElementById('item-codes-hint');
    const destinationLocationFields = document.getElementById('destination-location-fields');
    const destinationLocationInputs = destinationLocationFields.querySelectorAll('input');
    const locationNameField = document.getElementById('location-name-field');
    const locationNameInput = locationNameField.querySelector('input');
    const permittedField = document.getElementById('permitted-field');
    const permittedInput = permittedField.querySelector('input');
    const referenceInput = document.querySelector('input[name="reference"]');
    const noteInput = document.querySelector('textarea[name="note"]');
    const defaultItemCodesHint = 'La operación completa se revierte si un código no existe, no es único, no cumple el estado esperado o excede capacidad.';
    function updateDispatchContactOptions() {
        const codes = itemCodesInput.value.trim().split(/[\s,;]+/u).filter(Boolean);
        if (codes.length === 0) {
            dispatchContactSelect.replaceChildren(contactPlaceholder.cloneNode(true));
            dispatchCompanyStatus.textContent = 'El código del artículo determinará la empresa y sus contactos disponibles.';
            return;
        }

        const matches = codes.map(code => itemCodePrefixes.find(entry => code.startsWith(entry.prefix)));
        if (matches.some(match => !match)) {
            dispatchContactSelect.replaceChildren(contactPlaceholder.cloneNode(true));
            dispatchCompanyStatus.textContent = 'No se encontró una empresa configurada para el prefijo de uno de los códigos.';
            return;
        }

        const clientIds = new Set(matches.map(match => match.client_id));
        if (clientIds.size !== 1) {
            dispatchContactSelect.replaceChildren(contactPlaceholder.cloneNode(true));
            dispatchCompanyStatus.textContent = 'Los códigos corresponden a empresas distintas. Una remesa solo puede incluir artículos de una empresa.';
            return;
        }

        const clientId = matches[0].client_id;
        const companyName = matches[0].company_name;
        const matchingContacts = contactOptions.filter(option => Number(option.dataset.clientId) === clientId);
        const selectedContactId = dispatchContactSelect.value;
        dispatchContactSelect.replaceChildren(contactPlaceholder.cloneNode(true), ...matchingContacts);
        if (matchingContacts.some(option => option.value === selectedContactId)) {
            dispatchContactSelect.value = selectedContactId;
        }
        dispatchCompanyStatus.textContent = matchingContacts.length > 0
            ? `Empresa detectada: ${companyName}. Selecciona a la persona responsable.`
            : `Empresa detectada: ${companyName}, pero no tiene personas de contacto registradas.`;
    }

    function updateDispatchFields() {
        const movementType = movementTypeSelect.value;
        const isDispatch = movementType === 'dispatch';
        const isTransfer = movementType === 'transfer';
        const isReturn = movementType === 'return';
        const isWriteoff = movementType === 'writeoff';
        const isReceive = movementType === 'receive';
        dispatchContactField.hidden = !isDispatch;
        dispatchContactSelect.disabled = !isDispatch;
        dispatchContactSelect.required = isDispatch;
        destinationLocationFields.hidden = isDispatch;
        destinationLocationInputs.forEach(input => {
            input.disabled = isDispatch || isWriteoff;
        });
        locationNameField.hidden = isTransfer || isReturn || isReceive;
        locationNameInput.disabled = isDispatch || isTransfer || isReturn || isWriteoff || isReceive;
        permittedField.hidden = isTransfer;
        permittedInput.disabled = isDispatch || isTransfer || isReturn || isWriteoff || isReceive;
        referenceInput.disabled = isDispatch || isTransfer || isWriteoff || isReturn || isReceive;
        noteInput.disabled = isDispatch || isTransfer || isWriteoff || isReturn || isReceive;
        itemCodesHint.textContent = isDispatch
            ? 'La salida solo admite artículos En almacén de una misma empresa. Quedarán con estado En salida y sin ubicación.'
            : movementType === 'transfer'
                ? 'El traslado solo admite artículos En almacén. Solo cambia su ubicación; se conserva su cliente y demás datos.'
                : movementType === 'return'
                    ? 'La devolución solo admite artículos En salida de un mismo cliente según su prefijo. Los pendientes se actualizan automáticamente.'
                    : isWriteoff
                        ? 'La baja permanente solo cambiará el estatus del artículo; su ubicación y demás datos se conservan.'
                        : isReceive
                            ? 'El alta procesa los artículos y registra únicamente el edificio y la ubicación indicados.'
                        : defaultItemCodesHint;
        if (isDispatch) {
            updateDispatchContactOptions();
        }
    }
    movementTypeSelect.addEventListener('change', updateDispatchFields);
    itemCodesInput.addEventListener('input', updateDispatchContactOptions);
    updateDispatchFields();

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
