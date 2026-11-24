<?php
/**
 * Admin Dashboard
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/settings.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

require_admin_auth();

$pdo      = db();
$settings = get_settings();

// Handle "Draw Remaining Gifts" action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'draw_remaining') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid CSRF token.');
    } else {
        try {
            $result = draw_remaining_gifts($pdo);
            if ($result['drawn'] > 0) {
                $codes = array_map(fn($w) => $w['name'] . ' (' . $w['code'] . ')', $result['winners']);
                set_flash('success', $result['message'] . ' Winners: ' . implode(', ', $codes));
            } else {
                set_flash('info', $result['message']);
            }
        } catch (Throwable $e) {
            set_flash('error', 'Draw failed: ' . $e->getMessage());
        }
    }
    header('Location: index.php');
    exit;
}

// Ensure gift slots exist
$totalGifts = max(1, (int) ($settings['total_gifts'] ?? 3));
$poolSize   = max($totalGifts, (int) ($settings['pool_size'] ?? 100));
ensure_gift_slots_initialized($pdo, $totalGifts, $poolSize);

// Dashboard Metrics
$totalParticipants = (int) $pdo->query('SELECT COUNT(*) FROM participants')->fetchColumn();
$giftsAwarded      = (int) $pdo->query("SELECT COUNT(*) FROM participants WHERE result_type = 'gift' AND status != 'revoked'")->fetchColumn();
$discountsGiven    = (int) $pdo->query("SELECT COUNT(*) FROM participants WHERE result_type = 'discount' AND status != 'revoked'")->fetchColumn();
$redeemedCount     = (int) $pdo->query("SELECT COUNT(*) FROM participants WHERE status IN ('redeemed', 'delivered')")->fetchColumn();
$igVerifiedCount   = (int) $pdo->query('SELECT COUNT(*) FROM participants WHERE ig_verified = 1')->fetchColumn();
$unawardedSlots    = (int) $pdo->query('SELECT COUNT(*) FROM gift_slots WHERE awarded = 0')->fetchColumn();
$remainingGifts    = max(0, $totalGifts - $giftsAwarded);

$recentParticipants = $pdo->query(
    'SELECT id, entry_no, name, whatsapp, instagram_username, result_type, code, status, ig_verified, created_at
     FROM participants
     ORDER BY id DESC
     LIMIT 10'
)->fetchAll();

$giftWinners = $pdo->query(
    "SELECT id, entry_no, name, whatsapp, instagram_username, code, status, ig_verified, created_at
     FROM participants
     WHERE result_type = 'gift'
     ORDER BY id DESC"
)->fetchAll();

$pageTitle = 'Dashboard';
require __DIR__ . '/_header.php';
?>

<div class="page-header">
    <div>
        <h1>Campaign Dashboard</h1>
        <p>
            Status:
            <?php if (($settings['campaign_active'] ?? '0') === '1'): ?>
                <span class="badge badge-redeemed">LIVE</span>
            <?php else: ?>
                <span class="badge badge-revoked">PAUSED</span>
            <?php endif; ?>
            &nbsp;&bull;&nbsp; Pool Size N = <?= e($poolSize) ?>
            &nbsp;&bull;&nbsp; Coupon Valid: <?= e(format_validity_range($settings['valid_from'], $settings['valid_to'], true)) ?>
        </p>
    </div>
    <div class="action-stack">
        <a href="verify.php" class="btn btn-gold">Verify Coupon Code</a>
        <a href="participants.php?export=csv" class="btn btn-outline">Export CSV</a>
    </div>
</div>

<!-- KPI Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Total Participants</div>
        <div class="stat-value"><?= e(number_format($totalParticipants)) ?></div>
        <div class="stat-sub"><?= e($igVerifiedCount) ?> Instagram verified</div>
    </div>

    <div class="stat-card highlight">
        <div class="stat-label"><?= e($settings['gift_name']) ?>s Given</div>
        <div class="stat-value"><?= e($giftsAwarded) ?> / <?= e($totalGifts) ?></div>
        <div class="stat-sub"><?= e($remainingGifts) ?> gift(s) remaining in pool</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Rs. <?= e($settings['discount_amount']) ?> Coupons</div>
        <div class="stat-value"><?= e(number_format($discountsGiven)) ?></div>
        <div class="stat-sub">Valid <?= e(format_validity_range($settings['valid_from'], $settings['valid_to'])) ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Redeemed / Delivered</div>
        <div class="stat-value"><?= e(number_format($redeemedCount)) ?></div>
        <div class="stat-sub">Coupons &amp; gifts fulfilled</div>
    </div>
</div>

<div class="two-col">
    <!-- Quick Coupon Verification -->
    <div class="panel">
        <div class="panel-header">
            <h2>Quick Code Verification</h2>
        </div>
        <form method="get" action="verify.php" style="display:flex; gap:10px;">
            <input
                type="text"
                name="code"
                placeholder="Enter GIFT-XXXXXX or TZ1000-XXXXXX"
                required
                style="flex:1; padding:10px 12px; border-radius:9px; border:1px solid #cbd5e1; font-family:inherit; text-transform:uppercase;"
            >
            <button type="submit" class="btn btn-primary">Check</button>
        </form>
        <p style="margin:12px 0 0; font-size:0.82rem; color:var(--muted);">
            Checks whether a code is active today (<?= e(date('j M Y')) ?>), not yet valid, expired, already redeemed, or revoked.
        </p>
    </div>

    <!-- Draw Remaining Gifts -->
    <div class="panel">
        <div class="panel-header">
            <h2>Gift Pool &amp; End-of-Campaign Draw</h2>
        </div>
        <p style="margin:0 0 14px; font-size:0.86rem; color:var(--muted);">
            Pre-selected winning slots are stored privately in <code>gift_slots</code>.
            Currently <strong><?= e($unawardedSlots) ?></strong> unawarded slot(s) remain out of <strong><?= e($totalGifts) ?></strong>.
            If fewer than <strong><?= e($poolSize) ?></strong> people joined when the campaign ends, draw the remaining gifts randomly among existing participants.
        </p>
        <?php if ($remainingGifts > 0 && $totalParticipants > $giftsAwarded): ?>
            <form
                method="post"
                action="index.php"
                data-confirm="Draw <?= e($remainingGifts) ?> remaining <?= e($settings['gift_name']) ?>(s) randomly from existing non-gift participants? This upgrades their code to GIFT-XXXXXX."
            >
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="draw_remaining">
                <button type="submit" class="btn btn-gold">
                    Draw <?= e($remainingGifts) ?> Remaining Gift(s) Now
                </button>
            </form>
        <?php else: ?>
            <span class="badge badge-redeemed">
                <?= $remainingGifts === 0 ? 'All gifts awarded' : 'Waiting for participants' ?>
            </span>
        <?php endif; ?>
    </div>
</div>

<!-- Gift Winners List -->
<div class="panel">
    <div class="panel-header">
        <h2><?= e($settings['gift_name']) ?> Winners</h2>
        <a href="participants.php?result_type=gift" class="btn btn-outline btn-sm">Manage Winners</a>
    </div>
    <?php if (empty($giftWinners)): ?>
        <p style="margin:0; font-size:0.88rem; color:var(--muted);">No gift winners yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>WhatsApp</th>
                        <th>Instagram</th>
                        <th>Code</th>
                        <th>Status</th>
                        <th>Won At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($giftWinners as $w): ?>
                        <tr>
                            <td>#<?= e($w['entry_no']) ?></td>
                            <td><strong><?= e($w['name']) ?></strong></td>
                            <td><?= e($w['whatsapp']) ?></td>
                            <td>
                                <a href="https://www.instagram.com/<?= e(rawurlencode($w['instagram_username'])) ?>" target="_blank" rel="noopener noreferrer">
                                    @<?= e($w['instagram_username']) ?>
                                </a>
                            </td>
                            <td><span class="code-pill"><?= e($w['code']) ?></span></td>
                            <td>
                                <span class="badge badge-<?= e($w['status']) ?>"><?= e(ucfirst($w['status'])) ?></span>
                            </td>
                            <td><?= e($w['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Recent Participants -->
<div class="panel">
    <div class="panel-header">
        <h2>Recent Participants</h2>
        <a href="participants.php" class="btn btn-outline btn-sm">View All (<?= e($totalParticipants) ?>)</a>
    </div>
    <?php if (empty($recentParticipants)): ?>
        <p style="margin:0; font-size:0.88rem; color:var(--muted);">No participants have played yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Entry</th>
                        <th>Name</th>
                        <th>WhatsApp</th>
                        <th>Instagram</th>
                        <th>Result</th>
                        <th>Code</th>
                        <th>Status</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentParticipants as $p): ?>
                        <tr>
                            <td>#<?= e($p['entry_no']) ?></td>
                            <td><?= e($p['name']) ?></td>
                            <td><?= e($p['whatsapp']) ?></td>
                            <td>@<?= e($p['instagram_username']) ?></td>
                            <td>
                                <span class="badge badge-<?= e($p['result_type']) ?>">
                                    <?= $p['result_type'] === 'gift' ? 'Gift' : 'Discount' ?>
                                </span>
                            </td>
                            <td><span class="code-pill"><?= e($p['code']) ?></span></td>
                            <td>
                                <span class="badge badge-<?= e($p['status']) ?>"><?= e(ucfirst($p['status'])) ?></span>
                            </td>
                            <td><?= e($p['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
