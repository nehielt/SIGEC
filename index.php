<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
requireAuth($pdo);

function inventoryQuestionAnswer(PDO $pdo, string $question): string
{
    $scope = inventoryAccessSql('i', 'l', 'assistant');
    $scopeParams = inventoryAccessParams('assistant');

    if (preg_match('/\bedificio\s+([[:alnum:]_-]+)/iu', $question, $matches) === 1) {
        $buildingCode = strtoupper($matches[1]);
        $statement = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                COUNT(*) FILTER (WHERE i.status = 'in') AS stored,
                COUNT(*) FILTER (WHERE i.status = 'out') AS out,
                COUNT(*) FILTER (WHERE i.status = 'permanent_out') AS written_off
             FROM inventory_items i
             LEFT JOIN warehouse_locations l ON l.id = i.location_id
             WHERE i.deleted_at IS NULL AND l.building_code = :building_code AND {$scope}",
        );
        $statement->execute(['building_code' => $buildingCode] + $scopeParams);
        $counts = $statement->fetch();
        return sprintf(
            'Edificio %s: %s artículos en total; %s en almacén, %s en salida y %s bajas permanentes.',
            $buildingCode,
            number_format((int) $counts['total']),
            number_format((int) $counts['stored']),
            number_format((int) $counts['out']),
            number_format((int) $counts['written_off']),
        );
    }

    $rankingStatus = null;
    $rankingStatusLabel = 'artículos';
    if (preg_match('/\b(?:en\s+)?(?:almac[eé]n|disponibles?|stock)\b/iu', $question) === 1) {
        $rankingStatus = 'in';
        $rankingStatusLabel = 'artículos en almacén';
    } elseif (preg_match('/\b(?:en\s+)?(?:salida|fuera)\b/iu', $question) === 1) {
        $rankingStatus = 'out';
        $rankingStatusLabel = 'artículos en salida';
    } elseif (preg_match('/\b(?:bajas?|permanentes?)\b/iu', $question) === 1) {
        $rankingStatus = 'permanent_out';
        $rankingStatusLabel = 'bajas permanentes';
    }

    if (
        preg_match('/\b(?:ubicaci[oó]n|posici[oó]n)\b/iu', $question) === 1
        && preg_match('/\b(?:art[ií]culos?|inventario|stock)\b/iu', $question) === 1
    ) {
        $locationStatus = $rankingStatus ?? 'in';
        $locationStatusLabel = $rankingStatusLabel === 'artículos'
            ? 'artículos en almacén'
            : $rankingStatusLabel;
        $statement = $pdo->prepare(
            "SELECT l.building_code, l.code, l.name, COUNT(*) AS total
             FROM inventory_items i
             JOIN warehouse_locations l ON l.id = i.location_id
             WHERE i.deleted_at IS NULL
               AND i.status = :status
               AND {$scope}
             GROUP BY l.id
             ORDER BY total DESC, l.building_code, l.code
             LIMIT 1",
        );
        $statement->execute(['status' => $locationStatus] + $scopeParams);
        $row = $statement->fetch();
        if (!$row) {
            return 'No hay ' . $locationStatusLabel . ' en ubicaciones dentro de tu alcance.';
        }
        $locationLabel = $row['building_code'] . ' · ' . $row['code'];
        if ($row['name'] !== '') {
            $locationLabel .= ' (' . $row['name'] . ')';
        }
        return sprintf(
            'La ubicación con más %s es %s, con %s.',
            $locationStatusLabel,
            $locationLabel,
            number_format((int) $row['total']),
        );
    }

    $isAscendingClientRanking =
        preg_match('/\b(?:menos|menor(?:es)?)\b/iu', $question) === 1;
    $isClientRankingQuestion =
        preg_match('/\b(?:top|principales|mayores)\s+clientes?\b/iu', $question) === 1
        || preg_match('/\b(?:cliente|cuenta)\b.*\b(?:con\s+)?(?:m[aá]s|menos|mayor(?:es)?|menor(?:es)?|top|principal(?:es)?)\b.*\b(?:art[ií]culos?|inventario|stock)\b/iu', $question) === 1;
    if ($isClientRankingQuestion) {
        $statusFilter = $rankingStatus === null ? '' : ' AND i.status = :status';
        $rankingParams = $scopeParams;
        if ($rankingStatus !== null) {
            $rankingParams['status'] = $rankingStatus;
        }
        $statement = $pdo->prepare(
            "SELECT
                CASE
                    WHEN BTRIM(COALESCE(c.code, i.account_code)) = '' THEN 'SIN CÓDIGO'
                    ELSE LEFT(BTRIM(COALESCE(c.code, i.account_code)), 4)
                END AS code_prefix,
                COUNT(DISTINCT COALESCE(
                    c.id::text,
                    'account:' || BTRIM(i.account_code) || ':' || BTRIM(i.account_name)
                )) AS account_count,
                COUNT(*) AS total
             FROM inventory_items i
             LEFT JOIN warehouse_locations l ON l.id = i.location_id
             LEFT JOIN clients c ON c.id = i.client_id
             WHERE i.deleted_at IS NULL AND {$scope}{$statusFilter}
             GROUP BY CASE
                WHEN BTRIM(COALESCE(c.code, i.account_code)) = '' THEN 'SIN CÓDIGO'
                ELSE LEFT(BTRIM(COALESCE(c.code, i.account_code)), 4)
             END
             ORDER BY total " . ($isAscendingClientRanking ? 'ASC' : 'DESC') . ", code_prefix
             LIMIT 1",
        );
        $statement->execute($rankingParams);
        $row = $statement->fetch();
        if (!$row) {
            return 'No hay ' . $rankingStatusLabel . ' en tu alcance para determinar el grupo de clientes.';
        }
        $nameStatement = $pdo->prepare(
            "SELECT DISTINCT COALESCE(c.name, NULLIF(i.account_name, ''), 'Sin cliente') AS name
             FROM inventory_items i
             LEFT JOIN warehouse_locations l ON l.id = i.location_id
             LEFT JOIN clients c ON c.id = i.client_id
             WHERE i.deleted_at IS NULL
               AND CASE
                   WHEN BTRIM(COALESCE(c.code, i.account_code)) = '' THEN 'SIN CÓDIGO'
                   ELSE LEFT(BTRIM(COALESCE(c.code, i.account_code)), 4)
               END = :code_prefix
               AND {$scope}{$statusFilter}
             ORDER BY name",
        );
        $nameStatement->execute(
            ['code_prefix' => $row['code_prefix']] + $rankingParams,
        );
        $clientNames = $nameStatement->fetchAll(PDO::FETCH_COLUMN);
        $commonWords = preg_split('/\s+/u', trim((string) ($clientNames[0] ?? ''))) ?: [];
        foreach (array_slice($clientNames, 1) as $clientName) {
            $words = preg_split('/\s+/u', trim((string) $clientName)) ?: [];
            $commonWordCount = 0;
            while (
                isset($commonWords[$commonWordCount], $words[$commonWordCount])
                && strcasecmp($commonWords[$commonWordCount], $words[$commonWordCount]) === 0
            ) {
                $commonWordCount++;
            }
            $commonWords = array_slice($commonWords, 0, $commonWordCount);
            if ($commonWords === []) {
                break;
            }
        }
        $clientName = $commonWords === [] ? 'varias empresas' : implode(' ', $commonWords);
        return sprintf(
            'El grupo de cuentas con %s %s corresponde a %s (código %s; %s cuentas), con %s.',
            $isAscendingClientRanking ? 'menos' : 'más',
            $rankingStatusLabel,
            $clientName,
            $row['code_prefix'],
            number_format((int) $row['account_count']),
            number_format((int) $row['total']),
        );
    }

    if (
        preg_match('/\b(?:cliente|cuenta)\s+(.+?)(?:[?.!,]|$)/iu', $question, $matches) === 1
        && preg_match('/\b(?:cu[aá]ntos?|cantidad|n[uú]mero|top)\s+clientes?\b/iu', $question) !== 1
    ) {
        $clientSearch = trim($matches[1], " \t\n\r\0\x0B\"'");
        if ($clientSearch !== '') {
            $statement = $pdo->prepare(
                "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE i.status = 'in') AS stored,
                    COUNT(*) FILTER (WHERE i.status = 'out') AS out,
                    COUNT(*) FILTER (WHERE i.status = 'permanent_out') AS written_off
                 FROM inventory_items i
                 LEFT JOIN warehouse_locations l ON l.id = i.location_id
                 LEFT JOIN clients c ON c.id = i.client_id
                 WHERE i.deleted_at IS NULL
                   AND (
                       COALESCE(c.name, i.account_name) ILIKE :client_name
                       OR COALESCE(c.code, i.account_code) ILIKE :client_code
                   )
                   AND {$scope}",
            );
            $searchTerm = '%' . $clientSearch . '%';
            $statement->execute([
                'client_name' => $searchTerm,
                'client_code' => $searchTerm,
            ] + $scopeParams);
            $counts = $statement->fetch();
            return sprintf(
                'Cliente/cuenta «%s»: %s artículos en total; %s en almacén, %s en salida y %s bajas permanentes.',
                $clientSearch,
                number_format((int) $counts['total']),
                number_format((int) $counts['stored']),
                number_format((int) $counts['out']),
                number_format((int) $counts['written_off']),
            );
        }
    }

    if (preg_match('/\b(?:cu[aá]ntos?|cantidad|n[uú]mero)\s+clientes?\b/iu', $question) === 1) {
        $statement = $pdo->prepare(
            "SELECT COUNT(DISTINCT i.client_id)
             FROM inventory_items i
             LEFT JOIN warehouse_locations l ON l.id = i.location_id
             WHERE i.deleted_at IS NULL AND i.client_id IS NOT NULL AND {$scope}",
        );
        $statement->execute($scopeParams);
        return 'Clientes con artículos activos en tu alcance: ' . number_format((int) $statement->fetchColumn()) . '.';
    }

    if (preg_match('/\b(?:cu[aá]les?\s+(?:son\s+)?(?:los\s+)?edificios?|qu[eé]\s+(?:(?:son|hay)\s+)?(?:los\s+)?edificios?|lista(?:r)?\s+(?:de\s+)?(?:los\s+)?edificios?)\b/iu', $question) === 1) {
        $buildingScope = isAdmin()
            ? 'TRUE'
            : 'EXISTS (
                SELECT 1 FROM user_building_permissions ubp
                WHERE ubp.user_id = :user_id AND ubp.building_code = b.code
            )';
        $statement = $pdo->prepare('SELECT b.code FROM building b WHERE ' . $buildingScope . ' ORDER BY b.code');
        $statement->execute(isAdmin() ? [] : ['user_id' => $_SESSION['user_id']]);
        $buildingCodes = $statement->fetchAll(PDO::FETCH_COLUMN);
        if ($buildingCodes === []) {
            return 'No hay edificios configurados en tu alcance.';
        }
        return 'Edificios configurados en tu alcance: ' . implode(', ', $buildingCodes) . '.';
    }

    if (preg_match('/\b(?:cu[aá]ntos?|cantidad|n[uú]mero|total)\s+(?:de\s+)?edificios?\b/iu', $question) === 1) {
        $isAdministrator = isAdmin();
        $buildingScope = $isAdministrator
            ? 'TRUE'
            : 'EXISTS (
                SELECT 1 FROM user_building_permissions ubp
                WHERE ubp.user_id = :user_id AND ubp.building_code = b.code
            )';
        $statement = $pdo->prepare('SELECT COUNT(*) FROM building b WHERE ' . $buildingScope);
        $statement->execute($isAdministrator ? [] : ['user_id' => $_SESSION['user_id']]);
        $scopeLabel = $isAdministrator ? 'en el sistema' : 'en tu alcance';
        return 'Edificios configurados ' . $scopeLabel . ': ' . number_format((int) $statement->fetchColumn()) . '.';
    }

    $status = null;
    $statusLabel = '';
    if (preg_match('/\b(?:bajas?|permanentes?)\b/iu', $question) === 1) {
        $status = 'permanent_out';
        $statusLabel = 'bajas permanentes';
    } elseif (preg_match('/\b(?:salida|fuera)\b/iu', $question) === 1) {
        $status = 'out';
        $statusLabel = 'artículos en salida';
    } elseif (preg_match('/\b(?:almac[eé]n|disponibles?|stock)\b/iu', $question) === 1) {
        $status = 'in';
        $statusLabel = 'artículos en almacén';
    }

    if ($status !== null) {
        $statement = $pdo->prepare(
            "SELECT COUNT(*)
             FROM inventory_items i
             LEFT JOIN warehouse_locations l ON l.id = i.location_id
             WHERE i.deleted_at IS NULL AND i.status = :status AND {$scope}",
        );
        $statement->execute(['status' => $status] + $scopeParams);
        return ucfirst($statusLabel) . ' en tu alcance: ' . number_format((int) $statement->fetchColumn()) . '.';
    }

    if (preg_match('/\b(?:cu[aá]ntos?|total|inventario|art[ií]culos?)\b/iu', $question) === 1) {
        $statement = $pdo->prepare(
            "SELECT COUNT(*)
             FROM inventory_items i
             LEFT JOIN warehouse_locations l ON l.id = i.location_id
             WHERE i.deleted_at IS NULL AND {$scope}",
        );
        $statement->execute($scopeParams);
        return 'Artículos activos en tu alcance: ' . number_format((int) $statement->fetchColumn()) . '.';
    }

    return 'No reconocí esa pregunta. Prueba con: total de artículos, artículos en almacén, bajas permanentes, cantidad de clientes, artículos del cliente Acme, artículos del edificio ABC, ubicación con más artículos o top clientes.';
}

