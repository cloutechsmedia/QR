<?php
/**
 * Techzo Kochi Scratch & Win - Public Campaign Page
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/helpers.php';

init_secure_session();

$settings      = get_settings();
$csrf          = csrf_token();
$openStatus    = check_campaign_open($settings);
$baseUrl       = get_base_url();

$campaignTitle = $settings['campaign_title'] ?? 'Techzo Kochi Scratch & Win';
$igHandle      = ltrim($settings['instagram_handle'] ?? 'techzo__official', '@');
$igUrl         = 'https://www.instagram.com/' . rawurlencode($igHandle);
$discountAmt   = (int) ($settings['discount_amount'] ?? 1000);
$totalGifts    = (int) ($settings['total_gifts'] ?? 3);
$giftName      = $settings['gift_name'] ?? 'Bluetooth Speaker';
$validFrom     = $settings['valid_from'] ?? '2026-11-30';
$validTo       = $settings['valid_to'] ?? '2026-12-05';
$validityShort = format_validity_range($validFrom, $validTo, false);
$validityFull  = format_validity_range($validFrom, $validTo, true);

$scratchHint   = $settings['scratch_hint_text'] ?? 'Scratch Here';
$scratchSub    = $settings['scratch_subtext'] ?? 'Swipe to reveal your Kochi surprise';
$foilPrimary   = preg_match('/^#[0-9a-fA-F]{6}$/', $settings['scratch_foil_primary'] ?? '') ? $settings['scratch_foil_primary'] : '#d4a64a';
$foilSecondary = preg_match('/^#[0-9a-fA-F]{6}$/', $settings['scratch_foil_secondary'] ?? '') ? $settings['scratch_foil_secondary'] : '#f5d782';
$cardBg        = preg_match('/^#[0-9a-fA-F]{6}$/', $settings['scratch_card_bg'] ?? '') ? $settings['scratch_card_bg'] : '#0a3d42';
$alreadyPlayed = !empty($_SESSION['has_played']);

// Strict Auto-Verify Instagram DM Token Mode
$requireIgToken = (($settings['require_ig_token'] ?? '0') === '1');
$igDmKeyword    = (string) ($settings['ig_dm_keyword'] ?? 'KOCHI');
$igDmMobileUrl  = 'https://ig.me/m/' . rawurlencode($igHandle) . '?text=' . rawurlencode($igDmKeyword);
$igDmDesktopUrl = 'https://www.instagram.com/' . rawurlencode($igHandle) . '/';
$urlToken       = trim((string) ($_GET['token'] ?? ''));
$verifiedToken  = '';
$verifiedIgUser = '';
$tokenError     = '';

// Direct ManyChat Free-Plan Verified Redirect: /?v=<ig_webhook_secret>&u=<instagram_username>
$verifySecretParam = trim((string) ($_GET['v'] ?? ''));
$verifyUserParam   = normalize_instagram((string) ($_GET['u'] ?? ''));
$expectedSecret    = (string) ($settings['ig_webhook_secret'] ?? '');

if ($verifySecretParam !== '' && $expectedSecret !== '' && hash_equals($expectedSecret, $verifySecretParam)) {
    // Strip secret from URL immediately by issuing a one-time token and redirecting
    $oneTimeTok = bin2hex(random_bytes(20));
    $igForTok   = is_valid_instagram($verifyUserParam) ? $verifyUserParam : 'ig_follower_' . substr($oneTimeTok, 0, 8);

    $insTok = db()->prepare(
        'INSERT INTO ig_verify_tokens (token, instagram_username, used, expires_at)
         VALUES (:token, :ig, 0, DATE_ADD(NOW(), INTERVAL 24 HOUR))'
    );
    $insTok->execute([
        ':token' => $oneTimeTok,
        ':ig'    => $igForTok,
    ]);

    header('Location: index.php?token=' . rawurlencode($oneTimeTok));
    exit;
}

if ($urlToken !== '') {
    $tokStmt = db()->prepare(
        'SELECT token, instagram_username, used, (expires_at > NOW()) AS not_expired
         FROM ig_verify_tokens
         WHERE token = :token
         LIMIT 1'
    );
    $tokStmt->execute([':token' => $urlToken]);
    $tokRow = $tokStmt->fetch();

    if ($tokRow && (int) $tokRow['used'] === 0 && (int) $tokRow['not_expired'] === 1) {
        $verifiedToken  = (string) $tokRow['token'];
        $verifiedIgUser = (string) $tokRow['instagram_username'];
    } else {
        $tokenError = 'This verification link has expired or was already used. Please DM "' . $igDmKeyword . '" to @' . $igHandle . ' on Instagram for a fresh link.';
    }
}

$ogImage = $baseUrl . '/assets/img/og-image.svg';
$pageDesc = "Everyone wins! Follow @{$igHandle} and scratch your digital card for a flat Rs. {$discountAmt} discount (valid {$validityShort}) or win 1 of {$totalGifts} {$giftName}s!";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#06292d">
    <title><?= e($campaignTitle) ?> | Everyone Wins!</title>
    <meta name="description" content="<?= e($pageDesc) ?>">
    <meta name="robots" content="index, follow">

    <!-- Open Graph / WhatsApp / Instagram Preview -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= e($campaignTitle) ?>">
    <meta property="og:description" content="<?= e($pageDesc) ?>">
    <meta property="og:url" content="<?= e($baseUrl) ?>">
    <meta property="og:image" content="<?= e($ogImage) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($campaignTitle) ?>">
    <meta name="twitter:description" content="<?= e($pageDesc) ?>">
    <meta name="twitter:image" content="<?= e($ogImage) ?>">

    <!-- Google Fonts: Poppins + Noto Sans Malayalam -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Malayalam:wght@500;700&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        :root {
            --card-bg-custom: <?= e($cardBg) ?>;
            --foil-primary: <?= e($foilPrimary) ?>;
            --foil-secondary: <?= e($foilSecondary) ?>;
        }
    </style>
</head>
<body>

    <!-- ============================================================
         1. KOCHI LOADING SCREEN
         ============================================================ -->
    <div id="kochi-loader" class="kochi-loader" aria-live="polite" aria-label="Loading Techzo Kochi Scratch & Win">
        <div class="loader-stars" aria-hidden="true"></div>

        <div class="loader-center">
            <div class="loader-badge">TECHZO • KOCHI</div>
            <h1 class="loader-title">Kochi Loading</h1>
            <p class="loader-ml" lang="ml">കൊച്ചി ലോഡിംഗ്...</p>

            <div class="loader-progress-wrap">
                <div class="loader-progress-track">
                    <div id="loader-bar" class="loader-progress-bar"></div>
                </div>
                <div id="loader-percent" class="loader-percent">0%</div>
            </div>
        </div>

        <!-- Kochi SVG Scene: Marine Drive Skyline, Chinese Fishing Net, Vypin Ferry, Waves -->
        <div class="kochi-scene" aria-hidden="true">
            <svg viewBox="0 0 800 320" preserveAspectRatio="xMidYMax slice" class="kochi-svg">
                <defs>
                    <linearGradient id="sunsetGlow" x1="0%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stop-color="#f5d782" stop-opacity="0.32"/>
                        <stop offset="100%" stop-color="#06292d" stop-opacity="0"/>
                    </linearGradient>
                    <linearGradient id="goldStroke" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#f7df94"/>
                        <stop offset="100%" stop-color="#c6922c"/>
                    </linearGradient>
                </defs>

                <!-- Warm Twilight Sun Glow -->
                <circle cx="400" cy="200" r="140" fill="url(#sunsetGlow)" class="svg-sun"/>

                <!-- Marine Drive Skyline Silhouette -->
                <g class="skyline" fill="#041e22" opacity="0.88">
                    <rect x="40" y="168" width="24" height="65" rx="2"/>
                    <rect x="70" y="146" width="32" height="87" rx="2"/>
                    <rect x="108" y="160" width="22" height="73" rx="2"/>
                    <rect x="138" y="132" width="38" height="101" rx="2"/>
                    <rect x="184" y="155" width="26" height="78" rx="2"/>
                    <rect x="218" y="175" width="42" height="58" rx="2"/>
                    <!-- Rainbow Bridge (Marine Drive iconic arch) -->
                    <path d="M275 225 Q340 165 405 225" fill="none" stroke="#d4a64a" stroke-opacity="0.45" stroke-width="3"/>
                    <path d="M285 225 Q340 176 395 225" fill="none" stroke="#d4a64a" stroke-opacity="0.25" stroke-width="2"/>
                    <!-- Palm silhouettes -->
                    <path d="M445 230 Q448 190 456 170" stroke="#041e22" stroke-width="4" fill="none"/>
                    <path d="M456 170 Q435 162 424 176 M456 170 Q448 152 465 150 M456 170 Q474 162 484 178" stroke="#041e22" stroke-width="3" fill="none"/>
                </g>

                <!-- Animated Chinese Fishing Net (Cheena Vala) -->
                <g id="fishing-net-group" class="fishing-net">
                    <!-- Shore support poles -->
                    <line x1="720" y1="245" x2="645" y2="115" stroke="url(#goldStroke)" stroke-width="5" stroke-linecap="round"/>
                    <line x1="760" y1="245" x2="645" y2="115" stroke="#c6922c" stroke-width="3.5" stroke-linecap="round" opacity="0.8"/>
                    <!-- Pivoting boom & net (lifts at 100%) -->
                    <g id="net-arm" style="transform-origin: 645px 115px;">
                        <line x1="645" y1="115" x2="505" y2="178" stroke="url(#goldStroke)" stroke-width="4" stroke-linecap="round"/>
                        <line x1="645" y1="115" x2="735" y2="148" stroke="#d4a64a" stroke-width="3" stroke-linecap="round"/>
                        <!-- Counterweight stones -->
                        <line x1="735" y1="148" x2="735" y2="176" stroke="#f5d782" stroke-width="1.5" stroke-dasharray="3 2"/>
                        <circle cx="735" cy="182" r="7" fill="#d4a64a"/>
                        <!-- Suspended net mesh -->
                        <path d="M505 178 Q565 245 628 212 Q570 190 505 178 Z" fill="#f5d782" fill-opacity="0.16" stroke="#f5d782" stroke-width="2"/>
                        <line x1="532" y1="175" x2="558" y2="220" stroke="#f5d782" stroke-width="1.2" opacity="0.65"/>
                        <line x1="562" y1="168" x2="588" y2="222" stroke="#f5d782" stroke-width="1.2" opacity="0.65"/>
                        <line x1="590" y1="162" x2="612" y2="215" stroke="#f5d782" stroke-width="1.2" opacity="0.65"/>
                    </g>
                </g>

                <!-- Vypin Ferry / Backwater Boat -->
                <g id="kochi-boat" class="kochi-boat">
                    <!-- Boat hull -->
                    <path d="M120 248 L226 248 L214 264 L132 264 Z" fill="#f5d782"/>
                    <path d="M126 254 L220 254" stroke="#06292d" stroke-width="2"/>
                    <!-- Ferry cabin -->
                    <rect x="142" y="229" width="62" height="19" rx="3" fill="#0d4f55" stroke="#f5d782" stroke-width="1.5"/>
                    <!-- Cabin windows -->
                    <rect x="149" y="234" width="10" height="7" rx="1" fill="#f7df94"/>
                    <rect x="164" y="234" width="10" height="7" rx="1" fill="#f7df94"/>
                    <rect x="179" y="234" width="10" height="7" rx="1" fill="#f7df94"/>
                    <rect x="193" y="234" width="7" height="7" rx="1" fill="#f7df94"/>
                    <!-- Roof mast -->
                    <line x1="172" y1="229" x2="172" y2="218" stroke="#f5d782" stroke-width="1.5"/>
                    <circle cx="172" cy="217" r="2.2" fill="#ffdf80"/>
                </g>

                <!-- Backwater Waves -->
                <path class="wave wave-back" d="M-40 255 Q60 242 160 255 T360 255 T560 255 T760 255 T960 255 L960 320 L-40 320 Z" fill="#09383d"/>
                <path class="wave wave-mid" d="M-40 272 Q70 258 180 272 T400 272 T620 272 T840 272 L840 320 L-40 320 Z" fill="#0d4a50" opacity="0.88"/>
                <path class="wave wave-front" d="M-40 290 Q80 278 200 290 T440 290 T680 290 T920 290 L920 320 L-40 320 Z" fill="#052125"/>
            </svg>
        </div>

        <!-- Wave Wipe Curtain -->
        <div id="wave-wipe" class="wave-wipe" aria-hidden="true"></div>
    </div>

    <!-- ============================================================
         MAIN CAMPAIGN EXPERIENCE
         ============================================================ -->
    <div class="page-shell">
        <div class="kasavu-strip" aria-hidden="true"></div>

        <header class="site-header">
            <div class="brand-pill">
                <span class="brand-dot" aria-hidden="true"></span>
                <span>TECHZO KOCHI</span>
            </div>
            <h1 class="hero-title">Scratch &amp; Win</h1>
            <p class="hero-ml" lang="ml">കൊച്ചിയുടെ സർപ്രൈസ് സമ്മാനം</p>
        </header>

        <main class="main-container">
            <!-- Campaign Promise Banner -->
            <section class="promise-banner" aria-label="Campaign highlights">
                <div class="promise-icon" aria-hidden="true">✦</div>
                <p>
                    <strong>Everyone wins!</strong> Rs. <?= e(number_format($discountAmt)) ?> discount valid only from
                    <strong><?= e($validityShort) ?></strong>.
                    <strong><?= e($totalGifts) ?> lucky people</strong> win a <strong><?= e($giftName) ?></strong>.
                </p>
            </section>

            <?php if (!$openStatus['open']): ?>
                <section class="card card-closed">
                    <div class="closed-icon" aria-hidden="true">⚓</div>
                    <h2>Campaign Notice</h2>
                    <p><?= e($openStatus['reason']) ?></p>
                    <a class="btn btn-gold" href="<?= e($igUrl) ?>" target="_blank" rel="noopener noreferrer">
                        Visit @<?= e($igHandle) ?> on Instagram
                    </a>
                </section>
            <?php elseif ($alreadyPlayed): ?>
                <section class="card card-closed">
                    <div class="closed-icon" aria-hidden="true">🎉</div>
                    <h2>Already Participated!</h2>
                    <p>You've already unlocked your Kochi scratch card on this device. Each participant gets one chance to win.</p>
                    <a class="btn btn-gold" href="<?= e($igUrl) ?>" target="_blank" rel="noopener noreferrer">
                        Follow @<?= e($igHandle) ?> for Updates
                    </a>
                </section>
            <?php else: ?>
                <!-- ====================================================
                     2. RULES SCREEN & ENTRY FORM
                     ==================================================== -->
                <section id="step-rules" class="card rules-card" aria-labelledby="rule-heading">
                    <div class="step-badge">STEP 1 OF 2</div>
                    <h2 id="rule-heading" class="card-title">Rule No. 1</h2>

                    <?php if ($requireIgToken && $verifiedToken === ''): ?>
                        <p class="rule-desc">
                            Follow <a href="<?= e($igUrl) ?>" target="_blank" rel="noopener noreferrer" class="ig-handle-link">@<?= e($igHandle) ?></a>
                            on Instagram, then send <strong>"<?= e($igDmKeyword) ?>"</strong> in DM.
                            Our system automatically verifies your follow and sends your personal Scratch Card link!
                        </p>

                        <?php if ($tokenError !== ''): ?>
                            <div class="alert-box" role="alert"><?= e($tokenError) ?></div>
                        <?php endif; ?>

                        <div class="follow-action-wrap" style="display:flex; flex-direction:column; gap:12px;">
                            <a
                                href="<?= e($igUrl) ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="btn btn-instagram"
                            >
                                <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <rect x="2" y="2" width="20" height="20" rx="5"></rect>
                                    <circle cx="12" cy="12" r="4"></circle>
                                    <circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"></circle>
                                </svg>
                                <span>1. Follow @<?= e($igHandle) ?></span>
                            </a>

                            <a
                                id="btn-dm-ig"
                                href="<?= e($igDmMobileUrl) ?>"
                                data-mobile-url="<?= e($igDmMobileUrl) ?>"
                                data-desktop-url="<?= e($igDmDesktopUrl) ?>"
                                data-keyword="<?= e($igDmKeyword) ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="btn btn-gold btn-full"
                            >
                                <span>2. DM "<?= e($igDmKeyword) ?>" to Auto-Verify &amp; Unlock</span>
                            </a>

                            <p class="micro-hint">
                                🔒 Scratch card unlocks automatically only for verified followers of @<?= e($igHandle) ?>.
                            </p>
                        </div>
                    <?php else: ?>
                        <?php if ($verifiedToken !== ''): ?>
                            <div class="alert-box is-info" style="border-color: rgba(74, 222, 128, 0.55); background: rgba(74, 222, 128, 0.14); color: #dcfce7;">
                                ✓ <strong>Instagram Follow Auto-Verified</strong> for <strong>@<?= e($verifiedIgUser) ?></strong>! Enter your name &amp; WhatsApp below to scratch your card.
                            </div>
                        <?php else: ?>
                            <p class="rule-desc">
                                Follow our Instagram account
                                <a href="<?= e($igUrl) ?>" target="_blank" rel="noopener noreferrer" class="ig-handle-link">@<?= e($igHandle) ?></a>
                                to unlock your scratch card.
                            </p>

                            <div class="follow-action-wrap">
                                <a
                                    id="btn-follow-ig"
                                    href="<?= e($igUrl) ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="btn btn-instagram"
                                >
                                    <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <rect x="2" y="2" width="20" height="20" rx="5"></rect>
                                        <circle cx="12" cy="12" r="4"></circle>
                                        <circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"></circle>
                                    </svg>
                                    <span>Follow on Instagram</span>
                                </a>
                                <p id="follow-hint" class="micro-hint">Opens @<?= e($igHandle) ?> in a new tab. Come right back here after following!</p>
                            </div>
                        <?php endif; ?>

                        <!-- Entry Form -->
                        <form id="entry-form" class="entry-form <?= $verifiedToken !== '' ? '' : 'is-hidden' ?>" novalidate>
                            <div class="form-divider">
                                <span>Enter your details to unlock</span>
                            </div>

                            <div id="form-alert" class="alert-box is-hidden" role="alert"></div>

                            <div class="form-group">
                                <label for="input-name">Your Full Name</label>
                                <input
                                    type="text"
                                    id="input-name"
                                    name="name"
                                    placeholder="e.g. Arjun Nair"
                                    autocomplete="name"
                                    maxlength="120"
                                    required
                                >
                                <small class="field-error" data-error-for="name"></small>
                            </div>

                            <div class="form-group">
                                <label for="input-whatsapp">WhatsApp Number</label>
                                <div class="input-prefix-wrap">
                                    <span class="input-prefix">+91</span>
                                    <input
                                        type="tel"
                                        id="input-whatsapp"
                                        name="whatsapp"
                                        placeholder="98765 43210"
                                        inputmode="numeric"
                                        autocomplete="tel"
                                        maxlength="15"
                                        required
                                    >
                                </div>
                                <small class="field-error" data-error-for="whatsapp"></small>
                            </div>

                            <div class="form-group">
                                <label for="input-instagram">Your Instagram Username <?= $verifiedToken !== '' ? '(Verified ✓)' : '' ?></label>
                                <div class="input-prefix-wrap">
                                    <span class="input-prefix">@</span>
                                    <input
                                        type="text"
                                        id="input-instagram"
                                        name="instagram_username"
                                        value="<?= e($verifiedIgUser) ?>"
                                        placeholder="your_insta_handle"
                                        autocomplete="username"
                                        autocapitalize="none"
                                        spellcheck="false"
                                        maxlength="32"
                                        <?= $verifiedToken !== '' ? 'readonly' : '' ?>
                                        required
                                    >
                                </div>
                                <small class="field-error" data-error-for="instagram_username"></small>
                            </div>

                            <button id="btn-unlock" type="submit" class="btn btn-gold btn-full" <?= $verifiedToken !== '' ? '' : 'disabled' ?>>
                                <span id="btn-unlock-label"><?= $verifiedToken !== '' ? 'Unlock my card' : 'Unlocking in 10s...' ?></span>
                            </button>

                            <p class="privacy-note">
                                One entry per WhatsApp number &amp; Instagram account.
                            </p>
                        </form>
                    <?php endif; ?>
                </section>

                <!-- ====================================================
                     3. SCRATCH CARD SCREEN (Hidden until API returns)
                     ==================================================== -->
                <section id="step-scratch" class="card scratch-section is-hidden" aria-labelledby="scratch-heading">
                    <div class="step-badge">STEP 2 OF 2</div>
                    <h2 id="scratch-heading" class="card-title">Your Kochi Card is Ready!</h2>
                    <p id="scratch-instruction" class="rule-desc">
                        Rub the golden foil below with your finger or mouse to reveal your prize.
                    </p>

                    <div class="scratch-stage">
                        <div id="scratch-card-frame" class="scratch-card-frame">
                            <!-- Reward layer: strictly empty until server responds & foil is painted -->
                            <div id="reward-layer" class="reward-layer" aria-live="polite">
                                <div id="reward-badge" class="reward-badge"></div>
                                <div id="reward-icon" class="reward-icon" aria-hidden="true"></div>
                                <h3 id="reward-headline" class="reward-headline"></h3>
                                <div class="code-box">
                                    <span class="code-label">YOUR UNIQUE CODE</span>
                                    <div id="reward-code" class="reward-code"></div>
                                </div>
                                <p id="reward-validity" class="reward-validity"></p>
                            </div>

                            <!-- HTML5 Canvas Scratch Foil -->
                            <canvas id="scratch-canvas" class="scratch-canvas" aria-label="Scratch card surface"></canvas>
                        </div>
                    </div>

                    <!-- Post-reveal Actions (hidden until ~55% scratched) -->
                    <div id="post-reveal-actions" class="post-reveal-actions is-hidden">
                        <div class="screenshot-note">
                            📸 <strong>Screenshot this code</strong> right now so you don't lose it!
                        </div>

                        <p id="reward-instructions" class="claim-instructions"></p>

                        <div class="action-buttons">
                            <button type="button" id="btn-copy-code" class="btn btn-outline">
                                Copy Code
                            </button>
                            <a id="btn-share-wa" href="#" target="_blank" rel="noopener noreferrer" class="btn btn-whatsapp">
                                Share on WhatsApp
                            </a>
                        </div>

                        <a id="btn-claim-wa" href="#" target="_blank" rel="noopener noreferrer" class="btn btn-gold btn-full is-hidden">
                            Claim on WhatsApp
                        </a>
                    </div>
                </section>
            <?php endif; ?>
        </main>

        <footer class="site-footer">
            <p>&copy; <?= date('Y') ?> Techzo Kochi &bull; Discount valid <?= e($validityFull) ?> only</p>
        </footer>
    </div>

    <!-- Fullscreen Confetti Canvas -->
    <canvas id="confetti-canvas" class="confetti-canvas" aria-hidden="true"></canvas>

    <!-- Runtime Config passed safely to JS (no secrets, no prize info) -->
    <script>
        window.TECHZO_CONFIG = <?= json_encode([
            'csrfToken'     => $csrf,
            'apiUrl'        => 'api/play.php',
            'igHandle'      => $igHandle,
            'scratchHint'   => $scratchHint,
            'scratchSub'    => $scratchSub,
            'foilPrimary'   => $foilPrimary,
            'foilSecondary' => $foilSecondary,
            'cardBg'        => $cardBg,
            'waitSeconds'   => 10,
            'verifiedToken' => $verifiedToken,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>

    <!-- GSAP for smooth Kochi loading & net-lift animation (graceful fallback built into app.js) -->
    <script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js" defer></script>
    <script src="assets/js/scratch.js" defer></script>
    <script src="assets/js/app.js" defer></script>
</body>
</html>
