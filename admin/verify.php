<?php
/**
 * Admin Coupon & Gift Verification Page
 * Validates discount validity window (valid_from .. valid_to) & allows marking redeemed/delivered.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/settings.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

require_admin_auth();

$pdo      = db();
$settings = get_settings();

$codeInput = strtoupper(trim((string) ($_GET['code'] ?? $_POST['code'] ?? '')));

// Handle Redeem / Deliver / Verify IG actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $codeInput !== '') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token.');
        header('Location: verify.php?code=' . rawurlencode($codeInput));
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $stmt   = $pdo->prepare('SELECT * FROM participants WHERE code = :code LIMIT 1');
    $stmt->execute([':code' => $codeInput]);
    $p = $stmt->fetch();

    if ($p) {
        if ($action === 'verify_ig') {
            $newSt = ($p['status'] === 'pending') ? 'verified' : $p['status'];
            $pdo->prepare('UPDATE participants SET ig_verified = 1, status = :st WHERE id = :id')
                ->execute([':st' => $newSt, ':id' => $p['id']]);
            set_flash('success', "Instagram follow verified for @{$p['instagram_username']}.");
        } elseif ($action === 'redeem') {
            if ($p['status'] === 'revoked') {
                set_flash('error', 'This code has been revoked.');
            } elseif ($p['status'] === 'redeemed' || $p['status'] === 'delivered') {
                set_flash('error', 'This code has already been redeemed/delivered.');
            } elseif ($p['result_type'] === 'discount') {
                $dateCheck = evaluate_coupon_date_status($settings['valid_from'], $settings['valid_to']);
                if ($dateCheck !== 'valid') {
                    set_flash('error', 'Cannot redeem: Discount coupon is outside its valid redemption dates.');
                } else {
                    $pdo->prepare("UPDATE participants SET status = 'redeemed', redeemed_at = NOW() WHERE id = :id")
                        ->execute([':id' => $p['id']]);
                    set_flash('success', "Coupon {$p['code']} successfully marked as REDEEMED!");
                }
            } else {
                $pdo->prepare("UPDATE participants SET status = 'delivered', delivered_at = NOW(), ig_verified = 1 WHERE id = :id")
                    ->execute([':id' => $p['id']]);
                set_flash('success', "Gift {$p['code']} successfully marked as DELIVERED!");
            }
        }
    }

    header('Location: verify.php?code=' . rawurlencode($codeInput));
    exit;
}

// Look up code if provided
$record       = null;
$statusClass  = '';
$statusBadge  = '';
$statusTitle  = '';
$statusDetail = '';
$canRedeem    = false;

if ($codeInput !== '') {
    $stmt = $pdo->prepare('SELECT * FROM participants WHERE code = :code LIMIT 1');
    $stmt->execute([':code' => $codeInput]);
    $record = $stmt->fetch();

    if ($record) {
        $validFrom = (string) ($settings['valid_from'] ?? '2026-11-30');
        $validTo   = (string) ($settings['valid_to'] ?? '2026-12-05');
        $rangeFull = format_validity_range($validFrom, $validTo, true);

        if ($record['status'] === 'revoked') {
            $statusClass  = 'status-invalid';
            $statusBadge  = 'badge-revoked';
            $statusTitle  = 'Revoked Code';
            $statusDetail = 'This reward was revoked by an administrator and cannot be claimed.';
        } elseif ($record['status'] === 'redeemed' || $record['status'] === 'delivered') {
            $statusClass  = 'status-warning';
            $statusBadge  = 'badge-redeemed';
            $statusTitle  = $record['result_type'] === 'gift' ? 'Already Delivered' : 'Already Redeemed';
            $ts           = $record['result_type'] === 'gift' ? $record['delivered_at'] : $record['redeemed_at'];
            $statusDetail = 'Used on ' . ($ts ?: 'earlier') . '. Cannot be used again.';
        } elseif ($record['result_type'] === 'discount') {
            $dateStatus = evaluate_coupon_date_status($validFrom, $validTo);
            if ($dateStatus === 'not_yet_valid') {
                $statusClass  = 'status-warning';
                $statusBadge  = 'badge-not-yet';
                $statusTitle  = 'Not Yet Valid (Expired / Not yet valid)';
                $statusDetail = "This Rs. {$settings['discount_amount']} discount coupon is redeemable ONLY from {$rangeFull}. Today is " . date('j M Y') . '.';
            } elseif ($dateStatus === 'expired') {
                $statusClass  = 'status-invalid';
                $statusBadge  = 'badge-expired';
                $statusTitle  = 'Expired (Expired / Not yet valid)';
                $statusDetail = "This coupon was valid only from {$rangeFull} and has expired.";
            } else {
                $statusClass  = 'status-valid';
                $statusBadge  = 'badge-redeemed';
                $statusTitle  = 'Valid Discount Coupon!';
                $minBill      = (int) ($settings['min_bill_amount'] ?? 0);
                $minBillText  = $minBill > 0 ? " Minimum bill: Rs. {$minBill}." : '';
                $statusDetail = "Eligible for Rs. {$settings['discount_amount']} off today ({$rangeFull}).{$minBillText}";
                $canRedeem    = true;
            }
        } else {
            // Gift winner
            $statusClass  = 'status-valid';
            $statusBadge  = 'badge-gift';
            $statusTitle  = 'Valid Gift Winner!';
            $statusDetail = "Winner of {$settings['gift_name']}. Verify their Instagram follow before handing over the gift.";
            $canRedeem    = true;
        }
    }
}

$pageTitle = 'Verify Code';
require __DIR__ . '/_header.php';
?>

<div class="page-header">
    <div>
        <h1>Coupon &amp; Gift Verification</h1>
        <p>
            Today: <strong><?= e(date('j M Y')) ?></strong>
            &nbsp;&bull;&nbsp; Discount Window: <strong><?= e(format_validity_range($settings['valid_from'], $settings['valid_to'], true)) ?></strong>
        </p>
    </div>
</div>

<div class="panel">
    <form method="get" action="verify.php" style="display:flex; gap:10px; flex-wrap:wrap;">
        <input
            type="text"
            name="code"
            value="<?= e($codeInput) ?>"
            placeholder="Enter code (e.g. TZ1000-AB12CD or GIFT-XY34ZW)"
            required
            autofocus
            style="flex:1; min-width:240px; padding:11px 14px; border-radius:10px; border:1px solid #cbd5e1; font-family:inherit; font-size:0.98rem; text-transform:uppercase; font-weight:600;"
        >
        <button type="submit" class="btn btn-primary" style="padding:11px 22px;">Verify Code</button>
    </form>

    <?php if ($codeInput !== '' && !$record): ?>
        <div class="verify-result status-invalid">
            <span class="badge badge-revoked">NOT FOUND</span>
            <h3 style="margin:8px 0 4px;">Code "<?= e($codeInput) ?>" does not exist</h3>
            <p style="margin:0; font-size:0.88rem; color:var(--muted);">Double-check the spelling or search by name/WhatsApp in the Participants page.</p>
        </div>
    <?php elseif ($record): ?>
        <div class="verify-result <?= e($statusClass) ?>">
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                <span class="badge <?= e($statusBadge) ?>"><?= e($statusTitle) ?></span>
                <span class="code-pill" style="font-size:1rem;"><?= e($record['code']) ?></span>
            </div>
            <p style="margin:10px 0 0; font-size:0.9rem;"><?= e($statusDetail) ?></p>

            <div class="verify-meta">
                <div>
                    <span>Participant</span>
                    <strong><?= e($record['name']) ?> (#<?= e($record['entry_no']) ?>)</strong>
                </div>
                <div>
                    <span>WhatsApp</span>
                    <strong><?= e($record['whatsapp']) ?></strong>
                </div>
                <div>
                    <span>Instagram</span>
                    <strong>
                        <a href="https://www.instagram.com/<?= e(rawurlencode($record['instagram_username'])) ?>" target="_blank" rel="noopener noreferrer">
                            @<?= e($record['instagram_username']) ?>
                        </a>
                        <?= $record['ig_verified'] ? '✓ Verified' : '(Unverified)' ?>
                    </strong>
                </div>
                <div>
                    <span>Reward Type</span>
                    <strong><?= $record['result_type'] === 'gift' ? e($settings['gift_name']) : ('Rs. ' . e($settings['discount_amount']) . ' Off') ?></strong>
                </div>
                <div>
                    <span>Played At</span>
                    <strong><?= e($record['created_at']) ?></strong>
                </div>
            </div>

            <div class="action-stack">
                <?php if (!$record['ig_verified'] && $record['status'] !== 'revoked'): ?>
                    <form method="post" action="verify.php">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="code" value="<?= e($record['code']) ?>">
                        <input type="hidden" name="action" value="verify_ig">
                        <button type="submit" class="btn btn-outline">Mark Instagram Follow Verified</button>
                    </form>
                <?php endif; ?>

                <?php if ($canRedeem): ?>
                    <form method="post" action="verify.php" data-confirm="Confirm marking <?= e($record['code']) ?> as <?= $record['result_type'] === 'gift' ? 'Delivered' : 'Redeemed' ?>?">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="code" value="<?= e($record['code']) ?>">
                        <input type="hidden" name="action" value="redeem">
                        <button type="submit" class="btn btn-success">
                            <?= $record['result_type'] === 'gift' ? 'Mark Gift Delivered' : 'Mark Coupon Redeemed' ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
