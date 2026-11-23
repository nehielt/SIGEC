<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
requireAuth($pdo);

$search = trim((string) ($_GET['q'] ?? ''));
$selectedId = max(0, (int) ($_GET['id'] ?? 0));
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 30;
$where = ["m.type = 'dispatch'"];
$params = [];
if ($search !== '') {
    $where[] = '(r.number ILIKE :search_number OR m.folio ILIKE :search_folio
                 OR m.reference ILIKE :search_reference OR r.company_name ILIKE :search_company
                 OR r.contact_name ILIKE :search_contact)';
    $term = '%' . $search . '%';
    $params = [
        'search_number' => $term,
        'search_folio' => $term,
        'search_reference' => $term,
        'search_company' => $term,
        'search_contact' => $term,
    ];
}

$scopeSql = '';
$scopeParams = [];
if (!isAdmin()) {
    $scopeSql = '
        AND EXISTS (
            SELECT 1
            FROM movement_lines visible_ml
            JOIN inventory_items visible_i ON visible_i.id = visible_ml.item_id
            LEFT JOIN warehouse_locations visible_l ON visible_l.id = visible_i.location_id
            WHERE visible_ml.movement_id = m.id
              AND ' . inventoryAccessSql('visible_i', 'visible_l', 'remesa_visible') . '
        )
        AND NOT EXISTS (
            SELECT 1
            FROM movement_lines hidden_ml
            JOIN inventory_items hidden_i ON hidden_i.id = hidden_ml.item_id
            LEFT JOIN warehouse_locations hidden_l ON hidden_l.id = hidden_i.location_id
            WHERE hidden_ml.movement_id = m.id
              AND NOT ' . inventoryAccessSql('hidden_i', 'hidden_l', 'remesa_hidden') . '
        )
        AND NOT EXISTS (
            SELECT 1
            FROM movement_lines route_ml
            LEFT JOIN warehouse_locations route_from ON route_from.id = route_ml.from_location_id
            LEFT JOIN warehouse_locations route_to ON route_to.id = route_ml.to_location_id
            WHERE route_ml.movement_id = m.id
              AND (
                  (route_ml.from_location_id IS NOT NULL AND NOT ' . buildingAccessSql('route_from', 'remesa_from') . ')
                  OR (route_ml.to_location_id IS NOT NULL AND NOT ' . buildingAccessSql('route_to', 'remesa_to') . ')
              )
        )';
    $scopeParams = inventoryAccessParams('remesa_visible')
        + inventoryAccessParams('remesa_hidden')
        + [
            'building_user_id_remesa_from' => $_SESSION['user_id'],
            'building_user_id_remesa_to' => $_SESSION['user_id'],
        ];
}

$fromSql = '
    FROM remesa r
    JOIN movements m ON m.id = r.movement_id
    JOIN app_users u ON u.id = m.created_by';
$whereSql = ' WHERE ' . implode(' AND ', $where) . $scopeSql;
$countStatement = $pdo->prepare('SELECT COUNT(*) ' . $fromSql . $whereSql);
$countStatement->execute($params + $scopeParams);
$total = (int) $countStatement->fetchColumn();
$pages = max(1, (int) ceil($total / $pageSize));
$page = min($page, $pages);

$listStatement = $pdo->prepare(
    'SELECT r.id, r.number, r.created_at, m.folio, m.reference, r.company_name,
            r.contact_name, r.contact_job_title AS job_title, u.username,
            (SELECT COUNT(*) FROM movement_lines ml WHERE ml.movement_id = m.id) AS item_count,
            (SELECT COUNT(*) FROM remesa_pending_items rp
             WHERE rp.remesa_id = r.id AND rp.resolved_at IS NULL) AS pending_count
     ' . $fromSql . $whereSql . '
     ORDER BY r.id DESC
     LIMIT :limit OFFSET :offset',
);
foreach ($params + $scopeParams as $key => $value) {
    $listStatement->bindValue(':' . $key, $value);
}
$listStatement->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$listStatement->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
$listStatement->execute();
$remesas = $listStatement->fetchAll();

$selectedRemesa = null;
$remesaItems = [];
if ($selectedId > 0) {
    $selectedStatement = $pdo->prepare(
        'SELECT r.id, r.number, r.created_at, r.movement_id, m.folio, m.reference, m.note,
                m.created_at AS movement_created_at, r.company_name,
                r.contact_name, r.contact_job_title AS job_title, u.username
         ' . $fromSql . $whereSql . ' AND r.id = :selected_id',
    );
    $selectedStatement->execute($params + $scopeParams + ['selected_id' => $selectedId]);
    $selectedRemesa = $selectedStatement->fetch() ?: null;

    if ($selectedRemesa !== null) {
        $itemsStatement = $pdo->prepare(
            'SELECT i.item_code, i.description, ml.from_status,
                    source.building_code, source.code AS location_code,
                    rp.resolved_at, rp.resolved_by_movement_id
             FROM movement_lines ml
             JOIN inventory_items i ON i.id = ml.item_id
             LEFT JOIN warehouse_locations source ON source.id = ml.from_location_id
             LEFT JOIN remesa_pending_items rp
                ON rp.remesa_id = :remesa_id AND rp.item_id = i.id
             WHERE ml.movement_id = :movement_id
             ORDER BY i.item_code',
        );
        $itemsStatement->execute([
            'remesa_id' => $selectedRemesa['id'],
            'movement_id' => $selectedRemesa['movement_id'],
        ]);
        $remesaItems = $itemsStatement->fetchAll();
    }
}

