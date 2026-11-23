<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
requireAuth($pdo);
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $action = (string) ($_POST['action'] ?? '');
        $userId = (int) $_SESSION['user_id'];

        if ($action === 'start') {
            $_SESSION['totp_setup_secret'] = generateTotpSecret();
            flash('success', 'Agrega la cuenta en Google Authenticator y confirma el código para activarla.');
        } elseif ($action === 'cancel') {
            unset($_SESSION['totp_setup_secret']);
            flash('success', 'La configuración pendiente fue cancelada.');
        } elseif ($action === 'enable') {
            $secret = (string) ($_SESSION['totp_setup_secret'] ?? '');
            $code = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';
            $password = (string) ($_POST['password'] ?? '');
            $statement = $pdo->prepare(
                'SELECT password_hash, totp_enabled FROM app_users WHERE id = :id',
            );
            $statement->execute(['id' => $userId]);
            $user = $statement->fetch();
            if ($secret === '' || !$user || databaseBoolean($user['totp_enabled'])) {
                throw new InvalidArgumentException('Inicia de nuevo la configuración de autenticación en dos pasos.');
            }
            if (!password_verify($password, $user['password_hash'])) {
                throw new InvalidArgumentException('La contraseña actual es incorrecta.');
            }
            $step = verifyTotp($secret, $code);
            if ($step === null) {
                throw new InvalidArgumentException('El código no es válido. Verifica la hora del dispositivo e inténtalo otra vez.');
            }

            $statement = $pdo->prepare(
                'UPDATE app_users
                 SET totp_secret = :secret, totp_enabled = TRUE, totp_last_step = :step
                 WHERE id = :id AND NOT totp_enabled',
            );
            $statement->execute(['secret' => $secret, 'step' => $step, 'id' => $userId]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('No se pudo activar la autenticación en dos pasos.');
            }
            unset($_SESSION['totp_setup_secret']);
            flash('success', 'La autenticación en dos pasos quedó activada.');
        } elseif ($action === 'disable') {
            $password = (string) ($_POST['password'] ?? '');
            $code = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';
            $statement = $pdo->prepare(
                'SELECT password_hash, totp_secret, totp_last_step
                 FROM app_users WHERE id = :id AND totp_enabled',
            );
            $statement->execute(['id' => $userId]);
            $user = $statement->fetch();
            if (!$user || !password_verify($password, $user['password_hash'])) {
                throw new InvalidArgumentException('La contraseña actual es incorrecta.');
            }
            $step = verifyTotp((string) $user['totp_secret'], $code);
            if ($step === null || ($user['totp_last_step'] !== null && $step <= (int) $user['totp_last_step'])) {
                throw new InvalidArgumentException('El código no es válido o ya fue utilizado.');
            }

            $statement = $pdo->prepare(
                'UPDATE app_users
                 SET totp_secret = NULL, totp_enabled = FALSE, totp_last_step = NULL
                 WHERE id = :id AND totp_enabled
                  AND (totp_last_step IS NULL OR totp_last_step < :last_step)',
            );
            $statement->execute(['id' => $userId, 'last_step' => $step]);
            if ($statement->rowCount() !== 1) {
                throw new InvalidArgumentException('El código ya fue utilizado. Espera al siguiente e inténtalo otra vez.');
            }
            unset($_SESSION['totp_setup_secret']);
            flash('success', 'La autenticación en dos pasos quedó desactivada.');
        } else {
            throw new InvalidArgumentException('La operación solicitada no es válida.');
        }
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        flash('error', 'No se pudo actualizar la configuración de seguridad. Inténtalo otra vez.');
    } catch (InvalidArgumentException|RuntimeException $exception) {
        flash('error', $exception->getMessage());
    }

    header('Location: security.php');
    exit;
}

$statement = $pdo->prepare('SELECT username, totp_enabled FROM app_users WHERE id = :id');
$statement->execute(['id' => $_SESSION['user_id']]);
$user = $statement->fetch();
$enabled = $user && databaseBoolean($user['totp_enabled']);
$setupSecret = $enabled || !$user ? null : ($_SESSION['totp_setup_secret'] ?? null);
$provisioningUri = $setupSecret === null
    ? ''
    : 'otpauth://totp/' . rawurlencode('SIG:' . $user['username'])
        . '?secret=' . rawurlencode($setupSecret)
        . '&issuer=SIG&algorithm=SHA1&digits=6&period=30';

pageHeader('Seguridad', 'seguridad');
?>
<section class="panel security-panel">
    <div class="panel-heading"><div><h2>Autenticación en dos pasos</h2><p>Protege tu cuenta con códigos temporales de Google Authenticator.</p></div></div>
    <?php if ($enabled): ?>
        <div class="security-status active"><strong>Activada</strong><span>En cada inicio de sesión se solicitará un código de tu aplicación autenticadora.</span></div>
        <form method="post" class="form-stack security-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="disable">
            <h2>Desactivar</h2>
            <p class="muted">Confirma tu contraseña y un código vigente para desactivar esta protección.</p>
            <label>Contraseña actual<input type="password" name="password" required autocomplete="current-password"></label>
            <label>Código de Google Authenticator<input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code"></label>
            <button class="button secondary" type="submit">Desactivar 2FA</button>
        </form>
    <?php elseif (is_string($setupSecret)): ?>
        <div class="security-status pending"><strong>Configuración pendiente</strong><span>Introduce esta clave manualmente en Google Authenticator. El secreto se muestra solo durante la configuración.</span></div>
        <ol class="security-steps">
            <li>En Google Authenticator, pulsa <strong>+</strong> y selecciona introducir una clave de configuración.</li>
            <li>Cuenta: <strong>SIG · <?= h($user['username']) ?></strong>. Tipo: <strong>basada en tiempo</strong>.</li>
            <li>Clave: <code class="totp-secret"><?= h($setupSecret) ?></code></li>
        </ol>
        <details class="provisioning-details">
            <summary>Ver URI de configuración manual</summary>
            <code class="provisioning-uri"><?= h($provisioningUri) ?></code>
        </details>
        <form method="post" class="form-stack security-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="enable">
            <label>Código de seis dígitos<input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code"></label>
            <label>Contraseña actual<input type="password" name="password" required autocomplete="current-password"></label>
            <div class="security-actions">
                <button class="button primary" type="submit">Confirmar y activar</button>
            </div>
        </form>
        <form method="post" class="security-cancel">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="cancel">
            <button class="button secondary" type="submit">Cancelar configuración</button>
        </form>
    <?php else: ?>
        <div class="security-status inactive"><strong>No activada</strong><span>Es opcional. Si la activas, necesitarás la contraseña y el código de tu aplicación para iniciar sesión.</span></div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="start">
            <button class="button primary" type="submit">Configurar Google Authenticator</button>
        </form>
    <?php endif; ?>
</section>
<?php pageFooter(); ?>
