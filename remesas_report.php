<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
requireAuth($pdo);

if (!isAdmin()) {
    http_response_code(403);
    exit('No tienes permiso para ver reportes.');
}

$search = strtoupper(trim((string) ($_GET['q'] ?? '')));
$clientId = filter_var($_GET['client_id'] ?? null, FILTER_VALIDATE_INT);
$aging = (string) ($_GET['aging'] ?? '');
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$pendingOnly = ($_GET['pending_only'] ?? '') === '1';

if ($clientId === false) {
    $clientId = 0;
}
if (!in_array($aging, ['', 'up_to_15', '16_to_30', 'over_90'], true)) {
    $aging = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$kpiParams = [];
$kpiFilters = [
    'rp.resolved_at IS NULL',
    'i.deleted_at IS NULL',
];

if ($search !== '') {
    $kpiFilters[] = '(r.number ILIKE :kpi_search OR i.item_code ILIKE :kpi_search_item
                      OR i.description ILIKE :kpi_search_description OR r.company_name ILIKE :kpi_search_company
                      OR r.contact_name ILIKE :kpi_search_contact)';
    $kpiParams['kpi_search'] = '%' . $search . '%';
    $kpiParams['kpi_search_item'] = '%' . $search . '%';
    $kpiParams['kpi_search_description'] = '%' . $search . '%';
    $kpiParams['kpi_search_company'] = '%' . $search . '%';
    $kpiParams['kpi_search_contact'] = '%' . $search . '%';
}
if ($clientId > 0) {
    $kpiFilters[] = 'i.client_id = :kpi_client_id';
    $kpiParams['kpi_client_id'] = $clientId;
}
if ($dateFrom !== '') {
    $kpiFilters[] = 'dispatch_m.created_at::date >= CAST(:kpi_date_from AS date)';
    $kpiParams['kpi_date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $kpiFilters[] = 'dispatch_m.created_at::date <= CAST(:kpi_date_to AS date)';
    $kpiParams['kpi_date_to'] = $dateTo;
}

$baseSql = '
    FROM remesa_pending_items rp
    JOIN remesa r ON r.id = rp.remesa_id
    JOIN movements dispatch_m ON dispatch_m.id = r.movement_id
    JOIN inventory_items i ON i.id = rp.item_id
    LEFT JOIN warehouse_locations l ON l.id = i.location_id
';

$kpiStatement = $pdo->prepare(
    "SELECT
        COUNT(*) FILTER (
            WHERE dispatch_m.created_at >= NOW() - INTERVAL '15 days'
        ) AS up_to_15_days,
        COUNT(*) FILTER (
            WHERE dispatch_m.created_at < NOW() - INTERVAL '15 days'
              AND dispatch_m.created_at >= NOW() - INTERVAL '30 days'
        ) AS days_16_to_30,
        COUNT(*) FILTER (
            WHERE dispatch_m.created_at < NOW() - INTERVAL '90 days'
        ) AS over_90_days
     " . $baseSql . ' WHERE ' . implode(' AND ', $kpiFilters)
);
$kpiStatement->execute($kpiParams);
$kpis = $kpiStatement->fetch();

$listParams = [];
$listFilters = [];

if ($search !== '') {
    $listFilters[] = '(r.number ILIKE :search OR m.folio ILIKE :search_folio
                       OR r.company_name ILIKE :search_company OR r.contact_name ILIKE :search_contact)';
    $listParams['search'] = '%' . $search . '%';
    $listParams['search_folio'] = '%' . $search . '%';
    $listParams['search_company'] = '%' . $search . '%';
    $listParams['search_contact'] = '%' . $search . '%';
}
if ($clientId > 0) {
    $listFilters[] = 'r.contact_id IN (
        SELECT cc.id FROM client_contacts cc WHERE cc.client_id = :client_id
    )';
    $listParams['client_id'] = $clientId;
}
if ($dateFrom !== '') {
    $listFilters[] = 'm.created_at::date >= CAST(:date_from AS date)';
    $listParams['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $listFilters[] = 'm.created_at::date <= CAST(:date_to AS date)';
    $listParams['date_to'] = $dateTo;
}
if ($pendingOnly) {
    $listFilters[] = 'EXISTS (
        SELECT 1
        FROM remesa_pending_items rp
        WHERE rp.remesa_id = r.id AND rp.resolved_at IS NULL
    )';
}
if ($aging === 'up_to_15') {
    $listFilters[] = "m.created_at >= NOW() - INTERVAL '15 days'";
} elseif ($aging === '16_to_30') {
    $listFilters[] = "m.created_at < NOW() - INTERVAL '15 days'
                      AND m.created_at >= NOW() - INTERVAL '30 days'";
} elseif ($aging === 'over_90') {
    $listFilters[] = "m.created_at < NOW() - INTERVAL '90 days'";
}

