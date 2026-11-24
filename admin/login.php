<?php
/**
 * Admin Login (password_hash / password_verify + CSRF + IP Rate Limit)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

init_secure_session();

if (is_admin_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $rlCfg = app_config()['rate_limit'];
        $rl    = check_rate_limit(
            'admin_login',
            (int) ($rlCfg['login_max_attempts'] ?? 6),
            (int) ($rlCfg['login_window_seconds'] ?? 900)
        );

        if (!$rl['allowed']) {
            $mins  = max(1, (int) ceil($rl['retry_after'] / 60));
            $error = "Too many login attempts. Please wait {$mins} minute(s) and try again.";
        } else {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');

            $stmt = db()->prepare('SELECT id, username, password_hash, full_name FROM admin_users WHERE username = :u LIMIT 1');
            $stmt->execute([':u' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['admin_id']       = (int) $user['id'];
                $_SESSION['admin_username'] = $user['username'];
                $_SESSION['admin_name']     = $user['full_name'];

                $upd = db()->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = :id');
                $upd->execute([':id' => $user['id']]);

                header('Location: index.php');
                exit;
            }

            $error = 'Invalid username or password.';
        }
    }
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Login | Techzo Kochi Scratch &amp; Win</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="login-page">
    <div class="login-card">
        <h1>Techzo Kochi Admin</h1>
        <p class="sub">Sign in to manage the Scratch &amp; Win campaign</p>

        <?php if ($error !== ''): ?>
            <div class="flash flash-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

            <div class="field" style="margin-bottom: 14px;">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus autocomplete="username">
            </div>

            <div class="field" style="margin-bottom: 20px;">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%; padding:11px;">
                Sign In
            </button>
        </form>
    </div>
</body>
</html>
