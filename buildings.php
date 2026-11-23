<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
requireAuth($pdo);

if (!isAdmin()) {
    http_response_code(403);
    exit('No tienes permiso para administrar edificios.');
}

$selectedCode = trim((string) ($_GET['building_code'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = '';
    try {
        verifyCsrf();
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create_location') {
            $buildingCode = strtoupper(trim((string) ($_POST['building_code'] ?? '')));
            $selectedCode = $buildingCode;
            $locationCode = strtoupper(trim((string) ($_POST['location_code'] ?? '')));
            $locationName = trim((string) ($_POST['location_name'] ?? ''));
            $capacityInput = trim((string) ($_POST['capacity'] ?? ''));
            $capacity = $capacityInput === '' ? null : filter_var($capacityInput, FILTER_VALIDATE_INT);
            if (
                $buildingCode === '' || strlen($buildingCode) > 40
                || $locationCode === '' || strlen($locationCode) > 100
                || strlen($locationName) > 200
            ) {
                throw new InvalidArgumentException('El código de ubicación es obligatorio (máximo 100 caracteres) y la descripción admite hasta 200.');
            }
            if ($capacityInput !== '' && ($capacity === false || $capacity < 1)) {
                throw new InvalidArgumentException('La capacidad debe ser un entero positivo o quedar vacía (sin límite configurado).');
            }
            $buildingStatement = $pdo->prepare('SELECT EXISTS (SELECT 1 FROM building WHERE code = :code)');
            $buildingStatement->execute(['code' => $buildingCode]);
            if (!$buildingStatement->fetchColumn()) {
                throw new RuntimeException('No se encontró el edificio seleccionado.');
            }
            $statement = $pdo->prepare(
                'INSERT INTO warehouse_locations (building_code, code, name, capacity)
                 VALUES (:building_code, :code, :name, :capacity)',
            );
            $statement->bindValue(':building_code', $buildingCode);
            $statement->bindValue(':code', $locationCode);
            $statement->bindValue(':name', $locationName);
            $statement->bindValue(':capacity', $capacity, $capacity === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $statement->execute();
            $selectedCode = $buildingCode;
            flash('success', 'Ubicación creada.');
        } else {
            $originalCode = trim((string) ($_POST['original_code'] ?? ''));
            $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
            $description = trim((string) ($_POST['description'] ?? ''));
            if ($code === '' || strlen($code) > 40 || strlen($description) > 1000) {
                throw new InvalidArgumentException('El código es obligatorio (máximo 40 caracteres) y la descripción admite hasta 1000.');
            }

            if ($action === 'create') {
                $statement = $pdo->prepare('INSERT INTO building (code, description) VALUES (:code, :description)');
                $statement->execute(['code' => $code, 'description' => $description]);
                $selectedCode = $code;
                flash('success', 'Edificio creado.');
            } elseif ($action === 'update' && $originalCode !== '') {
                $statement = $pdo->prepare(
                    'UPDATE building SET code = :code, description = :description WHERE code = :original_code',
                );
                $statement->execute([
                    'code' => $code,
                    'description' => $description,
                    'original_code' => $originalCode,
                ]);
                if ($statement->rowCount() !== 1) {
                    throw new RuntimeException('No se encontró el edificio seleccionado.');
                }
                $selectedCode = $code;
                flash('success', 'Edificio actualizado.');
            } else {
                throw new InvalidArgumentException('La operación solicitada no es válida.');
            }
        }
    } catch (InvalidArgumentException|RuntimeException $exception) {
        flash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        flash('error', $exception->getCode() === '23505'
            ? ($action === 'create_location'
                ? 'Ya existe una ubicación con ese código en el edificio seleccionado.'
                : 'Ya existe un edificio con ese código.')
            : 'No se pudieron guardar los cambios. Inténtalo otra vez.');
    }

    header('Location: buildings.php' . ($selectedCode !== '' ? '?building_code=' . rawurlencode($selectedCode) : ''));
    exit;
}

$buildings = $pdo->query(
    'SELECT b.code, b.description, COUNT(l.id) AS location_count
     FROM building b LEFT JOIN warehouse_locations l ON l.building_code = b.code
     GROUP BY b.code ORDER BY b.code',
)->fetchAll();

$selectedBuilding = null;
if ($selectedCode !== '') {
    $statement = $pdo->prepare('SELECT code, description FROM building WHERE code = :code');
    $statement->execute(['code' => $selectedCode]);
    $selectedBuilding = $statement->fetch() ?: null;
}

pageHeader('Edificios', 'edificios');
?>
<div class="content-grid permission-layout">
    <section class="panel">
        <div class="panel-heading"><div><h2>Edificios registrados</h2><p><?= number_format(count($buildings)) ?> edificios en el catálogo</p></div><a class="button secondary" href="buildings.php">Nuevo edificio</a></div>
        <?php if ($buildings === []): ?>
            <div class="empty-state compact"><strong>Sin edificios registrados</strong><p>Crea un edificio para asignarlo a las ubicaciones.</p></div>
        <?php else: ?>
            <div class="user-permission-list">
                <?php foreach ($buildings as $building): ?>
                    <a class="user-permission-link <?= $building['code'] === $selectedCode ? 'active' : '' ?>" href="buildings.php?<?= http_build_query(['building_code' => $building['code']]) ?>">
                        <strong><?= h($building['code']) ?><?= $building['description'] !== '' ? ' · ' . h($building['description']) : '' ?></strong>
                        <span><?= number_format((int) $building['location_count']) ?> ubicaciones</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-heading"><div><h2><?= $selectedBuilding ? 'Modificar edificio' : 'Crear edificio' ?></h2><p>El código identifica el edificio y se usa en sus ubicaciones y permisos.</p></div></div>
        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="<?= $selectedBuilding ? 'update' : 'create' ?>">
            <?php if ($selectedBuilding): ?><input type="hidden" name="original_code" value="<?= h($selectedBuilding['code']) ?>"><?php endif; ?>
            <label>Código del edificio<input name="code" maxlength="40" required value="<?= h($selectedBuilding['code'] ?? '') ?>"></label>
            <label>Descripción<textarea name="description" rows="4" maxlength="1000"><?= h($selectedBuilding['description'] ?? '') ?></textarea></label>
            <button class="button primary" type="submit"><?= $selectedBuilding ? 'Guardar cambios' : 'Crear edificio' ?></button>
            <?php if ($selectedBuilding): ?><a class="table-link" href="buildings.php">Cancelar edición</a><?php endif; ?>
        </form>
        <?php if ($selectedBuilding): ?>
            <hr class="section-divider">
            <div class="panel-heading"><div><h2>Agregar ubicación</h2><p>Nueva ubicación para el edificio <?= h($selectedBuilding['code']) ?>.</p></div></div>
            <form method="post" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="create_location">
                <input type="hidden" name="building_code" value="<?= h($selectedBuilding['code']) ?>">
                <label>Código de ubicación<input name="location_code" maxlength="100" required></label>
                <label>Descripción<input name="location_name" maxlength="200"></label>
                <label>Capacidad <span class="conditional-hint">opcional</span><input type="number" name="capacity" min="1" step="1" placeholder="Sin límite configurado"></label>
                <button class="button primary" type="submit">Agregar ubicación</button>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php pageFooter(); ?>