$listWhereSql = $listFilters === [] ? '' : ' WHERE ' . implode(' AND ', $listFilters);
$listStatement = $pdo->prepare(
    "SELECT r.id, r.number, r.company_name, r.contact_name, r.contact_job_title,
            m.folio, m.created_at,
            (SELECT COUNT(*)
             FROM movement_lines ml
             WHERE ml.movement_id = m.id) AS items_out,
            (SELECT COUNT(*)
             FROM remesa_pending_items rp
             WHERE rp.remesa_id = r.id AND rp.resolved_at IS NULL) AS items_pending,
            EXTRACT(DAY FROM (NOW() - m.created_at))::INTEGER AS days_pending
     FROM remesa r
     JOIN movements m ON m.id = r.movement_id
     " . $listWhereSql . '
     ORDER BY m.created_at DESC, r.number'
);
$listStatement->execute($listParams);
$remesas = $listStatement->fetchAll();

$clients = $pdo->query(
    'SELECT id, code, name FROM clients ORDER BY name, code',
)->fetchAll();

pageHeader('Remesas', 'reportes-remesas');
?>
<section class="panel">
    <div class="panel-heading">
        <div>
            <h2>Reporte de remesas</h2>
            <p>Artículos que salieron y pendientes de devolución por remesa.</p>
        </div>
        <span class="count-pill"><?= number_format(count($remesas)) ?> remesas</span>
    </div>

    <section class="stats-grid remesa-stats-grid">
        <article class="stat-card stat-card--teal">
            <span class="stat-label">Hasta 15 días</span>
            <strong><?= number_format((int) $kpis['up_to_15_days']) ?></strong>
            <span class="stat-note">Artículos pendientes</span>
        </article>
        <article class="stat-card stat-card--orange">
            <span class="stat-label">16–30 días</span>
            <strong><?= number_format((int) $kpis['days_16_to_30']) ?></strong>
            <span class="stat-note">Artículos pendientes</span>
        </article>
        <article class="stat-card stat-card--red">
            <span class="stat-label">Más de 90 días</span>
            <strong><?= number_format((int) $kpis['over_90_days']) ?></strong>
            <span class="stat-note">Artículos pendientes</span>
        </article>
    </section>

    <form class="filter-form" method="get">
        <label class="search-field">Buscar<input type="search" name="q" value="<?= h($search) ?>" placeholder="Remesa, artículo, empresa o responsable"></label>
        <label>Empresa
            <select name="client_id">
                <option value="">Todas</option>
                <?php foreach ($clients as $client): ?>
                    <option value="<?= (int) $client['id'] ?>" <?= $clientId === (int) $client['id'] ? 'selected' : '' ?>>
                        <?= h($client['name'] . ($client['code'] !== '' ? ' · ' . $client['code'] : '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Antigüedad
            <select name="aging">
                <option value="">Todas</option>
                <option value="up_to_15" <?= $aging === 'up_to_15' ? 'selected' : '' ?>>Hasta 15 días</option>
                <option value="16_to_30" <?= $aging === '16_to_30' ? 'selected' : '' ?>>16–30 días</option>
                <option value="over_90" <?= $aging === 'over_90' ? 'selected' : '' ?>>Más de 90 días</option>
            </select>
        </label>
        <label>Desde<input type="date" name="date_from" value="<?= h($dateFrom) ?>"></label>
        <label>Hasta<input type="date" name="date_to" value="<?= h($dateTo) ?>"></label>
        <label class="checkbox-label">
            <input type="checkbox" name="pending_only" value="1" <?= $pendingOnly ? 'checked' : '' ?>>
            Solo remesas con pendientes por devolver
        </label>
        <button class="button secondary" type="submit">Filtrar</button>
        <?php if ($search !== '' || $clientId > 0 || $aging !== '' || $dateFrom !== '' || $dateTo !== '' || $pendingOnly): ?>
            <a class="button secondary" href="remesas_report.php">Limpiar</a>
        <?php endif; ?>
    </form>

    <?php if ($remesas === []): ?>
        <div class="empty-state">
            <strong>No hay remesas registradas</strong>
            <p>Prueba con otros filtros o no existen remesas en el periodo seleccionado.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Remesa</th>
                        <th>Empresa</th>
                        <th>Responsable</th>
                        <th>Movimiento</th>
                        <th>Despacho</th>
                        <th>Artículos que salieron</th>
                        <th>Artículos por devolver</th>
                        <th>Antigüedad</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($remesas as $remesa): ?>
                        <tr>
                            <td class="mono">
                                <a class="table-link" href="remesas.php?<?= h(http_build_query(['id' => (int) $remesa['id']])) ?>" target="_blank" rel="noopener noreferrer">
                                    <?= h($remesa['number']) ?>
                                </a>
                            </td>
                            <td><?= h($remesa['company_name']) ?></td>
                            <td><?= h($remesa['contact_name']) ?><?= $remesa['contact_job_title'] !== '' ? ' · ' . h($remesa['contact_job_title']) : '' ?></td>
                            <td class="mono"><?= h($remesa['folio']) ?></td>
                            <td><?= h(date('d/m/Y', strtotime($remesa['created_at']))) ?></td>
                            <td><?= number_format((int) $remesa['items_out']) ?></td>
                            <td><?= number_format((int) $remesa['items_pending']) ?></td>
                            <td><?= number_format((int) $remesa['days_pending']) ?> días</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php pageFooter(); ?>
