<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
requireAuth($pdo);

if (!isAdmin()) {
    http_response_code(403);
    exit('No tienes permiso para ver reportes.');
}

$reportStatement = $pdo->prepare(
    "SELECT
        CASE
            WHEN BTRIM(c.code) = '' THEN 'SIN CÓDIGO'
            ELSE LEFT(BTRIM(c.code), 4)
        END AS code_prefix,
        COUNT(i.id) AS total_items,
        COUNT(i.id) FILTER (WHERE i.status = 'in') AS in_stock,
        COUNT(i.id) FILTER (WHERE i.status = 'out') AS in_output,
        COUNT(i.id) FILTER (WHERE i.status = 'permanent_out') AS permanent_out
     FROM clients c
     LEFT JOIN inventory_items i ON i.client_id = c.id AND i.deleted_at IS NULL
     GROUP BY CASE
        WHEN BTRIM(c.code) = '' THEN 'SIN CÓDIGO'
        ELSE LEFT(BTRIM(c.code), 4)
     END
     ORDER BY code_prefix"
);
$reportStatement->execute();
$clientSummary = $reportStatement->fetchAll();

$clientNamesStatement = $pdo->prepare(
    "SELECT
        CASE
            WHEN BTRIM(code) = '' THEN 'SIN CÓDIGO'
            ELSE LEFT(BTRIM(code), 4)
        END AS code_prefix,
        name
     FROM clients
     ORDER BY code_prefix, name"
);
$clientNamesStatement->execute();
$clientNameGroups = [];
foreach ($clientNamesStatement->fetchAll() as $clientNameRow) {
    $codePrefix = (string) ($clientNameRow['code_prefix'] ?? 'SIN CÓDIGO');
    $name = (string) ($clientNameRow['name'] ?? 'Sin nombre');
    $clientNameGroups[$codePrefix][] = $name;
}

foreach ($clientSummary as &$summaryRow) {
    $summaryRow['client_names'] = implode(' / ', array_values(array_unique($clientNameGroups[(string) $summaryRow['code_prefix']] ?? [])));
}
unset($summaryRow);

$totals = [
    'total_items' => 0,
    'in_stock' => 0,
    'in_output' => 0,
    'permanent_out' => 0,
];
foreach ($clientSummary as $client) {
    $totals['total_items'] += (int) $client['total_items'];
    $totals['in_stock'] += (int) $client['in_stock'];
    $totals['in_output'] += (int) $client['in_output'];
    $totals['permanent_out'] += (int) $client['permanent_out'];
}

pageHeader('Reportes', 'reportes');
?>
<section class="panel">
    <div class="panel-heading">
        <div>
            <h2>Resumen de artículos por cliente</h2>
            <p>Totales consolidados por prefijo de 4 dígitos del código, separados con “ / ”</p>
        </div>
    </div>

    <section class="stats-grid">
        <article class="stat-card">
            <span class="stat-label">Artículos totales</span>
            <strong><?= number_format($totals['total_items']) ?></strong>
            <span class="stat-note"><span class="mini-dot green"></span>En todas las cuentas</span>
        </article>
        <article class="stat-card">
            <span class="stat-label">En almacén</span>
            <strong><?= number_format($totals['in_stock']) ?></strong>
            <span class="stat-note"><span class="mini-dot green"></span>Disponibles</span>
        </article>
        <article class="stat-card">
            <span class="stat-label">En salida</span>
            <strong><?= number_format($totals['in_output']) ?></strong>
            <span class="stat-note"><span class="mini-dot amber"></span>Fuera temporalmente</span>
        </article>
        <article class="stat-card">
            <span class="stat-label">Bajas permanentes</span>
            <strong><?= number_format($totals['permanent_out']) ?></strong>
            <span class="stat-note"><span class="mini-dot red"></span>Retirados del inventario</span>
        </article>
    </section>

    <?php if ($clientSummary === []): ?>
        <div class="empty-state compact">
            <strong>No hay clientes con artículos registrados</strong>
            <p>La vista se rellenará al asociar artículos a clientes durante la recepción.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Prefijo</th>
                        <th>Clientes</th>
                        <th>Total</th>
                        <th>En almacén</th>
                        <th>En salida</th>
                        <th>Bajas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clientSummary as $client): ?>
                        <tr>
                            <td><strong><?= h((string) $client['code_prefix']) ?></strong></td>
                            <td><?= h((string) ($client['client_names'] ?? 'Sin nombre')) ?></td>
                            <td><?= number_format((int) $client['total_items']) ?></td>
                            <td><?= number_format((int) $client['in_stock']) ?></td>
                            <td><?= number_format((int) $client['in_output']) ?></td>
                            <td><?= number_format((int) $client['permanent_out']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php pageFooter(); ?>
