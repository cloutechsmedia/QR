<?php
/**
 * Techzo Kochi Scratch & Win - One-Time Setup Script
 *
 * Creates the initial Admin account (using password_hash) and initializes gift_slots.
 * Automatically locks itself once an admin user exists. DELETE THIS FILE after setup!
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/settings.php';

init_secure_session();

$error   = '';
$success = '';
$locked  = false;

try {
    $pdo        = db();
    $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    $locked     = $adminCount > 0;
} catch (Throwable $e) {
    $error = 'Database connection failed. Please import sql/schema.sql and update config/config.php first. (' . $e->getMessage() . ')';
}

if (!$locked && $error === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $fullName = trim((string) ($_POST['full_name'] ?? 'Admin'));
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['password_confirm'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $username)) {
            $error = 'Username must be 3-32 characters (letters, numbers, _, ., -).';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins  = $pdo->prepare('INSERT INTO admin_users (username, password_hash, full_name) VALUES (:u, :h, :n)');
            $ins->execute([':u' => $username, ':h' => $hash, ':n' => $fullName !== '' ? $fullName : 'Admin']);

            $settings = get_settings();
            ensure_gift_slots_initialized(
                $pdo,
                max(1, (int) ($settings['total_gifts'] ?? 3)),
                max(3, (int) ($settings['pool_size'] ?? 100))
            );

            $success = 'Admin account created & 3 random gift slots initialized! Please DELETE setup.php from your server now.';
            $locked  = true;
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
    <title>One-Time Setup | Techzo Kochi Scratch &amp; Win</title>
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body class="login-page">
    <div class="login-card" style="max-width:440px;">
        <h1>Campaign Setup</h1>
        <p class="sub">Techzo Kochi Scratch &amp; Win — Initial Installer</p>

        <?php if ($error !== ''): ?>
            <div class="flash flash-error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="flash flash-success"><?= e($success) ?></div>
            <a href="admin/login.php" class="btn btn-primary" style="width:100%;">Go to Admin Login</a>
        <?php elseif ($locked): ?>
            <div class="flash flash-info">
                Setup is already complete (an admin user already exists). For security, please delete <code>setup.php</code> from your server.
            </div>
            <a href="admin/login.php" class="btn btn-primary" style="width:100%;">Go to Admin Login</a>
        <?php elseif ($error === ''): ?>
            <form method="post" action="setup.php">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

                <div class="field" style="margin-bottom:12px;">
                    <label for="full_name">Admin Name</label>
                    <input type="text" id="full_name" name="full_name" value="Techzo Admin" required>
                </div>

                <div class="field" style="margin-bottom:12px;">
                    <label for="username">Admin Username</label>
                    <input type="text" id="username" name="username" value="admin" required>
                </div>

                <div class="field" style="margin-bottom:12px;">
                    <label for="password">Password (min 8 chars)</label>
                    <input type="password" id="password" name="password" required minlength="8">
                </div>

                <div class="field" style="margin-bottom:18px;">
                    <label for="password_confirm">Confirm Password</label>
                    <input type="password" id="password_confirm" name="password_confirm" required minlength="8">
                </div>

                <button type="submit" class="btn btn-gold" style="width:100%; padding:11px;">
                    Create Admin &amp; Initialize Gift Pool
                </button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
