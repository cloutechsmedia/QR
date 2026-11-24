/**
 * Techzo Kochi Scratch & Win - Main Campaign Controller
 */

(function () {
    'use strict';

    const cfg = window.TECHZO_CONFIG || {};
    const WAIT_SECONDS = Number(cfg.waitSeconds || 10);
    const STORAGE_KEY_FOLLOW_TS = 'tz_kochi_follow_clicked_at';

    /* ======================================================================
       1. KOCHI LOADING ANIMATION (0% -> 100%, Net Lift + Wave Wipe)
       ====================================================================== */
    function runKochiLoader() {
        const loader = document.getElementById('kochi-loader');
        const bar = document.getElementById('loader-bar');
        const pctEl = document.getElementById('loader-percent');
        const netArm = document.getElementById('net-arm');
        const boat = document.getElementById('kochi-boat');
        const waveWipe = document.getElementById('wave-wipe');

        if (!loader || !bar || !pctEl) return;

        document.body.classList.add('is-loading');

        const finishTransition = () => {
            if (window.gsap) {
                const tl = window.gsap.timeline({
                    onComplete: () => {
                        loader.classList.add('is-done');
                        document.body.classList.remove('is-loading');
                    },
                });
                // Lift the Chinese fishing net smoothly at 100%
                if (netArm) {
                    tl.to(netArm, { rotation: 24, duration: 0.65, ease: 'power2.out' }, 0);
                }
                if (boat) {
                    tl.to(boat, { x: 90, duration: 0.7, ease: 'power2.inOut' }, 0);
                }
                // Wave wipe reveal
                if (waveWipe) {
                    tl.to(waveWipe, { y: '0%', duration: 0.55, ease: 'power3.inOut' }, 0.25)
                      .to(loader, { opacity: 0, duration: 0.4, ease: 'power2.out' }, 0.72);
                } else {
                    tl.to(loader, { opacity: 0, duration: 0.5 }, 0.45);
                }
            } else {
                // Pure CSS fallback if GSAP CDN is unavailable
                if (netArm) {
                    netArm.style.transition = 'transform 0.6s ease-out';
                    netArm.style.transform = 'rotate(24deg)';
                }
                setTimeout(() => {
                    loader.classList.add('is-done');
                    document.body.classList.remove('is-loading');
                }, 650);
            }
        };

        const counter = { val: 0 };
        if (window.gsap) {
            if (boat) {
                window.gsap.to(boat, { x: 36, duration: 2.2, ease: 'sine.inOut' });
            }
            if (netArm) {
                window.gsap.fromTo(
                    netArm,
                    { rotation: -5 },
                    { rotation: 4, duration: 1.8, ease: 'sine.inOut' }
                );
            }
            window.gsap.to(counter, {
                val: 100,
                duration: 2.2,
                ease: 'power2.inOut',
                onUpdate: () => {
                    const p = Math.round(counter.val);
                    pctEl.textContent = p + '%';
                    bar.style.width = p + '%';
                },
                onComplete: finishTransition,
            });
        } else {
            let p = 0;
            const timer = setInterval(() => {
                p = Math.min(100, p + 4);
                pctEl.textContent = p + '%';
                bar.style.width = p + '%';
                if (p >= 100) {
                    clearInterval(timer);
                    finishTransition();
                }
            }, 70);
        }
    }

    /* ======================================================================
       2. FOLLOW BUTTON + 10-SECOND UNLOCK TIMER + FORM VALIDATION
       ====================================================================== */
    function initRulesAndForm() {
        const btnFollow = document.getElementById('btn-follow-ig');
        const followHint = document.getElementById('follow-hint');
        const form = document.getElementById('entry-form');
        const btnUnlock = document.getElementById('btn-unlock');
        const btnUnlockLabel = document.getElementById('btn-unlock-label');
        const formAlert = document.getElementById('form-alert');

        if (!form || !btnUnlock) return;

        let countdownInterval = null;
        let timerReady = Boolean(cfg.verifiedToken);
        let isSubmitting = false;

        function startCountdownFrom(startTimestampMs) {
            form.classList.remove('is-hidden');
            if (followHint) {
                followHint.textContent = 'Awesome! Fill in your details below while we unlock your scratch card.';
            }

            if (countdownInterval) clearInterval(countdownInterval);

            const tick = () => {
                const elapsedSec = Math.floor((Date.now() - startTimestampMs) / 1000);
                const remaining = Math.max(0, WAIT_SECONDS - elapsedSec);

                if (remaining > 0) {
                    timerReady = false;
                    btnUnlock.disabled = true;
                    btnUnlockLabel.textContent = `Unlocking in ${remaining}s...`;
                } else {
                    timerReady = true;
                    if (!isSubmitting) {
                        btnUnlock.disabled = false;
                        btnUnlockLabel.textContent = 'Unlock my card';
                    }
                    clearInterval(countdownInterval);
                    countdownInterval = null;
                }
            };

            tick();
            countdownInterval = setInterval(tick, 250);
        }

        if (!cfg.verifiedToken && btnFollow) {
            // Restore timer if user switched to Instagram app and came back
            const savedTs = Number(sessionStorage.getItem(STORAGE_KEY_FOLLOW_TS) || 0);
            if (savedTs > 0 && Date.now() - savedTs < 30 * 60 * 1000) {
                startCountdownFrom(savedTs);
            }

            btnFollow.addEventListener('click', () => {
                const now = Date.now();
                sessionStorage.setItem(STORAGE_KEY_FOLLOW_TS, String(now));
                startCountdownFrom(now);
            });

            // Also gently scroll form into view when user returns to tab
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && !form.classList.contains('is-hidden')) {
                    const ts = Number(sessionStorage.getItem(STORAGE_KEY_FOLLOW_TS) || 0);
                    if (ts > 0) startCountdownFrom(ts);
                }
            });
        }

        function setFieldError(field, msg) {
            const el = form.querySelector(`[data-error-for="${field}"]`);
            if (el) el.textContent = msg || '';
        }

        function clearErrors() {
            form.querySelectorAll('.field-error').forEach((el) => (el.textContent = ''));
            if (formAlert) {
                formAlert.classList.add('is-hidden');
                formAlert.classList.remove('is-info');
                formAlert.textContent = '';
            }
        }

        function showAlert(msg, isInfo = false) {
            if (!formAlert) return;
            formAlert.textContent = msg;
            formAlert.classList.toggle('is-info', isInfo);
            formAlert.classList.remove('is-hidden');
        }

        function validateClient(name, whatsapp, instagram) {
            let valid = true;
            if (name.trim().length < 2) {
                setFieldError('name', 'Please enter your full name (at least 2 characters).');
                valid = false;
            }
            const digits = whatsapp.replace(/\D+/g, '');
            if (digits.length < 10 || digits.length > 15) {
                setFieldError('whatsapp', 'Enter a valid 10-digit WhatsApp number.');
                valid = false;
            }
            const igClean = instagram.trim().replace(/^@+/, '').toLowerCase();
            if (!/^[a-z0-9._]{1,30}$/.test(igClean) || igClean.startsWith('.') || igClean.endsWith('.') || igClean.includes('..')) {
                setFieldError('instagram_username', 'Enter a valid Instagram username without spaces.');
                valid = false;
            }
            return valid;
        }

        form.addEventListener('submit', async (evt) => {
            evt.preventDefault();
            clearErrors();

            if (!timerReady) {
                showAlert('Please wait until the 10-second timer finishes.', true);
                return;
            }

            const nameVal = document.getElementById('input-name').value.trim();
            const waVal = document.getElementById('input-whatsapp').value.trim();
            const igVal = document.getElementById('input-instagram').value.trim();

            if (!validateClient(nameVal, waVal, igVal)) {
                return;
            }

            isSubmitting = true;
            btnUnlock.disabled = true;
            btnUnlockLabel.textContent = 'Preparing your card...';

            try {
                const res = await fetch(cfg.apiUrl || 'api/play.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': cfg.csrfToken || '',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        csrf_token: cfg.csrfToken || '',
                        ig_token: cfg.verifiedToken || '',
                        name: nameVal,
                        whatsapp: waVal,
                        instagram_username: igVal,
                    }),
                });

                const json = await res.json();

                if (!res.ok || !json.ok) {
                    if (json.fields) {
                        Object.entries(json.fields).forEach(([k, v]) => setFieldError(k, v));
                    }
                    showAlert(json.error || 'Could not unlock card. Please check your details.', Boolean(json.already_played));
                    isSubmitting = false;
                    btnUnlock.disabled = false;
                    btnUnlockLabel.textContent = 'Unlock my card';
                    return;
                }

                // Success: transition to scratch card!
                showScratchCard(json.reward);
            } catch (err) {
                showAlert('Network error. Please check your connection and try again.');
                isSubmitting = false;
                btnUnlock.disabled = false;
                btnUnlockLabel.textContent = 'Unlock my card';
            }
        });
    }

    /* ======================================================================
       3. SCRATCH CARD SETUP & REVEAL
       ====================================================================== */
    function showScratchCard(reward) {
        const stepRules = document.getElementById('step-rules');
        const stepScratch = document.getElementById('step-scratch');
        const canvas = document.getElementById('scratch-canvas');
        const rewardLayer = document.getElementById('reward-layer');
        const badgeEl = document.getElementById('reward-badge');
        const iconEl = document.getElementById('reward-icon');
        const headlineEl = document.getElementById('reward-headline');
        const codeEl = document.getElementById('reward-code');
        const validityEl = document.getElementById('reward-validity');
        const postReveal = document.getElementById('post-reveal-actions');
        const instrEl = document.getElementById('reward-instructions');
        const btnCopy = document.getElementById('btn-copy-code');
        const btnShareWa = document.getElementById('btn-share-wa');
        const btnClaimWa = document.getElementById('btn-claim-wa');
        const scratchInstr = document.getElementById('scratch-instruction');
        const confettiCanvas = document.getElementById('confetti-canvas');

        if (!stepScratch || !canvas || !reward) return;

        // Hide Step 1 and reveal Step 2 container FIRST
        if (stepRules) stepRules.classList.add('is-hidden');
        stepScratch.classList.remove('is-hidden');
        stepScratch.scrollIntoView({ behavior: 'smooth', block: 'start' });

        // Paint the shimmering gold foil BEFORE populating the underlying reward text
        const scratchCard = new window.KochiScratchCard(canvas, {
            hintText: cfg.scratchHint || 'Scratch Here',
            subText: cfg.scratchSub || 'Swipe to reveal your Kochi surprise',
            foilPrimary: cfg.foilPrimary || '#d4a64a',
            foilSecondary: cfg.foilSecondary || '#f5d782',
            revealThreshold: 0.55,
            onReveal: () => {
                if (typeof window.launchKochiConfetti === 'function') {
                    window.launchKochiConfetti(confettiCanvas);
                }
                if (scratchInstr) {
                    scratchInstr.textContent =
                        reward.type === 'gift'
                            ? 'Woohoo! You hit one of Kochi\'s golden lucky slots!'
                            : 'Awesome! Your Techzo Kochi reward is unlocked.';
                }
                if (postReveal) {
                    postReveal.classList.remove('is-hidden');
                }
            },
        });

        // Paint foil immediately
        scratchCard.initSurface();

        // Now safely populate the reward layer underneath the opaque gold canvas
        const isGift = reward.type === 'gift';
        rewardLayer.classList.toggle('is-gift', isGift);
        badgeEl.textContent = isGift ? 'LUCKY GIFT WINNER' : 'GUARANTEED REWARD';
        iconEl.textContent = isGift ? '🔊' : '🎟️';
        headlineEl.textContent = reward.headline;
        codeEl.textContent = reward.code;
        validityEl.textContent = reward.validity;
        if (instrEl) instrEl.textContent = reward.instructions;

        // Wire Share on WhatsApp
        if (btnShareWa) {
            const shareMsg = encodeURIComponent(reward.share_text || '');
            btnShareWa.href = `https://wa.me/?text=${shareMsg}`;
        }

        // Wire direct Claim on WhatsApp for Gift Winners (if claim number configured)
        if (btnClaimWa && isGift && reward.claim_wa) {
            const claimMsg = encodeURIComponent(
                `Hi Techzo! I won a ${reward.gift_name} in Techzo Kochi Scratch & Win! My code is ${reward.code} (Name: ${reward.name}).`
            );
            btnClaimWa.href = `https://wa.me/${reward.claim_wa}?text=${claimMsg}`;
            btnClaimWa.classList.remove('is-hidden');
        }

        // Copy code button
        if (btnCopy) {
            btnCopy.addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(reward.code);
                    btnCopy.textContent = 'Copied!';
                    setTimeout(() => (btnCopy.textContent = 'Copy Code'), 2000);
                } catch {
                    // Fallback
                    const ta = document.createElement('textarea');
                    ta.value = reward.code;
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    ta.remove();
                    btnCopy.textContent = 'Copied!';
                    setTimeout(() => (btnCopy.textContent = 'Copy Code'), 2000);
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        runKochiLoader();
        initRulesAndForm();
    });
})();
