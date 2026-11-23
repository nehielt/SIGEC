<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
requireAuth($pdo);

if (!isAdmin()) {
    http_response_code(403);
    exit('No tienes permiso para administrar clientes.');
}

$selectedId = (int) ($_GET['client_id'] ?? 0);
$selectedDepartmentId = (int) ($_GET['department_id'] ?? 0);
$selectedContactId = (int) ($_GET['contact_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $action = (string) ($_POST['action'] ?? '');
        $selectedId = (int) ($_POST['client_id'] ?? 0);
        $selectedDepartmentId = (int) ($_POST['department_id'] ?? 0);
        $selectedContactId = (int) ($_POST['contact_id'] ?? 0);

        if ($action === 'create' || $action === 'update') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $code = trim((string) ($_POST['code'] ?? ''));
            if ($action === 'update' && $selectedId > 0) {
                $codeStatement = $pdo->prepare('SELECT code FROM clients WHERE id = :id');
                $codeStatement->execute(['id' => $selectedId]);
                $storedCode = $codeStatement->fetchColumn();
                if ($storedCode === false) {
                    throw new RuntimeException('No se encontró el cliente seleccionado.');
                }
                $code = (string) $storedCode;
            }
            if ($name === '' || strlen($name) > 250 || strlen($code) > 120 || str_contains($code, '\\')) {
                throw new InvalidArgumentException('El nombre es obligatorio (máximo 250 caracteres) y el código admite hasta 120 caracteres sin barras invertidas.');
            }

            if ($action === 'create') {
                $statement = $pdo->prepare(
                    'INSERT INTO clients (code, name) VALUES (:code, :name) RETURNING id',
                );
                $statement->execute(['code' => $code, 'name' => $name]);
                $selectedId = (int) $statement->fetchColumn();
                flash('success', 'Cliente creado.');
            } elseif ($selectedId > 0) {
                $statement = $pdo->prepare(
                    'UPDATE clients SET code = :code, name = :name, updated_at = NOW() WHERE id = :id',
                );
                $statement->execute(['code' => $code, 'name' => $name, 'id' => $selectedId]);
                if ($statement->rowCount() !== 1) {
                    throw new RuntimeException('No se encontró el cliente seleccionado.');
                }
                flash('success', 'Cliente actualizado. Los artículos asociados mostrarán los datos nuevos.');
            } else {
                throw new InvalidArgumentException('Selecciona un cliente para modificarlo.');
            }
            $selectedDepartmentId = 0;
            $selectedContactId = 0;
        } elseif (in_array($action, ['create_department', 'update_department'], true)) {
            $clientExists = $pdo->prepare('SELECT EXISTS (SELECT 1 FROM clients WHERE id = :id)');
            $clientExists->execute(['id' => $selectedId]);
            if ($selectedId < 1 || !$clientExists->fetchColumn()) {
                throw new InvalidArgumentException('Selecciona un cliente válido para administrar sus departamentos.');
            }

            $departmentId = (int) ($_POST['department_id'] ?? 0);
            $clientCodeStatement = $pdo->prepare('SELECT code FROM clients WHERE id = :id');
            $clientCodeStatement->execute(['id' => $selectedId]);
            $clientCode = $clientCodeStatement->fetchColumn();
            $departmentCode = trim((string) ($_POST['department_code'] ?? ''));
            $departmentName = trim((string) ($_POST['department_name'] ?? ''));
            if ($departmentCode === '' || strlen($departmentCode) > 120 || $departmentName === '' || strlen($departmentName) > 250) {
                throw new InvalidArgumentException('El código del departamento es obligatorio (máximo 120 caracteres) y el nombre es obligatorio (máximo 250).');
            }

            if ($action === 'create_department') {
                $duplicate = $pdo->prepare(
                    'SELECT EXISTS (
                        SELECT 1 FROM departaments d
                        JOIN clients c ON c.id = d.client_id
                        WHERE c.code = :client_code AND d.department_code = :code
                    )',
                );
                $duplicate->execute(['client_code' => $clientCode, 'code' => $departmentCode]);
                if ($duplicate->fetchColumn()) {
                    throw new InvalidArgumentException('Ya existe un departamento con ese código para este cliente.');
                }
                $statement = $pdo->prepare(
                    'INSERT INTO departaments (client_id, department_code, name) VALUES (:client_id, :code, :name)',
                );
                $statement->execute([
                    'client_id' => $selectedId,
                    'code' => $departmentCode,
                    'name' => $departmentName,
                ]);
                $selectedDepartmentId = 0;
                flash('success', 'Departamento creado.');
            } elseif ($departmentId > 0) {
                $statement = $pdo->prepare(
                    'UPDATE departaments d SET department_code = :code, name = :name
                     WHERE d.id = :id
                       AND EXISTS (
                           SELECT 1 FROM clients c
                           WHERE c.id = d.client_id AND c.code = :client_code
                       )',
                );
                $statement->execute([
                    'code' => $departmentCode,
                    'name' => $departmentName,
                    'id' => $departmentId,
                    'client_code' => $clientCode,
                ]);
                if ($statement->rowCount() !== 1) {
                    $exists = $pdo->prepare(
                        'SELECT EXISTS (
                            SELECT 1 FROM departaments d
                            JOIN clients c ON c.id = d.client_id
                            WHERE d.id = :id AND c.code = :client_code
                        )',
                    );
                    $exists->execute(['id' => $departmentId, 'client_code' => $clientCode]);
                    if (!$exists->fetchColumn()) {
                        throw new RuntimeException('No se encontró el departamento seleccionado.');
                    }
                }
                $selectedDepartmentId = $departmentId;
                flash('success', 'Departamento actualizado.');
            } else {
                throw new InvalidArgumentException('Selecciona un departamento válido para modificarlo.');
            }
            $selectedContactId = 0;
        } elseif (in_array($action, ['create_contact', 'update_contact', 'delete_contact'], true)) {
            $clientExists = $pdo->prepare('SELECT EXISTS (SELECT 1 FROM clients WHERE id = :id)');
            $clientExists->execute(['id' => $selectedId]);
            if ($selectedId < 1 || !$clientExists->fetchColumn()) {
                throw new InvalidArgumentException('Selecciona una empresa válida para administrar sus contactos.');
            }

            if ($action === 'delete_contact') {
                $statement = $pdo->prepare(
                    'DELETE FROM client_contacts WHERE id = :id AND client_id = :client_id',
                );
                $statement->execute(['id' => $selectedContactId, 'client_id' => $selectedId]);
                if ($selectedContactId < 1 || $statement->rowCount() !== 1) {
                    throw new RuntimeException('No se encontró la persona de contacto seleccionada.');
                }
                $selectedContactId = 0;
                flash('success', 'Persona de contacto eliminada.');
            } else {
                $name = trim((string) ($_POST['contact_name'] ?? ''));
                $jobTitle = trim((string) ($_POST['job_title'] ?? ''));
                if ($name === '' || strlen($name) > 250 || $jobTitle === '' || strlen($jobTitle) > 150) {
                    throw new InvalidArgumentException('El nombre es obligatorio (máximo 250 caracteres) y el cargo es obligatorio (máximo 150).');
                }

                if ($action === 'create_contact') {
                    $statement = $pdo->prepare(
                        'INSERT INTO client_contacts (client_id, name, job_title)
                         VALUES (:client_id, :name, :job_title)
                         RETURNING id',
                    );
                    $statement->execute([
                        'client_id' => $selectedId,
                        'name' => $name,
                        'job_title' => $jobTitle,
                    ]);
                    $selectedContactId = (int) $statement->fetchColumn();
                    flash('success', 'Persona de contacto agregada.');
                } else {
                    $statement = $pdo->prepare(
                        'UPDATE client_contacts
                         SET name = :name, job_title = :job_title, updated_at = NOW()
                         WHERE id = :id AND client_id = :client_id
                         RETURNING id',
                    );
                    $statement->execute([
                        'name' => $name,
                        'job_title' => $jobTitle,
                        'id' => $selectedContactId,
                        'client_id' => $selectedId,
                    ]);
                    $updatedId = $statement->fetchColumn();
                    if ($updatedId === false) {
                        throw new RuntimeException('No se encontró la persona de contacto seleccionada.');
                    }
                    $selectedContactId = (int) $updatedId;
                    flash('success', 'Persona de contacto actualizada.');
                }
            }
        } else {
            throw new InvalidArgumentException('La operación solicitada no es válida.');
        }
    } catch (InvalidArgumentException|RuntimeException $exception) {
        flash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        flash('error', $exception->getCode() === '23505'
            ? 'Ya existe un cliente con ese código y nombre, o un departamento con ese código para el cliente.'
            : 'No se pudieron guardar los cambios. Inténtalo otra vez.');
    }

    $query = [];
    if ($selectedId > 0) {
        $query['client_id'] = $selectedId;
    }
    if ($selectedDepartmentId > 0) {
        $query['department_id'] = $selectedDepartmentId;
    }
    if ($selectedContactId > 0) {
        $query['contact_id'] = $selectedContactId;
    }
    header('Location: clients.php' . ($query !== [] ? '?' . http_build_query($query) : ''));
    exit;
}

