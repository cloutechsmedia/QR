<?php
/**
 * Admin Campaign Settings
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/settings.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

require_admin_auth();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token.');
        header('Location: settings.php');
        exit;
    }

    $action = (string) ($_POST['action'] ?? 'save_settings');

    if ($action === 'regenerate_slots') {
        $curr       = get_settings();
        $totalGifts = max(1, (int) ($curr['total_gifts'] ?? 3));
        $poolSize   = max($totalGifts, (int) ($curr['pool_size'] ?? 100));
        $res        = regenerate_unawarded_slots($pdo, $totalGifts, $poolSize);
        set_flash('success', "Regenerated {$res['regenerated']} unawarded gift slot(s). ({$res['awarded']} already awarded.)");
        header('Location: settings.php');
        exit;
    }

    if ($action === 'generate_test_token') {
        $testIg = normalize_instagram((string) ($_POST['test_ig_username'] ?? ''));
        if (!is_valid_instagram($testIg)) {
            set_flash('error', 'Enter a valid Instagram username to generate a test verified link.');
        } else {
            $tok = bin2hex(random_bytes(20));
            $ins = $pdo->prepare(
                'INSERT INTO ig_verify_tokens (token, instagram_username, used, expires_at)
                 VALUES (:token, :ig, 0, DATE_ADD(NOW(), INTERVAL 24 HOUR))'
            );
            $ins->execute([':token' => $tok, ':ig' => $testIg]);
            $url = get_base_url() . '/?token=' . rawurlencode($tok);
            set_flash('success', "Verified link for @{$testIg}: {$url}");
        }
        header('Location: settings.php');
        exit;
    }

    // Validate & Save Settings
    $totalGifts  = max(1, min(100, (int) ($_POST['total_gifts'] ?? 3)));
    $poolSize    = max($totalGifts, min(100000, (int) ($_POST['pool_size'] ?? 100)));
    $discountAmt = max(1, (int) ($_POST['discount_amount'] ?? 1000));
    $minBill     = max(0, (int) ($_POST['min_bill_amount'] ?? 0));

    $validFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['valid_from'] ?? '')) ? $_POST['valid_from'] : '2026-11-30';
    $validTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['valid_to'] ?? '')) ? $_POST['valid_to'] : '2026-12-05';

    $startDt = trim((string) ($_POST['campaign_start'] ?? '2026-11-01T00:00'));
    $endDt   = trim((string) ($_POST['campaign_end'] ?? '2026-12-05T23:59'));
    $startSql = str_replace('T', ' ', $startDt) . (strlen($startDt) === 16 ? ':00' : '');
    $endSql   = str_replace('T', ' ', $endDt) . (strlen($endDt) === 16 ? ':00' : '');

    $igHandle = ltrim(trim((string) ($_POST['instagram_handle'] ?? 'techzo__official')), '@');
    $claimWa  = preg_replace('/\D+/', '', (string) ($_POST['whatsapp_claim_number'] ?? '')) ?? '';

    $colorCheck = fn(string $key, string $fallback): string =>
        preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($_POST[$key] ?? '')) ? $_POST[$key] : $fallback;

    save_settings([
        'campaign_active'        => isset($_POST['campaign_active']) ? '1' : '0',
        'campaign_title'         => trim((string) ($_POST['campaign_title'] ?? 'Techzo Kochi Scratch & Win')),
        'total_gifts'            => (string) $totalGifts,
        'gift_name'              => trim((string) ($_POST['gift_name'] ?? 'Bluetooth Speaker')),
        'pool_size'              => (string) $poolSize,
        'discount_amount'        => (string) $discountAmt,
        'valid_from'             => $validFrom,
        'valid_to'               => $validTo,
        'min_bill_amount'        => (string) $minBill,
        'campaign_start'         => $startSql,
        'campaign_end'           => $endSql,
        'instagram_handle'       => $igHandle,
        'whatsapp_claim_number'  => $claimWa,
        'scratch_hint_text'      => trim((string) ($_POST['scratch_hint_text'] ?? 'Scratch Here')),
        'scratch_subtext'        => trim((string) ($_POST['scratch_subtext'] ?? 'Swipe to reveal your Kochi surprise')),
        'scratch_foil_primary'   => $colorCheck('scratch_foil_primary', '#d4a64a'),
        'scratch_foil_secondary' => $colorCheck('scratch_foil_secondary', '#f5d782'),
        'scratch_card_bg'        => $colorCheck('scratch_card_bg', '#0a3d42'),
        'require_ig_token'       => isset($_POST['require_ig_token']) ? '1' : '0',
        'ig_dm_keyword'          => strtoupper(trim((string) ($_POST['ig_dm_keyword'] ?? 'KOCHI'))) ?: 'KOCHI',
        'ig_webhook_secret'      => trim((string) ($_POST['ig_webhook_secret'] ?? '')) ?: 'tz_kochi_secret_' . bin2hex(random_bytes(6)),
    ]);

    set_flash('success', 'Campaign settings updated successfully.');
    header('Location: settings.php');
    exit;
}

$s = get_settings();
$startInput = str_replace(' ', 'T', substr((string) ($s['campaign_start'] ?? '2026-11-01 00:00:00'), 0, 16));
$endInput   = str_replace(' ', 'T', substr((string) ($s['campaign_end'] ?? '2026-12-05 23:59:59'), 0, 16));

$pageTitle = 'Campaign Settings';
require __DIR__ . '/_header.php';
?>

<div class="page-header">
    <div>
        <h1>Campaign Settings</h1>
        <p>Configure rewards, validity dates, Instagram handle, pool size N, and scratch card styling.</p>
    </div>
</div>

<form method="post" action="settings.php">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="save_settings">

    <div class="panel">
        <div class="panel-header">
            <h2>General &amp; Rewards</h2>
        </div>

        <div class="form-grid">
            <div class="field">
                <label>Campaign Status</label>
                <label style="display:flex; align-items:center; gap:8px; font-weight:500; cursor:pointer; padding-top:6px;">
                    <input type="checkbox" name="campaign_active" value="1" <?= ($s['campaign_active'] ?? '0') === '1' ? 'checked' : '' ?> style="width:18px; height:18px;">
                    <span>Campaign is Active (Publicly Open)</span>
                </label>
            </div>

            <div class="field">
                <label for="campaign_title">Campaign Title</label>
                <input type="text" id="campaign_title" name="campaign_title" value="<?= e($s['campaign_title']) ?>" required>
            </div>

            <div class="field">
                <label for="gift_name">Grand Gift Name</label>
                <input type="text" id="gift_name" name="gift_name" value="<?= e($s['gift_name']) ?>" required>
            </div>

            <div class="field">
                <label for="total_gifts">Total Gifts</label>
                <input type="number" id="total_gifts" name="total_gifts" min="1" max="100" value="<?= e($s['total_gifts']) ?>" required>
                <small>Default: 3 Bluetooth Speakers</small>
            </div>

            <div class="field">
                <label for="pool_size">Expected Pool Size (N)</label>
                <input type="number" id="pool_size" name="pool_size" min="3" max="100000" value="<?= e($s['pool_size']) ?>" required>
                <small>Winning slots are randomly picked between 1 and N</small>
            </div>

            <div class="field">
                <label for="discount_amount">Discount Amount (Rs.)</label>
                <input type="number" id="discount_amount" name="discount_amount" min="1" value="<?= e($s['discount_amount']) ?>" required>
            </div>

            <div class="field">
                <label for="min_bill_amount">Minimum Bill Amount (Rs., Optional)</label>
                <input type="number" id="min_bill_amount" name="min_bill_amount" min="0" value="<?= e($s['min_bill_amount']) ?>">
                <small>Set 0 for no minimum bill requirement</small>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <h2>Validity Dates &amp; Social Handles</h2>
        </div>

        <div class="form-grid">
            <div class="field">
                <label for="valid_from">Coupon Valid From</label>
                <input type="date" id="valid_from" name="valid_from" value="<?= e($s['valid_from']) ?>" required>
            </div>

            <div class="field">
                <label for="valid_to">Coupon Valid To</label>
                <input type="date" id="valid_to" name="valid_to" value="<?= e($s['valid_to']) ?>" required>
            </div>

            <div class="field">
                <label for="campaign_start">Campaign Start</label>
                <input type="datetime-local" id="campaign_start" name="campaign_start" value="<?= e($startInput) ?>">
            </div>

            <div class="field">
                <label for="campaign_end">Campaign End</label>
                <input type="datetime-local" id="campaign_end" name="campaign_end" value="<?= e($endInput) ?>">
            </div>

            <div class="field">
                <label for="instagram_handle">Instagram Handle (without @)</label>
                <input type="text" id="instagram_handle" name="instagram_handle" value="<?= e($s['instagram_handle']) ?>" required>
            </div>

            <div class="field">
                <label for="whatsapp_claim_number">WhatsApp Claim Number (with country code)</label>
                <input type="text" id="whatsapp_claim_number" name="whatsapp_claim_number" value="<?= e($s['whatsapp_claim_number']) ?>" placeholder="919876543210">
                <small>Used for the "Claim on WhatsApp" button for gift winners</small>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <h2>Scratch Card Text &amp; Colors</h2>
        </div>

        <div class="form-grid">
            <div class="field">
                <label for="scratch_hint_text">Scratch Foil Main Text</label>
                <input type="text" id="scratch_hint_text" name="scratch_hint_text" value="<?= e($s['scratch_hint_text']) ?>" maxlength="40">
            </div>

            <div class="field">
                <label for="scratch_subtext">Scratch Foil Subtitle</label>
                <input type="text" id="scratch_subtext" name="scratch_subtext" value="<?= e($s['scratch_subtext']) ?>" maxlength="60">
            </div>

            <div class="field">
                <label for="scratch_foil_primary">Foil Primary Gold Color</label>
                <input type="color" id="scratch_foil_primary" name="scratch_foil_primary" value="<?= e($s['scratch_foil_primary']) ?>">
            </div>

            <div class="field">
                <label for="scratch_foil_secondary">Foil Shimmer Highlight Color</label>
                <input type="color" id="scratch_foil_secondary" name="scratch_foil_secondary" value="<?= e($s['scratch_foil_secondary']) ?>">
            </div>

            <div class="field">
                <label for="scratch_card_bg">Revealed Card Background</label>
                <input type="color" id="scratch_card_bg" name="scratch_card_bg" value="<?= e($s['scratch_card_bg']) ?>">
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <h2>Automatic Instagram Follow Gate (Strict Mode)</h2>
        </div>

        <div class="form-grid">
            <div class="field">
                <label>Strict Auto-Verify Mode</label>
                <label style="display:flex; align-items:center; gap:8px; font-weight:500; cursor:pointer; padding-top:6px;">
                    <input type="checkbox" name="require_ig_token" value="1" <?= ($s['require_ig_token'] ?? '0') === '1' ? 'checked' : '' ?> style="width:18px; height:18px;">
                    <span>Require Verified Instagram DM Link to Unlock Scratch Card</span>
                </label>
                <small>When ON, visitors CANNOT access the scratch card unless they follow @<?= e($s['instagram_handle']) ?> and get a one-time verified link from your Instagram DM bot (ManyChat / Meta API).</small>
            </div>

            <div class="field">
                <label for="ig_dm_keyword">Instagram DM Keyword</label>
                <input type="text" id="ig_dm_keyword" name="ig_dm_keyword" value="<?= e($s['ig_dm_keyword'] ?? 'KOCHI') ?>" maxlength="24">
                <small>Keyword users DM to @<?= e($s['instagram_handle']) ?> (e.g. KOCHI)</small>
            </div>

            <div class="field">
                <label for="ig_webhook_secret">Webhook Secret Key</label>
                <input type="text" id="ig_webhook_secret" name="ig_webhook_secret" value="<?= e($s['ig_webhook_secret'] ?? '') ?>">
                <small>Send in header <code>X-Webhook-Secret</code> from ManyChat to <code><?= e(get_base_url()) ?>/api/ig_webhook.php</code></small>
            </div>
        </div>
    </div>

    <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:24px;">
        <button type="submit" class="btn btn-primary" style="padding:11px 24px;">Save All Settings</button>
    </div>
</form>

<!-- Generate Test Verified Link -->
<div class="panel">
    <div class="panel-header">
        <h2>Test Auto-Verified Follower Link</h2>
    </div>
    <p style="margin:0 0 12px; font-size:0.85rem; color:var(--muted);">
        Generate a one-time verified unlock link (same link your ManyChat / Meta DM bot automatically sends after confirming <code>is_user_follow_business = true</code>):
    </p>
    <form method="post" action="settings.php" style="display:flex; gap:10px; flex-wrap:wrap;">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="generate_test_token">
        <input
            type="text"
            name="test_ig_username"
            placeholder="e.g. sreeragrm"
            required
            style="flex:1; min-width:200px; padding:9px 12px; border-radius:9px; border:1px solid #cbd5e1; font-family:inherit;"
        >
        <button type="submit" class="btn btn-gold">Generate Verified Link</button>
    </form>
</div>

<!-- Separate Form: Regenerate Unawarded Gift Slots -->
<div class="panel">
    <div class="panel-header">
        <h2>Gift Slot Pool Maintenance</h2>
    </div>
    <p style="margin:0 0 14px; font-size:0.86rem; color:var(--muted);">
        If you changed <strong>Pool Size (N)</strong> or <strong>Total Gifts</strong>, click below to re-select random winning positions for any <em>unawarded</em> gift slots (above the current entry number <?= e($s['entry_counter']) ?>). Already awarded gifts are untouched.
    </p>
    <form
        method="post"
        action="settings.php"
        data-confirm="Regenerate unawarded gift slots using the current Total Gifts and Pool Size (N)?"
    >
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="regenerate_slots">
        <button type="submit" class="btn btn-gold">Regenerate Unawarded Gift Slots</button>
    </form>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
