<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
requireAuth($pdo);

if (!isAdmin()) {
    http_response_code(403);
    exit('No tienes permiso para administrar usuarios.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetId = (int) ($_POST['user_id'] ?? 0);
    try {
        verifyCsrf();
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if ($username === '' || strlen($username) > 80 || strlen($password) < 12) {
                throw new InvalidArgumentException('Indica un usuario y una contraseña de al menos 12 caracteres.');
            }

            $statement = $pdo->prepare(
                'INSERT INTO app_users (username, password_hash) VALUES (:username, :hash) RETURNING id',
            );
            $statement->execute(['username' => $username, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
            $targetId = (int) $statement->fetchColumn();
            flash('success', 'Usuario creado. Asigna sus permisos para habilitar el acceso a la información.');
        } elseif ($action === 'save' && $targetId > 0) {
            $isTargetAdmin = isset($_POST['is_admin']);
            $buildingValues = $_POST['buildings'] ?? [];
            if (!is_array($buildingValues)) {
                throw new InvalidArgumentException('La selección de edificios no es válida.');
            }

            $pdo->beginTransaction();
            $pdo->exec('LOCK TABLE app_users IN EXCLUSIVE MODE');
            $userStatement = $pdo->prepare('SELECT id, is_admin FROM app_users WHERE id = :id FOR UPDATE');
            $userStatement->execute(['id' => $targetId]);
            $target = $userStatement->fetch();
            if (!$target) {
                throw new RuntimeException('No se encontró el usuario seleccionado.');
            }
            if (databaseBoolean($target['is_admin']) && !$isTargetAdmin) {
                $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM app_users WHERE is_admin')->fetchColumn();
                if ($adminCount <= 1) {
                    throw new RuntimeException('Debe permanecer al menos un administrador.');
                }
            }

            $updateUser = $pdo->prepare('UPDATE app_users SET is_admin = :is_admin WHERE id = :id');
            $updateUser->bindValue(':is_admin', $isTargetAdmin, PDO::PARAM_BOOL);
            $updateUser->bindValue(':id', $targetId, PDO::PARAM_INT);
            $updateUser->execute();
            $pdo->prepare('DELETE FROM user_building_permissions WHERE user_id = :id')->execute(['id' => $targetId]);

            $buildingExists = $pdo->prepare(
                'SELECT EXISTS (SELECT 1 FROM building WHERE code = :building_code)',
            );
            $insertBuilding = $pdo->prepare(
                'INSERT INTO user_building_permissions (user_id, building_code) VALUES (:user_id, :building_code)',
            );
            foreach ($buildingValues as $buildingCode) {
                if (!is_string($buildingCode) || $buildingCode === '') {
                    throw new InvalidArgumentException('La selección de edificios no es válida.');
                }
                $buildingExists->execute(['building_code' => $buildingCode]);
                if (!(bool) $buildingExists->fetchColumn()) {
                    throw new RuntimeException('Uno de los edificios seleccionados ya no está disponible.');
                }
                $insertBuilding->execute(['user_id' => $targetId, 'building_code' => $buildingCode]);
            }

            $pdo->commit();
            flash('success', 'Usuario y permisos actualizados.');
        } else {
            throw new InvalidArgumentException('La operación solicitada no es válida.');
        }
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
            ? 'El nombre de usuario ya existe o hay permisos duplicados.'
            : 'No se pudieron guardar los cambios. Inténtalo otra vez.');
    }

    header('Location: permissions.php' . ($targetId > 0 ? '?user_id=' . $targetId : ''));
    exit;
}

$users = $pdo->query('SELECT id, username, is_admin FROM app_users ORDER BY username')->fetchAll();
$buildings = $pdo->query('SELECT code AS building_code FROM building ORDER BY code')->fetchAll();
$selectedId = (int) ($_GET['user_id'] ?? ($users[0]['id'] ?? 0));
$selectedUser = null;
foreach ($users as $user) {
    if ((int) $user['id'] === $selectedId) {
        $selectedUser = $user;
        break;
    }
}
$selectedBuildings = [];
if ($selectedUser !== null) {
    $statement = $pdo->prepare('SELECT building_code FROM user_building_permissions WHERE user_id = :id');
    $statement->execute(['id' => $selectedId]);
    $selectedBuildings = array_fill_keys($statement->fetchAll(PDO::FETCH_COLUMN), true);
}

pageHeader('Usuarios y permisos', 'permisos');
?>
<div class="content-grid permission-layout">
    <section class="panel">
        <div class="panel-heading"><div><h2>Usuarios</h2><p>Selecciona una cuenta para administrar sus accesos.</p></div></div>
        <div class="user-permission-list">
            <?php foreach ($users as $user): ?>
                <a class="user-permission-link <?= (int) $user['id'] === $selectedId ? 'active' : '' ?>" href="permissions.php?user_id=<?= (int) $user['id'] ?>">
                    <strong><?= h($user['username']) ?></strong><span><?= databaseBoolean($user['is_admin']) ? 'Administrador' : 'Usuario' ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <hr class="section-divider">
        <h2>Crear usuario</h2>
        <p class="muted">La contraseña debe tener al menos 12 caracteres.</p>
        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="create">
            <label>Usuario<input name="username" maxlength="80" required autocomplete="off"></label>
            <label>Contraseña<input type="password" name="password" minlength="12" required autocomplete="new-password"></label>
            <button class="button primary" type="submit">Crear usuario</button>
        </form>
    </section>

    <section class="panel">
        <?php if ($selectedUser === null): ?>
            <div class="empty-state compact"><strong>Selecciona un usuario</strong><p>Crea un usuario para comenzar a asignar accesos.</p></div>
        <?php else: ?>
            <div class="panel-heading"><div><h2>Acceso de <?= h($selectedUser['username']) ?></h2><p>El acceso a los registros depende únicamente de los edificios autorizados.</p></div></div>
            <form method="post" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="user_id" value="<?= $selectedId ?>">
                <label class="checkbox-label"><input type="checkbox" name="is_admin" value="1" <?= databaseBoolean($selectedUser['is_admin']) ? 'checked' : '' ?>> Administrador · acceso completo y gestión de permisos</label>

                <fieldset class="permission-fieldset">
                    <legend>Edificios autorizados</legend>
                    <?php if ($buildings === []): ?><p class="muted">No hay edificios registrados.</p><?php else: ?>
                        <div class="permission-options">
                            <?php foreach ($buildings as $building): ?>
                                <label class="checkbox-label"><input type="checkbox" name="buildings[]" value="<?= h($building['building_code']) ?>" <?= isset($selectedBuildings[$building['building_code']]) ? 'checked' : '' ?>>
                                    <?= h($building['building_code']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </fieldset>
                <button class="button primary" type="submit">Guardar permisos</button>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php pageFooter(); ?>
