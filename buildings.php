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
    try {
        verifyCsrf();
        $action = (string) ($_POST['action'] ?? '');
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
    } catch (InvalidArgumentException|RuntimeException $exception) {
        flash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        flash('error', $exception->getCode() === '23505'
            ? 'Ya existe un edificio con ese código.'
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
        <div class="panel-heading"><div><h2>Edificios registrados</h2><p><?= number_format(count($buildings)) ?> edificios en el catálogo</p></div></div>
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
    </section>
</div>
<?php pageFooter(); ?>