$assistantQuestion = '';
$assistantAnswer = null;
$statsStatement = $pdo->prepare(
    "SELECT COUNT(*) AS total,
        COUNT(*) FILTER (WHERE status = 'in') AS stored,
        COUNT(*) FILTER (WHERE status = 'out') AS out,
        COUNT(*) FILTER (WHERE status = 'permanent_out') AS written_off
     FROM inventory_items i LEFT JOIN warehouse_locations l ON l.id = i.location_id
     WHERE i.deleted_at IS NULL AND " . inventoryAccessSql(),
);
$statsStatement->execute(inventoryAccessParams());
$stats = $statsStatement->fetch();
$remesaAgingStatement = $pdo->prepare(
    "SELECT
        COUNT(*) FILTER (
            WHERE dispatch_m.created_at <= NOW()
              AND dispatch_m.created_at >= NOW() - INTERVAL '15 days'
        ) AS up_to_15_days,
        COUNT(*) FILTER (
            WHERE dispatch_m.created_at < NOW() - INTERVAL '15 days'
              AND dispatch_m.created_at >= NOW() - INTERVAL '30 days'
        ) AS days_16_to_30,
        COUNT(*) FILTER (WHERE dispatch_m.created_at < NOW() - INTERVAL '90 days') AS over_90_days
     FROM remesa_pending_items rp
     JOIN remesa r ON r.id = rp.remesa_id
     JOIN movements dispatch_m ON dispatch_m.id = r.movement_id
     JOIN inventory_items i ON i.id = rp.item_id
     LEFT JOIN warehouse_locations l ON l.id = i.location_id
     WHERE rp.resolved_at IS NULL
       AND i.deleted_at IS NULL
       AND " . inventoryAccessSql('i', 'l', 'remesa_kpi'),
);
$remesaAgingStatement->execute(inventoryAccessParams('remesa_kpi'));
$remesaAging = $remesaAgingStatement->fetch();
$movementScope = inventoryAccessSql('i', 'l', 'recent');
$movementPrivacy = isAdmin() ? '' : '
     AND NOT EXISTS (
         SELECT 1 FROM movement_lines hidden_ml
         JOIN inventory_items hidden_i ON hidden_i.id = hidden_ml.item_id
         LEFT JOIN warehouse_locations hidden_l ON hidden_l.id = hidden_i.location_id
         WHERE hidden_ml.movement_id = m.id
           AND NOT ' . inventoryAccessSql('hidden_i', 'hidden_l', 'recent_hidden') . '
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
$movementsStatement = $pdo->prepare(
    'SELECT m.id, m.folio, m.type, m.reference, m.created_at, u.username, scoped.line_count
     FROM movements m JOIN app_users u ON u.id = m.created_by
     CROSS JOIN LATERAL (
         SELECT COUNT(*) AS line_count
         FROM movement_lines ml
         JOIN inventory_items i ON i.id = ml.item_id AND i.deleted_at IS NULL
         LEFT JOIN warehouse_locations l ON l.id = i.location_id
         WHERE ml.movement_id = m.id AND ' . $movementScope . '
     ) scoped
     WHERE scoped.line_count > 0' . $movementPrivacy . '
     ORDER BY m.id DESC LIMIT 8',
);
$movementParams = inventoryAccessParams('recent');
if (!isAdmin()) {
    $movementParams += inventoryAccessParams('recent_hidden');
    $movementParams['building_user_id_movement_from'] = $_SESSION['user_id'];
    $movementParams['building_user_id_movement_to'] = $_SESSION['user_id'];
}
$movementsStatement->execute($movementParams);
$movements = $movementsStatement->fetchAll();
$locationScope = inventoryAccessSql('i', 'l', 'occupancy');
$locationsStatement = $pdo->prepare(
    "SELECT l.building_code, l.code, l.name, l.capacity,
        COUNT(i.id) FILTER (WHERE i.status = 'in') AS occupied
     FROM warehouse_locations l LEFT JOIN inventory_items i ON i.location_id = l.id AND i.deleted_at IS NULL
        AND {$locationScope}
     WHERE l.is_active AND " . buildingAccessSql('l') . "
     GROUP BY l.id ORDER BY occupied DESC, l.building_code, l.code LIMIT 6",
);
$locationParams = inventoryAccessParams('occupancy');
if (!isAdmin()) {
    $locationParams['building_user_id'] = $_SESSION['user_id'];
}
$locationsStatement->execute($locationParams);
$locations = $locationsStatement->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ask_inventory') {
    try {
        verifyCsrf();
        $assistantQuestion = trim((string) ($_POST['question'] ?? ''));
        if ($assistantQuestion === '' || strlen($assistantQuestion) > 500) {
            throw new InvalidArgumentException('Escribe una pregunta de hasta 500 caracteres.');
        }
        $assistantAnswer = inventoryQuestionAnswer($pdo, $assistantQuestion);
    } catch (InvalidArgumentException $exception) {
        flash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        flash('error', 'No se pudo consultar el inventario. Inténtalo otra vez.');
    }
}

