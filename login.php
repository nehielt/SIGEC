<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
if ((int) $pdo->query('SELECT COUNT(*) FROM app_users')->fetchColumn() === 0) {
    header('Location: setup.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $statement = $pdo->prepare('SELECT id, username, password_hash, is_admin FROM app_users WHERE username = :username');
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new InvalidArgumentException('Usuario o contraseña incorrectos.');
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['is_admin'] = databaseBoolean($user['is_admin']);
        header('Location: index.php');
        exit;
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    }
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Iniciar sesión · SIGEC</title><link rel="stylesheet" href="assets/app.css"></head>
<body class="auth-page"><main class="auth-card"><a class="brand auth-brand" href="login.php"><span class="brand-mark" aria-hidden="true"></span><span>SIGEC<span class="brand-sub">Sistema Integral de Gestión y Custodia</span></span></a><p class="eyebrow">ACCESO SEGURO</p><h1>Iniciar sesión</h1><p class="muted">Ingresa a la operación de almacén.</p><?php if ($error !== ''): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?><form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><label>Usuario<input name="username" required autocomplete="username"></label><label>Contraseña<input type="password" name="password" required autocomplete="current-password"></label><button class="button primary full" type="submit">Entrar</button></form></main></body></html>
