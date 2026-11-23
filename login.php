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
$pendingTwoFactor = isset($_SESSION['pending_2fa_user_id']);
$verificationExpired = false;
if ($pendingTwoFactor && (int) ($_SESSION['pending_2fa_expires'] ?? 0) <= time()) {
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_expires'], $_SESSION['pending_2fa_attempts']);
    $pendingTwoFactor = false;
    $verificationExpired = true;
    $error = 'La verificación venció. Inicia sesión nuevamente.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$verificationExpired) {
    try {
        verifyCsrf();
        if (($_POST['action'] ?? '') === 'cancel_2fa') {
            unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_expires'], $_SESSION['pending_2fa_attempts']);
            header('Location: login.php');
            exit;
        }

        if (isset($_SESSION['pending_2fa_user_id'])) {
            $attempts = (int) ($_SESSION['pending_2fa_attempts'] ?? 0);
            if ($attempts >= 5) {
                unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_expires'], $_SESSION['pending_2fa_attempts']);
                throw new InvalidArgumentException('Se agotaron los intentos. Inicia sesión nuevamente.');
            }

            $_SESSION['pending_2fa_attempts'] = $attempts + 1;
            $statement = $pdo->prepare(
                'SELECT id, username, is_admin, totp_secret, totp_last_step
                 FROM app_users WHERE id = :id AND totp_enabled',
            );
            $statement->execute(['id' => $_SESSION['pending_2fa_user_id']]);
            $user = $statement->fetch();
            $code = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';
            $step = $user ? verifyTotp((string) $user['totp_secret'], $code) : null;
            if (!$user || $step === null || ($user['totp_last_step'] !== null && $step <= (int) $user['totp_last_step'])) {
                throw new InvalidArgumentException('El código es incorrecto o ya fue utilizado.');
            }

            $consumeCode = $pdo->prepare(
                'UPDATE app_users SET totp_last_step = :new_step
                 WHERE id = :id AND (totp_last_step IS NULL OR totp_last_step < :last_step)',
            );
            $consumeCode->execute(['new_step' => $step, 'id' => $user['id'], 'last_step' => $step]);
            if ($consumeCode->rowCount() !== 1) {
                throw new InvalidArgumentException('El código ya fue utilizado. Espera al siguiente e inténtalo otra vez.');
            }

            unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_expires'], $_SESSION['pending_2fa_attempts']);
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['is_admin'] = databaseBoolean($user['is_admin']);
            header('Location: index.php');
            exit;
        }

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $statement = $pdo->prepare(
            'SELECT id, username, password_hash, is_admin, totp_enabled
             FROM app_users WHERE username = :username',
        );
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new InvalidArgumentException('Usuario o contraseña incorrectos.');
        }

        if (databaseBoolean($user['totp_enabled'])) {
            session_regenerate_id(true);
            $_SESSION['pending_2fa_user_id'] = (int) $user['id'];
            $_SESSION['pending_2fa_expires'] = time() + 300;
            $_SESSION['pending_2fa_attempts'] = 0;
            $pendingTwoFactor = true;
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['is_admin'] = databaseBoolean($user['is_admin']);
            header('Location: index.php');
            exit;
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
        $pendingTwoFactor = isset($_SESSION['pending_2fa_user_id']);
    }
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Iniciar sesión · SIG</title><link rel="stylesheet" href="assets/app.css"></head>
<body class="auth-page"><main class="auth-card"><a class="brand auth-brand login-brand" href="login.php"><span class="brand-copy"><img class="brand-logo" src="logo_setecsa.png" alt="Logo de SETECSA"><span class="brand-text"><span class="brand-name">SIG</span><span class="brand-sub">Sistema Integrado de Gestión</span></span></span></a><p class="eyebrow">ACCESO SEGURO</p><h1>Iniciar sesión</h1><p class="muted"><?= $pendingTwoFactor ? 'Ingresa el código de Google Authenticator.' : 'Ingresa a la operación de almacén.' ?></p><?php if ($error !== ''): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?><form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><?php if ($pendingTwoFactor): ?><label>Código de verificación<input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code" autofocus></label><button class="button primary full" type="submit">Verificar</button><?php else: ?><label>Usuario<input name="username" required autocomplete="username"></label><label>Contraseña<input type="password" name="password" required autocomplete="current-password"></label><button class="button primary full" type="submit">Entrar</button><?php endif; ?></form><?php if ($pendingTwoFactor): ?><form method="post" class="auth-back"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="cancel_2fa"><button type="submit">Volver a iniciar sesión</button></form><?php endif; ?></main></body></html>
