/**
 * Techzo Kochi Scratch & Win - HTML5 Canvas Scratch Card & Confetti Engine
 * Touch + Mouse supported, shimmering gold foil, ~55% auto-reveal threshold.
 */

(function (global) {
    'use strict';

    class KochiScratchCard {
        constructor(canvas, options = {}) {
            this.canvas = canvas;
            this.ctx = canvas.getContext('2d', { willReadFrequently: true });
            this.options = Object.assign({
                hintText: 'Scratch Here',
                subText: 'Swipe to reveal your Kochi surprise',
                foilPrimary: '#d4a64a',
                foilSecondary: '#f5d782',
                revealThreshold: 0.55,
                brushRadius: 26,
                onReveal: null,
            }, options);

            this.isDrawing = false;
            this.isRevealed = false;
            this.lastPoint = null;
            this.strokeCount = 0;
            this.dpr = Math.min(window.devicePixelRatio || 1, 2);

            this._bindEvents();
        }

        initSurface() {
            const rect = this.canvas.parentElement.getBoundingClientRect();
            const width = Math.max(280, Math.round(rect.width));
            const height = Math.max(290, Math.round(rect.height));

            this.width = width;
            this.height = height;
            this.canvas.width = Math.round(width * this.dpr);
            this.canvas.height = Math.round(height * this.dpr);
            this.canvas.style.width = width + 'px';
            this.canvas.style.height = height + 'px';

            this.ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
            this.ctx.globalCompositeOperation = 'source-over';
            this.isRevealed = false;
            this.canvas.classList.remove('is-revealed');

            this._paintGoldFoil();
        }

        _paintGoldFoil() {
            const ctx = this.ctx;
            const w = this.width;
            const h = this.height;
            const c1 = this.options.foilPrimary;
            const c2 = this.options.foilSecondary;

            // Rich metallic gold gradient
            const grad = ctx.createLinearGradient(0, 0, w, h);
            grad.addColorStop(0, '#a6761f');
            grad.addColorStop(0.24, c1);
            grad.addColorStop(0.5, c2);
            grad.addColorStop(0.76, c1);
            grad.addColorStop(1, '#9d6e1a');
            ctx.fillStyle = grad;
            ctx.fillRect(0, 0, w, h);

            // Diagonal shimmer bands
            ctx.save();
            ctx.globalAlpha = 0.22;
            for (let i = -h; i < w + h; i += 36) {
                const band = ctx.createLinearGradient(i, 0, i + 20, h);
                band.addColorStop(0, 'rgba(255,255,255,0)');
                band.addColorStop(0.5, 'rgba(255,250,230,0.85)');
                band.addColorStop(1, 'rgba(255,255,255,0)');
                ctx.fillStyle = band;
                ctx.beginPath();
                ctx.moveTo(i, 0);
                ctx.lineTo(i + 16, 0);
                ctx.lineTo(i - 36, h);
                ctx.lineTo(i - 52, h);
                ctx.closePath();
                ctx.fill();
            }
            ctx.restore();

            // Subtle metallic grain speckles
            ctx.save();
            for (let i = 0; i < 420; i++) {
                const x = Math.random() * w;
                const y = Math.random() * h;
                const r = Math.random() * 1.4 + 0.3;
                ctx.fillStyle = i % 2 === 0 ? 'rgba(255,255,255,0.28)' : 'rgba(92,58,8,0.18)';
                ctx.beginPath();
                ctx.arc(x, y, r, 0, Math.PI * 2);
                ctx.fill();
            }
            ctx.restore();

            // Decorative inner Kasavu-style border
            ctx.save();
            ctx.strokeStyle = 'rgba(6, 41, 45, 0.35)';
            ctx.lineWidth = 2;
            ctx.setLineDash([6, 4]);
            ctx.strokeRect(14, 14, w - 28, h - 28);
            ctx.restore();

            // Center Sparkle Emblem
            ctx.save();
            ctx.fillStyle = '#06292d';
            ctx.globalAlpha = 0.85;
            ctx.font = '700 12px Poppins, sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText('✦ TECHZO KOCHI ✦', w / 2, h / 2 - 44);

            // Main "Scratch Here" hint
            ctx.font = '800 28px Poppins, sans-serif';
            ctx.fillStyle = '#052226';
            ctx.fillText(this.options.hintText, w / 2, h / 2);

            // Subtext
            ctx.font = '600 13px Poppins, sans-serif';
            ctx.fillStyle = 'rgba(5, 34, 38, 0.78)';
            ctx.fillText(this.options.subText, w / 2, h / 2 + 34);
            ctx.restore();
        }

        _getCoords(evt) {
            const rect = this.canvas.getBoundingClientRect();
            let clientX, clientY;
            if (evt.touches && evt.touches.length > 0) {
                clientX = evt.touches[0].clientX;
                clientY = evt.touches[0].clientY;
            } else {
                clientX = evt.clientX;
                clientY = evt.clientY;
            }
            return {
                x: clientX - rect.left,
                y: clientY - rect.top,
            };
        }

        _scratch(from, to) {
            const ctx = this.ctx;
            ctx.save();
            ctx.globalCompositeOperation = 'destination-out';
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.lineWidth = this.options.brushRadius * 2;

            ctx.beginPath();
            ctx.moveTo(from.x, from.y);
            ctx.lineTo(to.x, to.y);
            ctx.stroke();

            ctx.beginPath();
            ctx.arc(to.x, to.y, this.options.brushRadius, 0, Math.PI * 2);
            ctx.fill();
            ctx.restore();
        }

        _calculateScratchedRatio() {
            const pixels = this.ctx.getImageData(0, 0, this.canvas.width, this.canvas.height).data;
            let transparent = 0;
            let sampled = 0;
            // Sample every 16th pixel for fast mobile performance
            const step = 16 * 4;
            for (let i = 3; i < pixels.length; i += step) {
                sampled++;
                if (pixels[i] < 40) {
                    transparent++;
                }
            }
            return sampled > 0 ? transparent / sampled : 0;
        }

        _checkAutoReveal() {
            if (this.isRevealed) return;
            const ratio = this._calculateScratchedRatio();
            if (ratio >= this.options.revealThreshold) {
                this.revealAll();
            }
        }

        revealAll() {
            if (this.isRevealed) return;
            this.isRevealed = true;
            this.isDrawing = false;
            this.canvas.classList.add('is-revealed');
            if (typeof this.options.onReveal === 'function') {
                this.options.onReveal();
            }
        }

        _bindEvents() {
            const start = (evt) => {
                if (this.isRevealed) return;
                evt.preventDefault();
                this.isDrawing = true;
                const pt = this._getCoords(evt);
                this.lastPoint = pt;
                this._scratch(pt, pt);
            };

            const move = (evt) => {
                if (!this.isDrawing || this.isRevealed) return;
                evt.preventDefault();
                const pt = this._getCoords(evt);
                this._scratch(this.lastPoint || pt, pt);
                this.lastPoint = pt;
                this.strokeCount++;
                if (this.strokeCount % 6 === 0) {
                    this._checkAutoReveal();
                }
            };

            const end = () => {
                if (!this.isDrawing) return;
                this.isDrawing = false;
                this.lastPoint = null;
                this._checkAutoReveal();
            };

            this.canvas.addEventListener('mousedown', start);
            window.addEventListener('mousemove', move);
            window.addEventListener('mouseup', end);

            this.canvas.addEventListener('touchstart', start, { passive: false });
            this.canvas.addEventListener('touchmove', move, { passive: false });
            window.addEventListener('touchend', end);
            window.addEventListener('touchcancel', end);
        }
    }

    /**
     * Lightweight Festive Confetti Burst (Gold, Cream, Teal, Coral)
     */
    function launchConfetti(canvas, durationMs = 3400) {
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        const dpr = Math.min(window.devicePixelRatio || 1, 2);

        const resize = () => {
            canvas.width = Math.round(window.innerWidth * dpr);
            canvas.height = Math.round(window.innerHeight * dpr);
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        };
        resize();

        const colors = ['#f5d782', '#d4a64a', '#f7f1e3', '#25d366', '#ff8a5b', '#38bdf8'];
        const pieces = [];
        const count = 120;

        for (let i = 0; i < count; i++) {
            pieces.push({
                x: window.innerWidth * 0.5 + (Math.random() - 0.5) * 140,
                y: window.innerHeight * 0.42 + (Math.random() - 0.5) * 60,
                vx: (Math.random() - 0.5) * 12,
                vy: Math.random() * -11 - 3,
                w: Math.random() * 8 + 5,
                h: Math.random() * 5 + 3,
                rot: Math.random() * Math.PI * 2,
                vRot: (Math.random() - 0.5) * 0.25,
                color: colors[i % colors.length],
                alpha: 1,
            });
        }

        const startTime = performance.now();

        function frame(now) {
            const elapsed = now - startTime;
            ctx.clearRect(0, 0, window.innerWidth, window.innerHeight);

            for (const p of pieces) {
                p.vy += 0.26; // gravity
                p.vx *= 0.99;
                p.x += p.vx;
                p.y += p.vy;
                p.rot += p.vRot;
                if (elapsed > durationMs * 0.65) {
                    p.alpha = Math.max(0, 1 - (elapsed - durationMs * 0.65) / (durationMs * 0.35));
                }

                ctx.save();
                ctx.globalAlpha = p.alpha;
                ctx.translate(p.x, p.y);
                ctx.rotate(p.rot);
                ctx.fillStyle = p.color;
                ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h);
                ctx.restore();
            }

            if (elapsed < durationMs) {
                requestAnimationFrame(frame);
            } else {
                ctx.clearRect(0, 0, window.innerWidth, window.innerHeight);
            }
        }

        requestAnimationFrame(frame);
    }

    global.KochiScratchCard = KochiScratchCard;
    global.launchKochiConfetti = launchConfetti;
})(window);