$search = trim((string) ($_GET['q'] ?? ''));
$params = [];
$filter = '';
if ($search !== '') {
    $filter = 'WHERE code ILIKE :search_code OR name ILIKE :search_name';
    $term = '%' . $search . '%';
    $params = ['search_code' => $term, 'search_name' => $term];
}
$statement = $pdo->prepare(
    "SELECT DISTINCT ON (c.code, CASE WHEN c.code = '' THEN c.id END)
            c.id, c.code, c.name
     FROM clients c
     {$filter}
     ORDER BY c.code, CASE WHEN c.code = '' THEN c.id END, c.name, c.id",
);
$statement->execute($params);
$clients = $statement->fetchAll();

$selectedClient = null;
if ($selectedId > 0) {
    $selectedStatement = $pdo->prepare('SELECT id, code, name FROM clients WHERE id = :id');
    $selectedStatement->execute(['id' => $selectedId]);
    $selectedClient = $selectedStatement->fetch() ?: null;
}

$departments = [];
$selectedDepartment = null;
$contacts = [];
$selectedContact = null;
if ($selectedClient) {
    $contactsStatement = $pdo->prepare(
        'SELECT id, name, job_title
         FROM client_contacts
         WHERE client_id = :client_id
         ORDER BY name, id',
    );
    $contactsStatement->execute(['client_id' => $selectedId]);
    $contacts = $contactsStatement->fetchAll();

    if ($selectedContactId > 0) {
        $selectedContactStatement = $pdo->prepare(
            'SELECT id, name, job_title
             FROM client_contacts
             WHERE id = :id AND client_id = :client_id',
        );
        $selectedContactStatement->execute(['id' => $selectedContactId, 'client_id' => $selectedId]);
        $selectedContact = $selectedContactStatement->fetch() ?: null;
    }

    $departmentStatement = $pdo->prepare(
        'SELECT DISTINCT ON (d.department_code) d.id, d.department_code, d.name
         FROM departaments d
         JOIN clients c ON c.id = d.client_id
         WHERE c.code = :client_code
         ORDER BY d.department_code, d.id',
    );
    $departmentStatement->execute(['client_code' => $selectedClient['code']]);
    $departments = $departmentStatement->fetchAll();

    if ($selectedDepartmentId > 0) {
        $selectedDepartmentStatement = $pdo->prepare(
            'SELECT d.id, d.department_code, d.name
             FROM departaments d
             JOIN clients c ON c.id = d.client_id
             WHERE d.id = :id AND c.code = :client_code',
        );
        $selectedDepartmentStatement->execute([
            'id' => $selectedDepartmentId,
            'client_code' => $selectedClient['code'],
        ]);
        $selectedDepartment = $selectedDepartmentStatement->fetch() ?: null;
    }
}

