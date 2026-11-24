<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
requireAuth($pdo);

if (!isAdmin()) {
    http_response_code(403);
    exit('No tienes permiso para administrar prefijos y series.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create_prefix') {
            $clientId = filter_var($_POST['client_id'] ?? null, FILTER_VALIDATE_INT);
            $prefix = strtoupper(trim((string) ($_POST['prefix'] ?? '')));
            if ($clientId === false || $clientId < 1 || preg_match('/^[A-Z0-9]{1,3}$/', $prefix) !== 1) {
                throw new InvalidArgumentException('Selecciona un cliente e ingresa un prefijo de 1 a 3 letras mayúsculas o números.');
            }
            $clientExists = $pdo->prepare('SELECT EXISTS (SELECT 1 FROM clients WHERE id = :id)');
            $clientExists->execute(['id' => $clientId]);
            if (!$clientExists->fetchColumn()) {
                throw new InvalidArgumentException('El cliente seleccionado no está disponible.');
            }
            $insert = $pdo->prepare('INSERT INTO client_item_prefixes (client_id, prefix) VALUES (:client_id, :prefix)');
            $insert->execute(['client_id' => $clientId, 'prefix' => $prefix]);
            flash('success', 'Prefijo registrado.');
        } elseif ($action === 'create_series') {
            $clientId = filter_var($_POST['client_id'] ?? null, FILTER_VALIDATE_INT);
            $prefixId = filter_var($_POST['prefix_id'] ?? null, FILTER_VALIDATE_INT);
            $departmentIdInput = trim((string) ($_POST['department_id'] ?? ''));
            $departmentId = $departmentIdInput === ''
                ? null
                : filter_var($departmentIdInput, FILTER_VALIDATE_INT);
            $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($clientId === false || $clientId < 1 || $prefixId === false || $prefixId < 1 || ($departmentId !== null && ($departmentId === false || $departmentId < 1))) {
                throw new InvalidArgumentException('Selecciona un cliente, un prefijo y, si aplica, un departamento válido.');
            }
            if ($quantity === false || $quantity < 1 || $quantity > 500) {
                throw new InvalidArgumentException('La serie debe incluir entre 1 y 500 artículos.');
            }

            $pdo->beginTransaction();
            $prefixStatement = $pdo->prepare(
                'SELECT id, client_id, prefix FROM client_item_prefixes WHERE id = :id FOR UPDATE',
            );
            $prefixStatement->execute(['id' => $prefixId]);
            $prefix = $prefixStatement->fetch();
            if (!$prefix) {
                throw new InvalidArgumentException('El prefijo seleccionado ya no está disponible.');
            }
            if ((int) $prefix['client_id'] !== $clientId) {
                throw new InvalidArgumentException('El prefijo seleccionado no pertenece al cliente.');
            }
            if ($departmentId !== null) {
                $departmentStatement = $pdo->prepare(
                    'SELECT id FROM departaments WHERE id = :id AND client_id = :client_id',
                );
                $departmentStatement->execute(['id' => $departmentId, 'client_id' => $prefix['client_id']]);
                if ($departmentStatement->fetchColumn() === false) {
                    throw new InvalidArgumentException('El departamento debe pertenecer al cliente del prefijo.');
                }
            }

            $prefixLength = strlen($prefix['prefix']);
            $suffixLength = 7 - $prefixLength;
            $lastBoxStatement = $pdo->prepare(
                     "SELECT COALESCE(MAX(SUBSTRING(item_code, CAST(:suffix_start AS INTEGER))::INTEGER), 0)
                 FROM inventory_items
                 WHERE LENGTH(item_code) = 7
                   AND LEFT(item_code, :prefix_length) = :prefix
                         AND SUBSTRING(item_code, CAST(:suffix_filter_start AS INTEGER)) ~ '^[0-9]+$'",
            );
            $lastBoxStatement->execute([
                'suffix_start' => $prefixLength + 1,
                'prefix_length' => $prefixLength,
                'prefix' => $prefix['prefix'],
                'suffix_filter_start' => $prefixLength + 1,
            ]);
            $lastBox = (int) $lastBoxStatement->fetchColumn();
            if ($lastBox + $quantity > (10 ** $suffixLength) - 1) {
                throw new InvalidArgumentException('La serie supera el máximo disponible para este prefijo.');
            }

            $insertItem = $pdo->prepare(
                "INSERT INTO inventory_items (
                    external_id, item_code, account_code, account_name, client_id, department_id,
                    status, source_sheet
                 ) VALUES (
                    :external_id, :item_code, :account_code, :account_name, :client_id, :department_id,
                    'unprocessed', 'SERIE'
                 )",
            );
            $clientStatement = $pdo->prepare('SELECT code, name FROM clients WHERE id = :id');
            $clientStatement->execute(['id' => $prefix['client_id']]);
            $client = $clientStatement->fetch();
            $firstCode = '';
            $lastCode = '';
            for ($box = $lastBox + 1; $box <= $lastBox + $quantity; $box++) {
                $itemCode = $prefix['prefix'] . str_pad((string) $box, $suffixLength, '0', STR_PAD_LEFT);
                $insertItem->execute([
                    'external_id' => 'SERIE-' . strtoupper(bin2hex(random_bytes(16))),
                    'item_code' => $itemCode,
                    'account_code' => $client['code'],
                    'account_name' => $client['name'],
                    'client_id' => $prefix['client_id'],
                    'department_id' => $departmentId,
                ]);
                $firstCode = $firstCode === '' ? $itemCode : $firstCode;
                $lastCode = $itemCode;
            }
            $pdo->commit();
            flash('success', 'Serie creada: ' . $firstCode . ' a ' . $lastCode . ' (' . $quantity . ' artículos sin procesar).');
        } else {
            throw new InvalidArgumentException('La operación solicitada no es válida.');
        }
    } catch (InvalidArgumentException $exception) {
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
            ? 'El prefijo ya está registrado o uno de los códigos ya existe; no se guardó ningún artículo.'
            : 'No se pudo guardar la operación. No se guardó ningún cambio; inténtalo otra vez.');
    }
    header('Location: codes.php');
    exit;
}

