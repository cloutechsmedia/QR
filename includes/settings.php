<?php
/**
 * Campaign Settings & Gift Slots Manager
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function get_default_settings(): array
{
    return [
        'campaign_active'       => '1',
        'campaign_title'        => 'Techzo Kochi Scratch & Win',
        'total_gifts'           => '3',
        'gift_name'             => 'Bluetooth Speaker',
        'pool_size'             => '100',
        'discount_amount'       => '1000',
        'valid_from'            => '2026-11-30',
        'valid_to'              => '2026-12-05',
        'min_bill_amount'       => '0',
        'campaign_start'        => '2026-11-01 00:00:00',
        'campaign_end'          => '2026-12-05 23:59:59',
        'instagram_handle'      => 'techzo__official',
        'whatsapp_claim_number' => '919999999999',
        'scratch_hint_text'     => 'Scratch Here',
        'scratch_subtext'       => 'Swipe to reveal your Kochi surprise',
        'scratch_foil_primary'  => '#d4a64a',
        'scratch_foil_secondary'=> '#f5d782',
        'scratch_card_bg'       => '#0a3d42',
        'entry_counter'         => '0',
        'require_ig_token'      => '0',
        'ig_dm_keyword'         => 'KOCHI',
        'ig_webhook_secret'     => 'tz_kochi_change_this_secret_98765',
    ];
}

function get_settings(): array
{
    $pdo      = db();
    $defaults = get_default_settings();

    $stmt = $pdo->query('SELECT setting_key, setting_value FROM settings');
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $defaults[$row['setting_key']] = $row['setting_value'];
    }

    return $defaults;
}

function save_settings(array $pairs): void
{
    $pdo  = db();
    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );

    foreach ($pairs as $key => $val) {
        $stmt->execute([
            ':k' => (string) $key,
            ':v' => (string) $val,
        ]);
    }
}

/**
 * Ensure gift_slots table has `total_gifts` pre-selected random entry positions in [1..pool_size].
 * Uses PHP cryptographically secure random_int().
 */
function ensure_gift_slots_initialized(PDO $pdo, int $totalGifts, int $poolSize): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM gift_slots')->fetchColumn();
    if ($count > 0 || $totalGifts <= 0) {
        return;
    }

    $poolSize   = max($poolSize, $totalGifts);
    $chosen     = [];
    $insertStmt = $pdo->prepare('INSERT INTO gift_slots (entry_no, awarded) VALUES (:entry_no, 0)');

    while (count($chosen) < $totalGifts) {
        $pos = random_int(1, $poolSize);
        if (!isset($chosen[$pos])) {
            $chosen[$pos] = true;
            $insertStmt->execute([':entry_no' => $pos]);
        }
    }
}

/**
 * Regenerate only UNAWARDED gift slots (e.g. when admin changes pool_size or total_gifts).
 * Picks random positions strictly greater than the current entry_counter up to pool_size.
 */
function regenerate_unawarded_slots(PDO $pdo, int $totalGifts, int $poolSize): array
{
    $pdo->beginTransaction();
    try {
        // Lock counter
        $counterStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'entry_counter' FOR UPDATE");
        $counterStmt->execute();
        $currentEntry = (int) ($counterStmt->fetchColumn() ?: 0);

        $awardedCount = (int) $pdo->query('SELECT COUNT(*) FROM gift_slots WHERE awarded = 1 FOR UPDATE')->fetchColumn();
        $needed       = max(0, $totalGifts - $awardedCount);

        // Delete existing unawarded slots
        $pdo->exec('DELETE FROM gift_slots WHERE awarded = 0');

        $existingAwarded = $pdo->query('SELECT entry_no FROM gift_slots WHERE awarded = 1')->fetchAll(PDO::FETCH_COLUMN);
        $taken           = array_flip(array_map('intval', $existingAwarded));

        $minPos = $currentEntry + 1;
        $maxPos = max($poolSize, $minPos + $needed - 1);

        $insert = $pdo->prepare('INSERT INTO gift_slots (entry_no, awarded) VALUES (:entry_no, 0)');
        $added  = 0;

        while ($added < $needed) {
            $pos = random_int($minPos, $maxPos);
            if (!isset($taken[$pos])) {
                $taken[$pos] = true;
                $insert->execute([':entry_no' => $pos]);
                $added++;
            }
        }

        $pdo->commit();
        return ['awarded' => $awardedCount, 'regenerated' => $added];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Admin Action: Draw remaining unawarded gifts among existing non-gift participants.
 * Runs inside a DB transaction with row locks so total awarded gifts NEVER exceed total_gifts.
 */
function draw_remaining_gifts(PDO $pdo): array
{
    $pdo->beginTransaction();
    try {
        // Lock settings row to serialize against concurrent plays
        $lock = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'entry_counter' FOR UPDATE");
        $lock->execute();

        $totalGifts = (int) $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'total_gifts'")->fetchColumn();
        if ($totalGifts <= 0) {
            $totalGifts = 3;
        }

        // Lock all gift_slots
        $unawardedSlots = $pdo->query('SELECT id FROM gift_slots WHERE awarded = 0 ORDER BY id ASC FOR UPDATE')->fetchAll();
        $awardedGifts   = (int) $pdo->query("SELECT COUNT(*) FROM participants WHERE result_type = 'gift' AND status != 'revoked' FOR UPDATE")->fetchColumn();

        $remainingToDraw = min(count($unawardedSlots), max(0, $totalGifts - $awardedGifts));
        if ($remainingToDraw <= 0) {
            $pdo->commit();
            return ['drawn' => 0, 'winners' => [], 'message' => 'All gifts have already been awarded!'];
        }

        // Eligible existing participants: discount winners who are not revoked
        $eligible = $pdo->query(
            "SELECT id, entry_no, name, whatsapp, instagram_username
             FROM participants
             WHERE result_type = 'discount' AND status != 'revoked'
             ORDER BY id ASC
             FOR UPDATE"
        )->fetchAll();

        if (empty($eligible)) {
            $pdo->commit();
            return ['drawn' => 0, 'winners' => [], 'message' => 'No eligible participants available for the draw.'];
        }

        $drawCount = min($remainingToDraw, count($eligible));
        $winners   = [];
        $pool      = $eligible;

        $updateParticipant = $pdo->prepare(
            "UPDATE participants
             SET result_type = 'gift', code = :code, status = IF(ig_verified = 1, 'verified', 'pending'), redeemed_at = NULL
             WHERE id = :id"
        );
        $updateSlot = $pdo->prepare(
            'UPDATE gift_slots SET awarded = 1, participant_id = :pid, awarded_at = NOW() WHERE id = :slot_id'
        );

        for ($i = 0; $i < $drawCount; $i++) {
            $randIndex = random_int(0, count($pool) - 1);
            $chosen    = $pool[$randIndex];
            array_splice($pool, $randIndex, 1);

            $giftCode = generate_unique_code($pdo, 'GIFT');
            $updateParticipant->execute([
                ':code' => $giftCode,
                ':id'   => $chosen['id'],
            ]);

            $slotId = $unawardedSlots[$i]['id'];
            $updateSlot->execute([
                ':pid'     => $chosen['id'],
                ':slot_id' => $slotId,
            ]);

            $chosen['code'] = $giftCode;
            $winners[]      = $chosen;
        }

        $pdo->commit();
        return [
            'drawn'   => count($winners),
            'winners' => $winners,
            'message' => count($winners) . ' remaining gift(s) successfully drawn!',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
