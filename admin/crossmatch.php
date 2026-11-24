<?php
/**
 * Admin Instagram Follower Cross-Match Verification
 *
 * Upload official Instagram followers JSON (followers_1.json), CSV/TXT,
 * or paste follower usernames to automatically cross-match and verify participants.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/settings.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

require_admin_auth();

$pdo      = db();
$settings = get_settings();

/**
 * Recursively extract Instagram usernames from Meta's followers_1.json structure.
 */
function extract_handles_from_json(mixed $node, array &$out): void
{
    if (!is_array($node)) {
        return;
    }

    if (isset($node['string_list_data']) && is_array($node['string_list_data'])) {
        foreach ($node['string_list_data'] as $item) {
            if (is_array($item)) {
                $val = $item['value'] ?? $item['href'] ?? '';
                if (is_string($val) && $val !== '') {
                    $norm = normalize_instagram($val);
                    if (is_valid_instagram($norm)) {
                        $out[$norm] = true;
                    }
                }
            }
        }
        return;
    }

    // Also handle simple flat JSON arrays of strings e.g. ["user1", "user2"]
    foreach ($node as $key => $val) {
        if (is_string($val) && !in_array($key, ['title', 'timestamp'], true)) {
            if (str_contains($val, 'instagram.com/') || is_int($key)) {
                $norm = normalize_instagram($val);
                if (is_valid_instagram($norm)) {
                    $out[$norm] = true;
                }
            }
        } elseif (is_array($val)) {
            extract_handles_from_json($val, $out);
        }
    }
}

/**
 * Extract normalized Instagram usernames from plain text / CSV content.
 */
function extract_handles_from_text(string $text, array &$out): void
{
    $tokens = preg_split('/[\r\n,;\t\s]+/', $text) ?: [];
    foreach ($tokens as $tok) {
        $tok = trim($tok, " \t\n\r\0\x0B\"'");
        if ($tok === '') {
            continue;
        }
        $norm = normalize_instagram($tok);
        if (is_valid_instagram($norm)) {
            $out[$norm] = true;
        }
    }
}