pageHeader('Clientes', 'clientes');
?>
<div class="content-grid permission-layout">
    <section class="panel">
        <div class="panel-heading"><div><h2>Clientes registrados</h2><p><?= number_format(count($clients)) ?> clientes en el catálogo</p></div></div>
        <form class="filter-form compact-filter" method="get">
            <label class="search-field">Buscar cliente<input type="search" name="q" value="<?= h($search) ?>" placeholder="Código del cliente"></label>
            <button class="button secondary" type="submit">Buscar</button>
        </form>
        <?php if ($clients === []): ?>
            <div class="empty-state compact"><strong><?= $search === '' ? 'Sin clientes registrados' : 'No se encontraron clientes' ?></strong><p><?= $search === '' ? 'Crea un cliente para asociarlo a los artículos al registrar una recepción.' : 'Prueba con otro código.' ?></p></div>
        <?php else: ?>
            <div class="user-permission-list">
                <?php foreach ($clients as $client): ?>
                    <a class="user-permission-link <?= (int) $client['id'] === $selectedId ? 'active' : '' ?>" href="clients.php?<?= http_build_query(['q' => $search, 'client_id' => $client['id']]) ?>">
                        <strong><?= h($client['code'] !== '' ? $client['code'] : 'Sin código') ?></strong>
                        <span><?= h($client['name']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-heading"><div><h2><?= $selectedClient ? 'Modificar cliente' : 'Crear cliente' ?></h2><p>El nombre y código se reflejan en los artículos vinculados.</p></div></div>
        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="<?= $selectedClient ? 'update' : 'create' ?>">
            <?php if ($selectedClient): ?><input type="hidden" name="client_id" value="<?= (int) $selectedClient['id'] ?>"><?php endif; ?>
            <label>Nombre del cliente<input name="name" maxlength="250" required value="<?= h($selectedClient['name'] ?? '') ?>"></label>
            <label>Código de cuenta <span class="conditional-hint"><?= $selectedClient ? 'no editable' : 'opcional' ?></span><input name="code" maxlength="120" value="<?= h($selectedClient['code'] ?? '') ?>" <?= $selectedClient ? 'readonly' : '' ?>></label>
            <button class="button primary" type="submit"><?= $selectedClient ? 'Guardar cambios' : 'Crear cliente' ?></button>
            <?php if ($selectedClient): ?><a class="table-link" href="clients.php">Cancelar edición</a><?php endif; ?>
        </form>

        <?php if ($selectedClient): ?>
            <hr class="section-divider">
            <div class="panel-heading">
                <div><h2>Departamentos de <?= h($selectedClient['name']) ?></h2><p><?= number_format(count($departments)) ?> departamentos asociados al cliente.</p></div>
            </div>
            <?php if ($departments === []): ?>
                <div class="empty-state compact"><strong>Sin departamentos</strong><p>Agrega un departamento para este cliente.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Código</th><th>Nombre</th><th>Acción</th></tr></thead>
                        <tbody>
                        <?php foreach ($departments as $department): ?>
                            <tr>
                                <td class="mono"><?= h($department['department_code']) ?></td>
                                <td><?= h($department['name']) ?></td>
                                <td class="department-actions">
                                    <a class="table-link" href="clients.php?<?= http_build_query(['client_id' => $selectedId, 'department_id' => $department['id']]) ?>">Editar</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <hr class="section-divider">
            <div class="panel-heading"><div><h2><?= $selectedDepartment ? 'Modificar departamento' : 'Agregar departamento' ?></h2><p>Los departamentos se identifican por su código dentro de este cliente.</p></div></div>
            <form method="post" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="<?= $selectedDepartment ? 'update_department' : 'create_department' ?>">
                <input type="hidden" name="client_id" value="<?= (int) $selectedId ?>">
                <?php if ($selectedDepartment): ?><input type="hidden" name="department_id" value="<?= (int) $selectedDepartment['id'] ?>"><?php endif; ?>
                <label>Código del departamento<input name="department_code" maxlength="120" required value="<?= h($selectedDepartment['department_code'] ?? '') ?>"></label>
                <label>Nombre del departamento<input name="department_name" maxlength="250" required value="<?= h($selectedDepartment['name'] ?? '') ?>"></label>
                <button class="button primary" type="submit"><?= $selectedDepartment ? 'Guardar departamento' : 'Agregar departamento' ?></button>
                <?php if ($selectedDepartment): ?><a class="table-link" href="clients.php?client_id=<?= (int) $selectedId ?>">Cancelar edición</a><?php endif; ?>
            </form>

            <hr class="section-divider">
            <div class="panel-heading"><div><h2>Personas de contacto de <?= h($selectedClient['name']) ?></h2><p><?= number_format(count($contacts)) ?> personas registradas; puedes agregar varias por empresa.</p></div></div>
            <?php if ($contacts === []): ?>
                <div class="empty-state compact"><strong>Sin personas de contacto</strong><p>Agrega el nombre y cargo de una persona para esta empresa.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Nombre</th><th>Cargo</th><th>Acciones</th></tr></thead>
                        <tbody>
                        <?php foreach ($contacts as $contact): ?>
                            <tr>
                                <td><?= h($contact['name']) ?></td>
                                <td><?= h($contact['job_title']) ?></td>
                                <td class="department-actions">
                                    <a class="table-link" href="clients.php?<?= http_build_query(['client_id' => $selectedId, 'contact_id' => $contact['id']]) ?>">Editar</a>
                                    <form method="post" class="inline-form" onsubmit="return confirm('¿Eliminar esta persona de contacto?')">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_contact">
                                        <input type="hidden" name="client_id" value="<?= (int) $selectedId ?>">
                                        <input type="hidden" name="contact_id" value="<?= (int) $contact['id'] ?>">
                                        <button class="button small secondary" type="submit">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <hr class="section-divider">
            <div class="panel-heading"><div><h2><?= $selectedContact ? 'Modificar persona de contacto' : 'Agregar persona de contacto' ?></h2><p>Registra varias personas con su cargo para esta empresa.</p></div></div>
            <form method="post" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="<?= $selectedContact ? 'update_contact' : 'create_contact' ?>">
                <input type="hidden" name="client_id" value="<?= (int) $selectedId ?>">
                <?php if ($selectedContact): ?><input type="hidden" name="contact_id" value="<?= (int) $selectedContact['id'] ?>"><?php endif; ?>
                <label>Nombre<input name="contact_name" maxlength="250" required value="<?= h($selectedContact['name'] ?? '') ?>"></label>
                <label>Cargo<input name="job_title" maxlength="150" required value="<?= h($selectedContact['job_title'] ?? '') ?>"></label>
                <button class="button primary" type="submit"><?= $selectedContact ? 'Guardar persona de contacto' : 'Agregar persona de contacto' ?></button>
                <?php if ($selectedContact): ?><a class="table-link" href="clients.php?client_id=<?= (int) $selectedId ?>">Cancelar edición</a><?php endif; ?>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php pageFooter(); ?>
