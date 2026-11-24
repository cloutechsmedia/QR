<?php
/**
 * Admin Participants Management: Search, Filters, Actions & CSV Export
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/settings.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

require_admin_auth();

$pdo      = db();
$settings = get_settings();

// Handle Row Actions (POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token.');
        header('Location: participants.php');
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $pid    = (int) ($_POST['participant_id'] ?? 0);

    if ($pid > 0) {
        $stmt = $pdo->prepare('SELECT * FROM participants WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $pid]);
        $participant = $stmt->fetch();

        if ($participant) {
            if ($action === 'verify_ig') {
                $newStatus = ($participant['status'] === 'pending') ? 'verified' : $participant['status'];
                $upd = $pdo->prepare('UPDATE participants SET ig_verified = 1, status = :st WHERE id = :id');
                $upd->execute([':st' => $newStatus, ':id' => $pid]);
                set_flash('success', "Marked @{$participant['instagram_username']} as Instagram verified.");
            } elseif ($action === 'mark_redeemed') {
                if ($participant['status'] === 'revoked') {
                    set_flash('error', 'Cannot redeem a revoked code.');
                } else {
                    $upd = $pdo->prepare("UPDATE participants SET status = 'redeemed', redeemed_at = NOW() WHERE id = :id");
                    $upd->execute([':id' => $pid]);
                    set_flash('success', "Coupon {$participant['code']} marked as Redeemed.");
                }
            } elseif ($action === 'mark_delivered') {
                if ($participant['result_type'] !== 'gift') {
                    set_flash('error', 'Only gift winners can be marked as delivered.');
                } elseif ($participant['status'] === 'revoked') {
                    set_flash('error', 'Cannot deliver a revoked gift.');
                } else {
                    $upd = $pdo->prepare("UPDATE participants SET status = 'delivered', delivered_at = NOW(), ig_verified = 1 WHERE id = :id");
                    $upd->execute([':id' => $pid]);
                    set_flash('success', "Gift {$participant['code']} marked as Delivered.");
                }
            } elseif ($action === 'revoke_gift') {
                if ($participant['result_type'] === 'gift') {
                    $pdo->beginTransaction();
                    try {
                        $upd = $pdo->prepare("UPDATE participants SET status = 'revoked' WHERE id = :id");
                        $upd->execute([':id' => $pid]);

                        // Free the gift slot so it can be re-drawn if desired
                        $freeSlot = $pdo->prepare('UPDATE gift_slots SET awarded = 0, participant_id = NULL, awarded_at = NULL WHERE participant_id = :pid');
                        $freeSlot->execute([':pid' => $pid]);

                        $pdo->commit();
                        set_flash('info', "Gift {$participant['code']} for {$participant['name']} has been revoked and the gift slot was released.");
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        set_flash('error', 'Failed to revoke gift: ' . $e->getMessage());
                    }
                }
            }
        }
    }

    // Preserve current query string on redirect
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: participants.php' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

// Filters & Search
$search     = trim((string) ($_GET['q'] ?? ''));
$resultType = (string) ($_GET['result_type'] ?? '');
$status     = (string) ($_GET['status'] ?? '');
$export     = (string) ($_GET['export'] ?? '');

$where  = [];
$params = [];

if ($search !== '') {
    $where[]      = '(name LIKE :q1 OR whatsapp LIKE :q2 OR instagram_username LIKE :q3 OR code LIKE :q4)';
    $like         = '%' . $search . '%';
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
    $params[':q4'] = $like;
}
if (in_array($resultType, ['gift', 'discount'], true)) {
    $where[]               = 'result_type = :result_type';
    $params[':result_type'] = $resultType;
}
if (in_array($status, ['pending', 'verified', 'redeemed', 'delivered', 'revoked'], true)) {
    $where[]          = 'status = :status';
    $params[':status'] = $status;
}

$whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

// CSV Export
if ($export === 'csv') {
    $stmt = $pdo->prepare(
        "SELECT entry_no, name, whatsapp, instagram_username, result_type, code, status, ig_verified, ip, created_at, redeemed_at, delivered_at
         FROM participants
         {$whereSql}
         ORDER BY id DESC"
    );
    $stmt->execute($params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="techzo-kochi-participants-' . date('Ymd-His') . '.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM for clean Excel Malayalam/Unicode opening
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Entry #', 'Name', 'WhatsApp', 'Instagram Username', 'Result',
        'Code', 'Status', 'IG Verified', 'IP Address', 'Created At', 'Redeemed At', 'Delivered At'
    ]);

    while ($row = $stmt->fetch()) {
        fputcsv($out, [
            $row['entry_no'],
            csv_safe((string) $row['name']),
            csv_safe((string) $row['whatsapp']),
            csv_safe('@' . $row['instagram_username']),
            $row['result_type'] === 'gift' ? 'Gift Winner' : 'Discount Coupon',
            csv_safe((string) $row['code']),
            ucfirst((string) $row['status']),
            $row['ig_verified'] ? 'Yes' : 'No',
            $row['ip'],
            $row['created_at'],
            $row['redeemed_at'] ?? '',
            $row['delivered_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// Pagination
$perPage = 25;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM participants {$whereSql}");
$countStmt->execute($params);
$totalRows  = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

$listStmt = $pdo->prepare(
    "SELECT * FROM participants {$whereSql} ORDER BY id DESC LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$rows = $listStmt->fetchAll();

$exportQuery = http_build_query(array_filter([
    'q'           => $search,
    'result_type' => $resultType,
    'status'      => $status,
    'export'      => 'csv',
]));

$pageTitle = 'Participants';
require __DIR__ . '/_header.php';
?>

<div class="page-header">
    <div>
        <h1>Participants (<?= e(number_format($totalRows)) ?>)</h1>
        <p>Search, verify Instagram follows, mark redemptions/deliveries, or export CSV.</p>
    </div>
    <a href="participants.php?<?= e($exportQuery) ?>" class="btn btn-gold">Export Filtered CSV</a>
</div>

<div class="panel">
    <form method="get" action="participants.php" class="filter-bar">
        <input
            type="search"
            name="q"
            value="<?= e($search) ?>"
            placeholder="Search name, WhatsApp, @instagram, or code..."
        >
        <select name="result_type">
            <option value="">All Results</option>
            <option value="gift" <?= $resultType === 'gift' ? 'selected' : '' ?>>Gift Winners</option>
            <option value="discount" <?= $resultType === 'discount' ? 'selected' : '' ?>>Discount Coupons</option>
        </select>
        <select name="status">
            <option value="">All Statuses</option>
            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="verified" <?= $status === 'verified' ? 'selected' : '' ?>>Verified</option>
            <option value="redeemed" <?= $status === 'redeemed' ? 'selected' : '' ?>>Redeemed</option>
            <option value="delivered" <?= $status === 'delivered' ? 'selected' : '' ?>>Delivered</option>
            <option value="revoked" <?= $status === 'revoked' ? 'selected' : '' ?>>Revoked</option>
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($search !== '' || $resultType !== '' || $status !== ''): ?>
            <a href="participants.php" class="btn btn-outline">Reset</a>
        <?php endif; ?>
    </form>

    <?php if (empty($rows)): ?>
        <p style="margin:12px 0 0; color:var(--muted);">No matching participants found.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Participant</th>
                        <th>WhatsApp</th>
                        <th>Instagram</th>
                        <th>Result</th>
                        <th>Code</th>
                        <th>Status</th>
                        <th>Time</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td>#<?= e($r['entry_no']) ?></td>
                            <td><strong><?= e($r['name']) ?></strong></td>
                            <td>
                                <a href="https://wa.me/<?= e($r['whatsapp']) ?>" target="_blank" rel="noopener noreferrer">
                                    <?= e($r['whatsapp']) ?>
                                </a>
                            </td>
                            <td>
                                <a href="https://www.instagram.com/<?= e(rawurlencode($r['instagram_username'])) ?>" target="_blank" rel="noopener noreferrer">
                                    @<?= e($r['instagram_username']) ?>
                                </a>
                                <?php if ($r['ig_verified']): ?>
                                    <span title="Instagram Follow Verified" style="color:var(--success); font-weight:700;">✓</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?= e($r['result_type']) ?>">
                                    <?= $r['result_type'] === 'gift' ? e($settings['gift_name']) : ('Rs. ' . e($settings['discount_amount'])) ?>
                                </span>
                            </td>
                            <td><span class="code-pill"><?= e($r['code']) ?></span></td>
                            <td>
                                <span class="badge badge-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span>
                            </td>
                            <td style="white-space:nowrap; font-size:0.78rem; color:var(--muted);"><?= e($r['created_at']) ?></td>
                            <td>
                                <div class="action-stack">
                                    <?php if (!$r['ig_verified'] && $r['status'] !== 'revoked'): ?>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="participant_id" value="<?= e($r['id']) ?>">
                                            <input type="hidden" name="action" value="verify_ig">
                                            <button type="submit" class="btn btn-outline btn-sm">Verify IG</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($r['result_type'] === 'discount' && !in_array($r['status'], ['redeemed', 'revoked'], true)): ?>
                                        <form method="post" data-confirm="Mark coupon <?= e($r['code']) ?> as Redeemed?">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="participant_id" value="<?= e($r['id']) ?>">
                                            <input type="hidden" name="action" value="mark_redeemed">
                                            <button type="submit" class="btn btn-success btn-sm">Redeem</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($r['result_type'] === 'gift' && !in_array($r['status'], ['delivered', 'revoked'], true)): ?>
                                        <form method="post" data-confirm="Mark gift <?= e($r['code']) ?> as Delivered to <?= e($r['name']) ?>?">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="participant_id" value="<?= e($r['id']) ?>">
                                            <input type="hidden" name="action" value="mark_delivered">
                                            <button type="submit" class="btn btn-success btn-sm">Delivered</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($r['result_type'] === 'gift' && $r['status'] !== 'revoked'): ?>
                                        <form method="post" data-confirm="REVOKE gift <?= e($r['code']) ?> from <?= e($r['name']) ?>? This will invalidate their gift code and release the gift slot.">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="participant_id" value="<?= e($r['id']) ?>">
                                            <input type="hidden" name="action" value="revoke_gift">
                                            <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php
                    $pq = http_build_query(array_filter([
                        'q'           => $search,
                        'result_type' => $resultType,
                        'status'      => $status,
                        'page'        => $i,
                    ]));
                    ?>
                    <a
                        href="participants.php?<?= e($pq) ?>"
                        class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-outline' ?>"
                    ><?= $i ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