$report = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token. Please try again.');
        header('Location: crossmatch.php');
        exit;
    }

    $followerSet = [];
    $parseError  = '';

    // 1. Parse uploaded file(s) if provided (JSON, TXT, CSV — max 10MB each)
    if (!empty($_FILES['follower_files']['name'][0])) {
        $fileCount = count($_FILES['follower_files']['name']);
        for ($i = 0; $i < $fileCount; $i++) {
            $err  = $_FILES['follower_files']['error'][$i];
            $tmp  = $_FILES['follower_files']['tmp_name'][$i];
            $name = (string) $_FILES['follower_files']['name'][$i];
            $size = (int) $_FILES['follower_files']['size'][$i];

            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                $parseError = "Failed to upload file: {$name}";
                break;
            }
            if ($size > 10 * 1024 * 1024) {
                $parseError = "File too large (max 10 MB): {$name}";
                break;
            }

            $content = file_get_contents($tmp);
            if ($content === false) {
                continue;
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext === 'json' || str_starts_with(ltrim($content), '[') || str_starts_with(ltrim($content), '{')) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    extract_handles_from_json($decoded, $followerSet);
                } else {
                    extract_handles_from_text($content, $followerSet);
                }
            } else {
                extract_handles_from_text($content, $followerSet);
            }
        }
    }

    // 2. Parse pasted usernames if provided
    $pasted = trim((string) ($_POST['pasted_usernames'] ?? ''));
    if ($pasted !== '') {
        extract_handles_from_text($pasted, $followerSet);
    }

    if ($parseError !== '') {
        set_flash('error', $parseError);
        header('Location: crossmatch.php');
        exit;
    }

    if (empty($followerSet)) {
        set_flash('error', 'No valid Instagram usernames found. Upload followers_1.json (or CSV/TXT) or paste usernames.');
        header('Location: crossmatch.php');
        exit;
    }

    $syncUnverify = !empty($_POST['unverify_missing']);

    // 3. Cross-match against all participants in DB
    $participants = $pdo->query(
        'SELECT id, entry_no, name, whatsapp, instagram_username, result_type, code, status, ig_verified, created_at
         FROM participants
         ORDER BY result_type ASC, id DESC'
    )->fetchAll();

    $matched        = [];
    $unmatched      = [];
    $newlyVerified  = 0;
    $unverifiedCnt  = 0;

    $verifyStmt = $pdo->prepare(
        "UPDATE participants
         SET ig_verified = 1,
             status = IF(status = 'pending', 'verified', status)
         WHERE id = :id"
    );
    $unverifyStmt = $pdo->prepare(
        "UPDATE participants
         SET ig_verified = 0,
             status = IF(status = 'verified', 'pending', status)
         WHERE id = :id"
    );

    $pdo->beginTransaction();
    try {
        foreach ($participants as $p) {
            $handle = strtolower((string) $p['instagram_username']);
            if (isset($followerSet[$handle])) {
                if ((int) $p['ig_verified'] === 0 && $p['status'] !== 'revoked') {
                    $verifyStmt->execute([':id' => $p['id']]);
                    $p['ig_verified'] = 1;
                    if ($p['status'] === 'pending') {
                        $p['status'] = 'verified';
                    }
                    $p['_newly_verified'] = true;
                    $newlyVerified++;
                } else {
                    $p['_newly_verified'] = false;
                }
                $matched[] = $p;
            } else {
                if ($syncUnverify && (int) $p['ig_verified'] === 1 && !in_array($p['status'], ['redeemed', 'delivered', 'revoked'], true)) {
                    $unverifyStmt->execute([':id' => $p['id']]);
                    $p['ig_verified'] = 0;
                    if ($p['status'] === 'verified') {
                        $p['status'] = 'pending';
                    }
                    $unverifiedCnt++;
                }
                $unmatched[] = $p;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Cross-match error: ' . $e->getMessage());
        header('Location: crossmatch.php');
        exit;
    }

    $report = [
        'imported_count' => count($followerSet),
        'total_players'  => count($participants),
        'matched'        => $matched,
        'unmatched'      => $unmatched,
        'newly_verified' => $newlyVerified,
        'unverified_cnt' => $unverifiedCnt,
    ];
}

$pageTitle = 'Instagram Cross-Match';
require __DIR__ . '/_header.php';
?>

<div class="page-header">
    <div>
        <h1>Instagram Follower Cross-Match</h1>
        <p>Automatically verify which participants actually follow <strong>@<?= e($settings['instagram_handle']) ?></strong> — 100% safe, no scraping.</p>
    </div>
    <a href="participants.php" class="btn btn-outline">Back to Participants</a>
</div>

<!-- Upload / Paste Form -->
<div class="panel">
    <div class="panel-header">
        <h2>Import Your Followers List</h2>
    </div>

    <form method="post" action="crossmatch.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

        <div class="two-col" style="margin-bottom: 16px;">
            <div class="field">
                <label for="follower_files">Option A: Upload Instagram Followers File(s)</label>
                <input
                    type="file"
                    id="follower_files"
                    name="follower_files[]"
                    accept=".json,.txt,.csv"
                    multiple
                >
                <small>
                    Supports Meta's official <code>followers_1.json</code> (and <code>followers_2.json</code>, etc.), <code>.csv</code>, or <code>.txt</code>.
                    <br><strong>How to get it:</strong> Instagram → Settings → Accounts Center → Your information and permissions → Download your information → Select <em>Followers and following</em> (JSON format).
                </small>
            </div>

            <div class="field">
                <label for="pasted_usernames">Option B: Paste Follower Usernames</label>
                <textarea
                    id="pasted_usernames"
                    name="pasted_usernames"
                    rows="5"
                    placeholder="arjun_kochi&#10;@meera.nair&#10;https://www.instagram.com/rahul_k"
                ></textarea>
                <small>Paste one per line or comma-separated (handles, @usernames, or Instagram URLs all work).</small>
            </div>
        </div>

        <div style="margin-bottom: 16px;">
            <label style="display:inline-flex; align-items:center; gap:8px; font-size:0.84rem; cursor:pointer; color:var(--muted);">
                <input type="checkbox" name="unverify_missing" value="1" style="width:16px; height:16px;">
                <span>Full-list sync: also mark previously verified (unredeemed) users as unverified if they are missing from this list</span>
            </label>
        </div>

        <button type="submit" class="btn btn-gold" style="padding:11px 22px;">
            Run Auto Cross-Match &amp; Verify
        </button>
    </form>
</div>

<?php if ($report !== null): ?>
    <!-- Cross-Match Summary KPIs -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Followers Imported</div>
            <div class="stat-value"><?= e(number_format($report['imported_count'])) ?></div>
            <div class="stat-sub">Unique Instagram handles parsed</div>
        </div>
        <div class="stat-card highlight">
            <div class="stat-label">Matched (Following ✓)</div>
            <div class="stat-value"><?= e(count($report['matched'])) ?> / <?= e($report['total_players']) ?></div>
            <div class="stat-sub"><?= e($report['newly_verified']) ?> newly verified just now</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Not Found in List (✗)</div>
            <div class="stat-value" style="color:var(--danger);"><?= e(count($report['unmatched'])) ?></div>
            <div class="stat-sub">Did not follow or username mismatch</div>
        </div>
    </div>

    <!-- Unmatched / Not Following Table (Shown first so admin can spot non-followers fast) -->
    <div class="panel">
        <div class="panel-header">
            <h2>Not Following / Unmatched Participants (<?= e(count($report['unmatched'])) ?>)</h2>
            <span class="badge badge-revoked">Needs Check</span>
        </div>
        <?php if (empty($report['unmatched'])): ?>
            <p style="margin:0; color:var(--success); font-weight:600;">Every single participant is following @<?= e($settings['instagram_handle']) ?>!</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Instagram</th>
                            <th>WhatsApp</th>
                            <th>Result</th>
                            <th>Code</th>
                            <th>Status</th>
                            <th>Check</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report['unmatched'] as $u): ?>
                            <tr>
                                <td>#<?= e($u['entry_no']) ?></td>
                                <td><strong><?= e($u['name']) ?></strong></td>
                                <td>
                                    <a href="https://www.instagram.com/<?= e(rawurlencode($u['instagram_username'])) ?>" target="_blank" rel="noopener noreferrer">
                                        @<?= e($u['instagram_username']) ?>
                                    </a>
                                </td>
                                <td><?= e($u['whatsapp']) ?></td>
                                <td>
                                    <span class="badge badge-<?= e($u['result_type']) ?>">
                                        <?= $u['result_type'] === 'gift' ? e($settings['gift_name']) : 'Discount' ?>
                                    </span>
                                </td>
                                <td><span class="code-pill"><?= e($u['code']) ?></span></td>
                                <td><span class="badge badge-<?= e($u['status']) ?>"><?= e(ucfirst($u['status'])) ?></span></td>
                                <td>
                                    <a href="participants.php?q=<?= e(rawurlencode($u['code'])) ?>" class="btn btn-outline btn-sm">Manage</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Matched / Verified Followers Table -->
    <div class="panel">
        <div class="panel-header">
            <h2>Matched &amp; Verified Followers (<?= e(count($report['matched'])) ?>)</h2>
            <span class="badge badge-redeemed">IG Verified ✓</span>
        </div>
        <?php if (empty($report['matched'])): ?>
            <p style="margin:0; color:var(--muted);">No participants matched the imported list.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Instagram</th>
                            <th>Result</th>
                            <th>Code</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report['matched'] as $m): ?>
                            <tr>
                                <td>#<?= e($m['entry_no']) ?></td>
                                <td><strong><?= e($m['name']) ?></strong></td>
                                <td>
                                    @<?= e($m['instagram_username']) ?>
                                    <span style="color:var(--success); font-weight:700;">✓</span>
                                    <?php if (!empty($m['_newly_verified'])): ?>
                                        <span class="badge badge-verified" style="margin-left:4px;">NEW</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= e($m['result_type']) ?>">
                                        <?= $m['result_type'] === 'gift' ? e($settings['gift_name']) : 'Discount' ?>
                                    </span>
                                </td>
                                <td><span class="code-pill"><?= e($m['code']) ?></span></td>
                                <td><span class="badge badge-<?= e($m['status']) ?>"><?= e(ucfirst($m['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