$clients = $pdo->query('SELECT id, code, name FROM clients ORDER BY name, code')->fetchAll();
$departments = $pdo->query(
    'SELECT id, client_id, department_code, name FROM departaments ORDER BY name, department_code',
)->fetchAll();
$prefixes = $pdo->query(
    "SELECT p.id, p.prefix, c.id AS client_id, c.name AS client_name, c.code AS client_code,
            COUNT(i.id) FILTER (WHERE i.deleted_at IS NULL) AS item_count,
            MAX(i.item_code) FILTER (WHERE i.deleted_at IS NULL AND i.item_code ~ ('^' || p.prefix || '[0-9]{' || (7 - LENGTH(p.prefix)) || '}$')) AS last_code
     FROM client_item_prefixes p
     JOIN clients c ON c.id = p.client_id
     LEFT JOIN inventory_items i ON i.client_id = c.id AND LEFT(i.item_code, LENGTH(p.prefix)) = p.prefix
     GROUP BY p.id, c.id
     ORDER BY c.name, p.prefix",
)->fetchAll();

pageHeader('Prefijos y series', 'codigos');
?>
<div class="content-grid permission-layout">
    <section class="panel">
        <div class="panel-heading"><div><h2>Registrar prefijo</h2><p>Cada cliente puede tener varios prefijos numéricos.</p></div></div>
        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="create_prefix">
            <label>Cliente<select name="client_id" required><option value="">Selecciona un cliente</option><?php foreach ($clients as $client): ?><option value="<?= (int) $client['id'] ?>"><?= h($client['name'] . ($client['code'] !== '' ? ' · ' . $client['code'] : '')) ?></option><?php endforeach; ?></select></label>
            <label>Prefijo<input name="prefix" id="prefix-input" pattern="[A-Z0-9]{1,3}" minlength="1" maxlength="3" required placeholder="1 a 3 letras mayúsculas o números"></label>
            <button class="button primary" type="submit">Guardar prefijo</button>
        </form>
    </section>
    <section class="panel">
        <div class="panel-heading"><div><h2>Crear serie</h2><p>Los códigos se crean sin ubicación y quedan no procesados.</p></div></div>
        <form method="post" class="form-stack" id="series-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="create_series">
            <label>Cliente<select name="client_id" id="series-client-select" required><option value="">Selecciona un cliente</option><?php foreach ($clients as $client): ?><option value="<?= (int) $client['id'] ?>"><?= h($client['name'] . ($client['code'] !== '' ? ' · ' . $client['code'] : '')) ?></option><?php endforeach; ?></select></label>
            <label>Prefijo del cliente<select name="prefix_id" id="prefix-select" required><option value="">Selecciona un prefijo</option><?php foreach ($prefixes as $prefix): ?><option value="<?= (int) $prefix['id'] ?>" data-client-id="<?= (int) $prefix['client_id'] ?>" data-prefix="<?= h($prefix['prefix']) ?>"><?= h($prefix['client_name'] . ' · ' . $prefix['prefix']) ?></option><?php endforeach; ?></select></label>
            <label>Departamento <span class="conditional-hint">opcional</span><select name="department_id" id="department-select"><option value="">Sin departamento</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>" data-client-id="<?= (int) $department['client_id'] ?>"><?= h($department['name'] . ' · ' . $department['department_code']) ?></option><?php endforeach; ?></select></label>
            <label>Cantidad de cajas<input type="number" name="quantity" min="1" max="500" required value="1"></label>
            <p class="code-series-preview" id="series-preview" aria-live="polite">El prefijo determina los dígitos disponibles para la serie.</p>
            <button class="button primary" type="submit">Crear artículos</button>
        </form>
    </section>