pageHeader('Remesas', 'remesas');
?>
<section class="panel">
    <div class="panel-heading"><div><h2>Remesas registradas</h2><p><?= number_format($total) ?> remesas disponibles en tu alcance.</p></div></div>
    <form class="filter-form compact-filter" method="get">
        <label class="search-field">Buscar remesa, empresa o responsable<input type="search" name="q" value="<?= h($search) ?>" placeholder="REM-..."></label>
        <button class="button secondary" type="submit">Buscar</button>
    </form>
    <?php if ($remesas === []): ?>
        <div class="empty-state compact"><strong><?= $search === '' ? 'Sin remesas registradas' : 'No se encontraron remesas' ?></strong><p>Las remesas se generan al registrar una salida · despacho temporal.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Número</th><th>Empresa</th><th>Responsable</th><th>Movimiento</th><th>Artículos</th><th>Por devolver</th><th>Fecha</th><th>Detalle</th></tr></thead>
                <tbody>
                <?php foreach ($remesas as $remesa): ?>
                    <tr>
                        <td class="mono"><?= h($remesa['number']) ?></td>
                        <td><?= h($remesa['company_name']) ?></td>
                        <td><?= h($remesa['contact_name']) ?><br><small><?= h($remesa['job_title']) ?></small></td>
                        <td class="mono"><?= h($remesa['folio']) ?></td>
                        <td><?= number_format((int) $remesa['item_count']) ?></td>
                        <td><?= number_format((int) $remesa['pending_count']) ?></td>
                        <td><?= h(date('d/m/Y H:i', strtotime($remesa['created_at']))) ?></td>
                        <td><a class="table-link" href="remesas.php?<?= h(http_build_query(['q' => $search, 'page' => $page, 'id' => $remesa['id']])) ?>">Ver</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?><a href="?<?= h(http_build_query(['q' => $search, 'page' => $page - 1])) ?>">← Anterior</a><?php endif; ?>
                <span><?= $page ?> / <?= $pages ?></span>
                <?php if ($page < $pages): ?><a href="?<?= h(http_build_query(['q' => $search, 'page' => $page + 1])) ?>">Siguiente →</a><?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($selectedRemesa !== null): ?>
    <section class="panel">
        <div class="panel-heading"><div><h2>Remesa <?= h($selectedRemesa['number']) ?></h2><p>Información del despacho temporal y artículos incluidos.</p></div><a class="table-link" href="remesas.php?<?= h(http_build_query(['q' => $search, 'page' => $page])) ?>">Cerrar detalle</a></div>
        <div class="detail-grid">
            <div><span>Empresa</span><strong><?= h($selectedRemesa['company_name']) ?></strong></div>
            <div><span>Responsable de recibir</span><strong><?= h($selectedRemesa['contact_name']) ?> · <?= h($selectedRemesa['job_title']) ?></strong></div>
            <div><span>Folio de movimiento</span><strong><?= h($selectedRemesa['folio']) ?></strong></div>
            <div><span>Registró</span><strong><?= h($selectedRemesa['username']) ?></strong></div>
            <div><span>Fecha</span><strong><?= h(date('d/m/Y H:i', strtotime($selectedRemesa['created_at']))) ?></strong></div>
            <div><span>Referencia</span><strong><?= h($selectedRemesa['reference'] ?: '—') ?></strong></div>
        </div>
        <?php if ($selectedRemesa['note'] !== ''): ?><p class="movement-note"><?= h($selectedRemesa['note']) ?></p><?php endif; ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Artículo</th><th>Descripción</th><th>Estado al despacho</th><th>Ubicación de origen</th><th>Devolución</th></tr></thead>
                <tbody>
                <?php foreach ($remesaItems as $item): ?>
                    <tr>
                        <td class="mono"><?= h($item['item_code']) ?></td>
                        <td><?= h($item['description'] ?: '—') ?></td>
                        <td><?= h(statusLabel($item['from_status'])) ?></td>
                        <td><?= $item['location_code'] ? h($item['building_code'] . ' · ' . $item['location_code']) : '—' ?></td>
                        <td><?= $item['resolved_at'] !== null
                            ? 'Devuelto · ' . h(date('d/m/Y H:i', strtotime($item['resolved_at'])))
                            : '<strong>Pendiente por devolver</strong>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
<?php pageFooter(); ?>