pageHeader('Resumen', 'inicio');
?>
<section class="stats-grid summary-stats-grid">
    <article class="stat-card stat-card--blue"><span class="stat-label">Artículos registrados</span><strong><?= number_format((int) $stats['total']) ?></strong><span class="stat-note">IDs de inventario únicos</span></article>
    <article class="stat-card stat-card--green"><span class="stat-label">En almacén</span><strong><?= number_format((int) $stats['stored']) ?></strong><span class="stat-note"><span class="mini-dot green"></span>Existencia disponible en ubicación</span></article>
    <article class="stat-card stat-card--amber"><span class="stat-label">En salida</span><strong><?= number_format((int) $stats['out']) ?></strong><span class="stat-note"><span class="mini-dot amber"></span>Fuera temporalmente</span></article>
</section>
<section class="stats-grid remesa-stats-grid">
    <article class="stat-card stat-card--teal"><span class="stat-label">Pendientes de devolución · hasta 15 días</span><strong><?= number_format((int) $remesaAging['up_to_15_days']) ?></strong><span class="stat-note">Artículos no devueltos en remesas</span></article>
    <article class="stat-card stat-card--orange"><span class="stat-label">Pendientes de devolución · 16–30 días</span><strong><?= number_format((int) $remesaAging['days_16_to_30']) ?></strong><span class="stat-note">Artículos no devueltos en remesas</span></article>
    <article class="stat-card stat-card--red"><span class="stat-label">Pendientes de devolución · más de 90 días</span><strong><?= number_format((int) $remesaAging['over_90_days']) ?></strong><span class="stat-note">Artículos no devueltos en remesas</span></article>
