<?php
/**
 * Shared Admin Layout Header
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/settings.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

require_admin_auth();

$currentPage = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$csrf        = csrf_token();
$flash       = get_flash();
$pageTitle   = $pageTitle ?? 'Admin Panel';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> | Techzo Kochi Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
    <nav class="admin-nav">
        <div class="admin-nav-inner">
            <a href="index.php" class="admin-brand">
                <span>Techzo Kochi</span>
                <span class="tag">Admin</span>
            </a>

            <div class="admin-links">
                <a href="index.php" class="<?= $currentPage === 'index.php' ? 'active' : '' ?>">Dashboard</a>
                <a href="participants.php" class="<?= $currentPage === 'participants.php' ? 'active' : '' ?>">Participants</a>
                <a href="crossmatch.php" class="<?= $currentPage === 'crossmatch.php' ? 'active' : '' ?>">Cross-Match IG</a>
                <a href="verify.php" class="<?= $currentPage === 'verify.php' ? 'active' : '' ?>">Verify Code</a>
                <a href="settings.php" class="<?= $currentPage === 'settings.php' ? 'active' : '' ?>">Settings</a>
                <a href="../index.php" target="_blank" rel="noopener noreferrer">View Site ↗</a>
                <form method="post" action="logout.php" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <button type="submit" class="nav-logout">Logout (<?= e($_SESSION['admin_username'] ?? 'admin') ?>)</button>
                </form>
            </div>
        </div>
    </nav>

    <main class="admin-container">
        <?php if ($flash): ?>
            <div class="flash flash-<?= e($flash['type']) ?>" role="status">
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>
