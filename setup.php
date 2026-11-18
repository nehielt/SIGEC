<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if ((int) $pdo->query('SELECT COUNT(*) FROM app_users')->fetchColumn() > 0) {
    header('Location: ' . (isset($_SESSION['user_id']) ? 'index.php' : 'login.php'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($username === '' || strlen($username) > 80 || strlen($password) < 12) {
            throw new InvalidArgumentException('Indica un usuario y una contraseña de al menos 12 caracteres.');
        }

        $pdo->beginTransaction();
        $pdo->exec('LOCK TABLE app_users IN EXCLUSIVE MODE');
        if ((int) $pdo->query('SELECT COUNT(*) FROM app_users')->fetchColumn() > 0) {
            throw new RuntimeException('Ya se creó el primer usuario. Inicia sesión.');
        }
        $statement = $pdo->prepare('INSERT INTO app_users (username, password_hash, is_admin) VALUES (:username, :hash, TRUE) RETURNING id');
        $statement->execute(['username' => $username, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
        $_SESSION['user_id'] = (int) $statement->fetchColumn();
        $_SESSION['username'] = $username;
        $_SESSION['is_admin'] = true;
        $pdo->commit();
        session_regenerate_id(true);
        header('Location: index.php');
        exit;
    } catch (InvalidArgumentException|RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        $error = 'No se pudo crear el usuario. Revisa que el nombre no esté en uso.';
    }
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Configurar acceso · SIGEC</title><link rel="stylesheet" href="assets/app.css"></head>
<body class="auth-page"><main class="auth-card"><a class="brand auth-brand" href="setup.php"><span class="brand-mark" aria-hidden="true"></span><span>SIGEC<span class="brand-sub">Sistema Integral de Gestión y Custodia</span></span></a><p class="eyebrow">PRIMER ACCESO</p><h1>Crear administrador</h1><p class="muted">Configura la cuenta inicial para proteger el acceso al inventario.</p><?php if ($error !== ''): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?><form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><label>Usuario<input name="username" maxlength="80" required autocomplete="username"></label><label>Contraseña<input type="password" name="password" minlength="12" required autocomplete="new-password"><small>Usa al menos 12 caracteres.</small></label><button class="button primary full" type="submit">Crear cuenta y entrar</button></form></main></body></html>
