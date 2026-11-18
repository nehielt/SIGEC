<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
requireAuth($pdo);

$id = trim((string) ($_GET['id'] ?? ''));
$statement = $pdo->prepare(
    "SELECT i.id, i.external_id, i.item_code,
        COALESCE(c.name, i.account_name) AS account_name,
        COALESCE(c.code, i.account_code) AS account_code,
        d.department_code, d.name AS department_name,
        i.alternate_code, i.location_id, i.status, i.permitted, i.added_date, i.source_sheet,
        l.building_code, l.code AS location_code, l.name AS location_name
     FROM inventory_items i LEFT JOIN warehouse_locations l ON l.id = i.location_id
     LEFT JOIN clients c ON c.id = i.client_id
     LEFT JOIN departaments d ON d.id = i.department_id AND d.client_id = i.client_id
     WHERE i.deleted_at IS NULL AND i.external_id = :id AND " . inventoryAccessSql(),
);
$statement->execute(['id' => $id] + inventoryAccessParams());
$item = $statement->fetch();
if (!$item) {
    http_response_code(404);
    pageHeader('Artículo no encontrado', 'inventario');
    echo '<section class="panel"><p>No se encontró ese artículo.</p><a class="button secondary" href="inventory.php">Volver al inventario</a></section>';
    pageFooter();
    exit;
}
$history = $pdo->prepare(
    "SELECT m.folio, m.id, m.type, m.reference, m.note, m.created_at, u.username,
        fl.building_code AS from_building, fl.code AS from_code,
        tl.building_code AS to_building, tl.code AS to_code, ml.from_status, ml.to_status
     FROM movement_lines ml JOIN movements m ON m.id = ml.movement_id
     JOIN app_users u ON u.id = m.created_by
     LEFT JOIN warehouse_locations fl ON fl.id = ml.from_location_id
     LEFT JOIN warehouse_locations tl ON tl.id = ml.to_location_id
     WHERE ml.item_id = :item_id
       AND (" . buildingAccessSql('fl', 'from') . " OR fl.id IS NULL)
       AND (" . buildingAccessSql('tl', 'to') . " OR tl.id IS NULL)
     ORDER BY m.id DESC",
);
$historyParams = ['item_id' => $item['id']];
if (!isAdmin()) {
    $historyParams['building_user_id_from'] = $_SESSION['user_id'];
    $historyParams['building_user_id_to'] = $_SESSION['user_id'];
}
$history->execute($historyParams);
pageHeader('Ficha del artículo', 'inventario');
?>
<p class="back-link"><a href="inventory.php">← Volver al inventario</a></p>
<section class="panel item-detail">
    <div class="item-detail-head"><div><p class="eyebrow">ARTÍCULO / CÓDIGO</p><h2 class="mono"><?= h($item['item_code']) ?></h2></div><span class="status-badge status-<?= h($item['status']) ?>"><?= h(statusLabel($item['status'])) ?></span></div>
    <div class="detail-grid">
        <div><span>Cliente / cuenta</span><strong><?= h($item['account_name']) ?></strong></div>
        <div><span>Código de cliente</span><strong><?= h($item['account_code'] ?: '—') ?></strong></div>
        <div><span>Departamento</span><strong><?= h(($item['department_name'] ?: '—') . ($item['department_code'] ? ' · ' . $item['department_code'] : '')) ?></strong></div>
        <div><span>Código alternativo</span><strong><?= h($item['alternate_code'] ?: '—') ?></strong></div>
        <div><span>Ubicación actual</span><strong><?= $item['location_code'] ? h($item['building_code'] . ' · ' . $item['location_code']) : '—' ?></strong></div><div><span>Permiso</span><strong><?= $item['permitted'] ? 'Sí' : 'No' ?></strong></div>
        <div><span>Fecha de alta</span><strong><?= h($item['added_date'] ?: '—') ?></strong></div><div><span>Origen del registro</span><strong><?= h($item['source_sheet']) ?></strong></div>
    </div>
</section>
<section class="panel"><div class="panel-heading"><div><h2>Historial de movimientos</h2><p>Trazabilidad de este artículo</p></div><a class="button secondary" href="movements.php?item_code=<?= urlencode($item['item_code']) ?>">Registrar movimiento</a></div>
    <?php $entries = $history->fetchAll(); if ($entries === []): ?><div class="empty-state compact"><strong>Sin movimientos registrados</strong><p>El inventario importado es una fotografía inicial, no un movimiento.</p></div><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Folio</th><th>Tipo</th><th>Desde</th><th>Hacia</th><th>Referencia / nota</th><th>Usuario · fecha</th></tr></thead><tbody><?php foreach ($entries as $entry): ?><tr><td class="mono"><?= h($entry['folio'] ?: ('MOV-' . $entry['id'])) ?></td><td><?= h(movementLabel($entry['type'])) ?></td><td><?= h($entry['from_code'] ? $entry['from_building'] . ' · ' . $entry['from_code'] : '—') ?></td><td><?= h($entry['to_code'] ? $entry['to_building'] . ' · ' . $entry['to_code'] : '—') ?></td><td><?= h(trim($entry['reference'] . ' ' . $entry['note']) ?: '—') ?></td><td><?= h($entry['username']) ?><br><small><?= h(date('d/m/Y H:i', strtotime($entry['created_at']))) ?></small></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php pageFooter(); ?>