</section>
<section class="panel">
    <div class="panel-heading">
        <div><h2>Consulta de inventario</h2><p>Asistente local: consulta datos con permisos de inventario; la información no sale de este servidor.</p></div>
    </div>
    <form method="post" class="filter-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="ask_inventory">
        <label class="search-field">Pregunta<input type="search" name="question" maxlength="500" required value="<?= h($assistantQuestion) ?>" placeholder="Ej.: ¿Cuántos artículos hay en el edificio ABC?"></label>
        <button class="button primary" type="submit">Consultar</button>
    </form>
    <?php if ($assistantAnswer !== null): ?>
        <div class="empty-state compact assistant-answer"><strong>Respuesta</strong><p><?= h($assistantAnswer) ?></p></div>
    <?php endif; ?>
</section>
<div class="content-grid">
    <section class="panel"><div class="panel-heading"><div><h2>Movimientos recientes</h2><p>Actividad registrada en el almacén</p></div><a class="table-link" href="movements.php">Ver todos →</a></div>
        <?php if ($movements === []): ?><div class="empty-state compact"><strong>Sin movimientos todavía</strong><p>Las recepciones, traslados, salidas y devoluciones aparecerán aquí.</p></div><?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Folio</th><th>Movimiento</th><th>Referencia</th><th>Artículos</th><th>Fecha</th></tr></thead><tbody><?php foreach ($movements as $movement): ?><tr><td class="mono"><?= h($movement['folio'] ?: ('MOV-' . $movement['id'])) ?></td><td><?= h(movementLabel($movement['type'])) ?></td><td><?= h($movement['reference'] ?: '—') ?></td><td><?= number_format((int) $movement['line_count']) ?></td><td><?= h(date('d/m/Y H:i', strtotime($movement['created_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>
    <section class="panel"><div class="panel-heading"><div><h2>Ocupación</h2><p>Artículos por ubicación activa</p></div><a class="table-link" href="locations.php">Ver ubicaciones →</a></div>
        <?php if ($locations === []): ?><div class="empty-state compact"><strong>Sin ubicaciones registradas</strong><p>Se crean al importar el archivo o recibir artículos.</p></div><?php else: ?><div class="location-list"><?php foreach ($locations as $location): $capacity = $location['capacity'] !== null ? (int) $location['capacity'] : null; $occupied = (int) $location['occupied']; $percent = $capacity ? min(100, (int) round($occupied * 100 / $capacity)) : 0; ?><div class="location-row"><div class="location-info"><strong><?= h($location['building_code'] . ' · ' . $location['code']) ?></strong><span><?= h($location['name']) ?></span></div><span class="location-count"><?= number_format($occupied) ?><?= $capacity !== null ? ' / ' . number_format($capacity) : '' ?></span></div><?php if ($capacity !== null): ?><div class="progress-track"><span style="width:<?= $percent ?>%"></span></div><?php endif; ?><?php endforeach; ?></div><?php endif; ?>
    </section>
</div>
<?php pageFooter(); ?>