</div>
<section class="panel">
    <div class="panel-heading"><div><h2>Prefijos registrados</h2><p id="registered-prefix-count">Selecciona un cliente para ver sus prefijos</p></div></div>
    <?php if ($prefixes === []): ?>
        <div class="empty-state compact"><strong>Sin prefijos registrados</strong><p>Registra un prefijo para habilitar la creación de series.</p></div>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Cliente</th><th>Prefijo</th><th>Serie</th><th>Último código</th><th>Artículos activos</th></tr></thead><tbody id="registered-prefix-rows">
            <?php foreach ($prefixes as $prefix): ?><tr data-client-id="<?= (int) $prefix['client_id'] ?>" hidden><td><?= h($prefix['client_name'] . ($prefix['client_code'] !== '' ? ' · ' . $prefix['client_code'] : '')) ?></td><td class="mono"><?= h($prefix['prefix']) ?></td><td><?= 7 - strlen($prefix['prefix']) ?> dígitos</td><td class="mono"><?= h($prefix['last_code'] ?? '—') ?></td><td><?= number_format((int) $prefix['item_count']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<script>
    const prefixInput = document.getElementById('prefix-input');
    const seriesClientSelect = document.getElementById('series-client-select');
    const prefixSelect = document.getElementById('prefix-select');
    const departmentSelect = document.getElementById('department-select');
    const prefixOptions = Array.from(prefixSelect.options).slice(1);
    const departmentOptions = Array.from(departmentSelect.options).slice(1);
    const seriesPreview = document.getElementById('series-preview');
    const registeredPrefixRows = Array.from(document.querySelectorAll('#registered-prefix-rows tr'));
    const registeredPrefixCount = document.getElementById('registered-prefix-count');

    prefixInput.addEventListener('input', () => {
        prefixInput.value = prefixInput.value.toUpperCase();
    });

    function updateSeriesClientFields() {
        const clientId = seriesClientSelect.value;
        const matchingPrefixes = prefixOptions.filter(option => option.dataset.clientId === clientId);
        prefixSelect.replaceChildren(prefixSelect.options[0], ...matchingPrefixes);
        let visiblePrefixCount = 0;
        registeredPrefixRows.forEach(row => {
            const visible = clientId !== '' && row.dataset.clientId === clientId;
            row.hidden = !visible;
            if (visible) {
                visiblePrefixCount++;
            }
        });
        registeredPrefixCount.textContent = clientId === ''
            ? 'Selecciona un cliente para ver sus prefijos'
            : `${visiblePrefixCount} prefijo${visiblePrefixCount === 1 ? '' : 's'} configurado${visiblePrefixCount === 1 ? '' : 's'} para el cliente`;
        updateSeriesFields();
    }

    function updateSeriesFields() {
        const selectedPrefix = prefixSelect.selectedOptions[0];
        const clientId = selectedPrefix?.dataset.clientId ?? '';
        const matchingDepartments = departmentOptions.filter(option => option.dataset.clientId === clientId);
        departmentSelect.replaceChildren(departmentSelect.options[0], ...matchingDepartments);
        if (selectedPrefix?.dataset.prefix) {
            const prefix = selectedPrefix.dataset.prefix;
            seriesPreview.textContent = `Código de 7 caracteres: ${prefix} + ${7 - prefix.length} dígitos de serie.`;
        } else {
            seriesPreview.textContent = 'El prefijo determina los dígitos disponibles para la serie.';
        }
    }

    seriesClientSelect.addEventListener('change', updateSeriesClientFields);
    prefixSelect.addEventListener('change', updateSeriesFields);
    updateSeriesClientFields();
</script>
<?php pageFooter(); ?>