/**
 * Spartrak — homepage carousel controls.
 *
 * ===========================================================================
 * THIS WIDGET DOES NOT SCROLL ANYTHING. THE BROWSER DOES.
 * ===========================================================================
 * Every rail on the homepage is a plain `overflow-x: auto` container with
 * `scroll-snap-type`. That means it is already swipeable, already
 * keyboard-scrollable, already momentum-scrolled, already correct in RTL, and
 * already accessible — WITHOUT this file. Nothing here is required for the
 * section to work; if the JS never loads, a shopper loses the arrow buttons
 * and keeps the carousel.
 *
 * All this widget adds is:
 *   - arrows that call the native scrollBy()
 *   - dots that call the native scrollTo()
 *   - a progress bar and disabled states, updated from the native scroll event
 *
 * That is CLAUDE.md's "native browser/CSS behaviour over unnecessary
 * JavaScript" taken literally, and it is why one small widget serves the hero
 * banner, both product rails and the showcase rather than four carousel
 * implementations.
 *
 * ===========================================================================
 * WHY THE SCROLL HANDLER CANNOT JANK
 * ===========================================================================
 * Scroll fires far more often than the compositor paints. The handler here
 * does nothing except set a flag and request one animation frame; all reads
 * and writes happen inside that frame, and a second scroll event while a
 * frame is already pending is dropped. So the per-frame cost is bounded no
 * matter how fast the shopper flicks, and the only thing written is a CSS
 * custom property that drives a transform — never a width, never a class that
 * would invalidate layout.
 *
 * ===========================================================================
 * RTL
 * ===========================================================================
 * `scrollLeft` is signed in RTL and browsers historically disagreed about how.
 * Rather than branch per browser, direction is read once from the computed
 * style and every distance is taken through Math.abs() with an explicit sign
 * on the way back out. "Next" therefore means "further along the reading
 * direction" in both locales.
 */
define(['jquery', 'jquery-ui-modules/widget'], function ($) {
    'use strict';

    $.widget('mage.spartrakHomeCarousel', {
        options: {
            // 'banner' pages a full slide at a time; 'rail' pages by roughly a
            // viewport of cards, which is what feels right on a rail whose
            // items are much narrower than the container.
            mode: 'rail',

            // Milliseconds between automatic advances. 0 disables it, which is
            // the default: only the hero opts in. See _setupAutoplay.
            autoplay: 0
        },

        _create: function () {
            this.track = this.element.find('[data-carousel-track]').first();

            if (!this.track.length) {
                return;
            }

            this.trackEl = this.track[0];
            this.slides = this.track.find('[data-carousel-slide]');

            if (this.slides.length < 2) {
                return;
            }

            // The prev/next buttons live in the SECTION HEADER for product
            // rails and inside the frame for the banner, so they are looked up
            // from the widget root rather than from the track.
            this.prev = this.element.find('[data-carousel-prev]');
            this.next = this.element.find('[data-carousel-next]');
            this.dots = this.element.find('[data-carousel-dot]');
            this.progress = this.element.find('[data-carousel-progress]');

            this.isRtl = window.getComputedStyle(this.trackEl).direction === 'rtl';
            this.frameQueued = false;

            this._on(this.prev, { click: this._onPrev });
            this._on(this.next, { click: this._onNext });
            this._on(this.dots, { click: this._onDot });
            this._on(this.track, { scroll: this._queueUpdate });

            this._enableDrag();

            // Also on resize: the step size and the maximum scroll both depend
            // on the container width.
            this._on($(window), { resize: this._queueUpdate });

            this._update();
            this._setupAutoplay();
        },

        /**
         * ===================================================================
         * AUTOPLAY - THE HERO ONLY, AND STILL THE BROWSER DOING THE SCROLLING
         * ===================================================================
         * `_advance` calls the same native scrollTo() the dots already call,
         * with `behavior: 'smooth'`. Nothing here animates anything frame by
         * frame: the browser owns the easing, on the compositor, and it is the
         * identical motion a shopper gets from pressing an arrow. That is what
         * makes it smooth, and it is why this costs one timer and no rAF loop
         * (CLAUDE.md section 13).
         *
         * FOUR THINGS STOP THE CLOCK, each avoiding a real cost:
         *
         *   prefers-reduced-motion  never starts at all. An auto-advancing
         *                           carousel is the canonical thing that
         *                           setting exists to stop (CLAUDE.md 15).
         *   pointer / focus inside  pauses. A shopper reading a slide, or
         *                           tabbing through its link, does not get it
         *                           pulled out from under them.
         *   tab hidden              pauses, via `visibilitychange`. A timer
         *                           firing scrollTo() in a background tab is
         *                           pure battery.
         *   hero scrolled off       pauses, via IntersectionObserver. The hero
         *                           sits at the top of a long page, so for most
         *                           of a session it is not on screen at all.
         *
         * ANY DELIBERATE INTERACTION STOPS IT FOR GOOD - an arrow, a dot, or a
         * drag. Once a shopper has chosen a slide, moving them off it is the
         * carousel arguing with them.
         *
         * WCAG 2.2.2, honestly. The criterion asks for a mechanism to pause
         * content that moves for more than five seconds. The mechanism here is
         * the visible pagination dots: pressing one halts the rotation
         * permanently, as does hovering or tabbing in. A dedicated pause button
         * would be the textbook answer, and Figma's hero (595:14562) does not
         * draw one - so this is the strongest conformance available without
         * inventing a control the design has no place for, and it is recorded
         * here as a known gap rather than treated as solved.
         */
        _setupAutoplay: function () {
            var interval = parseInt(this.options.autoplay, 10) || 0,
                self = this,
                reduced = window.matchMedia
                    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            if (interval <= 0 || reduced) {
                return;
            }

            this.autoplayInterval = interval;
            this.autoplayTimer = null;
            this.autoplayStopped = false;
            // Two independent reasons to be paused - a pointer resting on the
            // hero and the tab being in the background - so they are COUNTED
            // rather than toggled. A single boolean would let whichever
            // resumed second restart the clock while the other still held it.
            this.autoplayHolds = 0;

            this._on(this.element, {
                mouseenter: this._holdAutoplay,
                mouseleave: this._releaseAutoplay,
                focusin: this._holdAutoplay,
                focusout: this._releaseAutoplay
            });

            // Capture phase, so a press on an arrow or a dot registers as
            // intent even though those handlers live on the same element.
            this.element[0].addEventListener('pointerdown', function () {
                self._stopAutoplay();
            }, true);

            this._onVisibility = function () {
                if (document.hidden) {
                    self._holdAutoplay();
                } else {
                    self._releaseAutoplay();
                }
            };
            document.addEventListener('visibilitychange', this._onVisibility);

            if (window.IntersectionObserver) {
                // Starts HELD, because the observer's first callback always
                // fires and is what releases it when the hero is already on
                // screen. Starting unheld would double-count that release.
                this.autoplayHolds = 1;
                this.autoplayObserver = new window.IntersectionObserver(function (entries) {
                    if (entries[entries.length - 1].isIntersecting) {
                        self._releaseAutoplay();
                    } else {
                        self._holdAutoplay();
                    }
                }, { threshold: 0.25 });
                this.autoplayObserver.observe(this.element[0]);

                return;
            }

            this._resumeAutoplay();
        },

        _holdAutoplay: function () {
            if (this.autoplayStopped || !this.autoplayInterval) {
                return;
            }

            this.autoplayHolds += 1;
            this._clearAutoplayTimer();
        },

        _releaseAutoplay: function () {
            if (this.autoplayStopped || !this.autoplayInterval) {
                return;
            }

            this.autoplayHolds = Math.max(0, this.autoplayHolds - 1);

            if (this.autoplayHolds === 0) {
                this._resumeAutoplay();
            }
        },

        _resumeAutoplay: function () {
            var self = this;

            this._clearAutoplayTimer();
            this.autoplayTimer = window.setInterval(function () {
                self._advance();
            }, this.autoplayInterval);
        },

        /**
         * Permanent. There is no path back to autoplay in this widget's life.
         */
        _stopAutoplay: function () {
            this.autoplayStopped = true;
            this._clearAutoplayTimer();
        },

        _clearAutoplayTimer: function () {
            if (this.autoplayTimer) {
                window.clearInterval(this.autoplayTimer);
                this.autoplayTimer = null;
            }
        },

        /**
         * One slide forward, wrapping to the first at the end.
         *
         * The wrap is a scrollTo(0) rather than a clone-based infinite loop:
         * cloning slides would duplicate every hero <picture> in the DOM and in
         * the accessibility tree to buy a seam a shopper sees once per rotation.
         */
        _advance: function () {
            var el = this.trackEl,
                maxScroll = el.scrollWidth - el.clientWidth;

            if (Math.abs(el.scrollLeft) >= maxScroll - 1) {
                el.scrollTo({ left: 0, behavior: 'smooth' });

                return;
            }

            this._scrollBy(this._step());
        },

        /**
         * jQuery UI calls this on teardown. `_on` bindings and the observer's
         * own subscription go with the widget; the document-level listener and
         * the interval do not, so they are released by hand.
         */
        _destroy: function () {
            this._clearAutoplayTimer();

            if (this.autoplayObserver) {
                this.autoplayObserver.disconnect();
                this.autoplayObserver = null;
            }

            if (this._onVisibility) {
                document.removeEventListener('visibilitychange', this._onVisibility);
                this._onVisibility = null;
            }
        },

        /**
         * Click-and-drag with a mouse.
         *
         * ===================================================================
         * WHY THIS NEEDS JS WHEN THE REST OF THE RAIL DOES NOT
         * ===================================================================
         * `overflow-x: auto` already gives touch swipe, trackpad scroll, the
         * keyboard and momentum for free — that is why the rails work with no
         * JS at all. The one gesture browsers do NOT provide is dragging with
         * a held mouse button; on desktop a shopper expects to grab a carousel
         * and pull it. That gesture, and only that gesture, is added here.
         *
         * Deliberately limited to a real mouse. Touch and pen already scroll
         * natively, and intercepting them would replace a smooth, momentum-
         * carrying native gesture with a worse hand-rolled one.
         *
         * THREE THINGS THAT WOULD OTHERWISE BREAK, HANDLED:
         *   1. scroll-snap fights a drag — it keeps yanking the rail back to
         *      the nearest snap point mid-gesture. Snapping is switched off for
         *      the duration and restored on release, so the rail still settles
         *      onto a card afterwards.
         *   2. a drag that ends over a card would otherwise FOLLOW that card's
         *      link. A click is suppressed once, and only if the pointer
         *      actually travelled past a small threshold, so an ordinary click
         *      still works.
         *   3. the browser's own image/text drag would take over. Suppressed
         *      on the track only.
         */
        _enableDrag: function () {
            var el = this.trackEl;
            var self = this;
            var down = false;
            var moved = 0;
            var startX = 0;
            var startScroll = 0;
            var snap = '';

            // Anything below this is a click, not a drag. Roughly the slop a
            // mouse picks up between button-down and button-up on a click.
            var THRESHOLD = 5;

            el.addEventListener('pointerdown', function (event) {
                if (event.pointerType !== 'mouse' || event.button !== 0) {
                    return;
                }

                down = true;
                moved = 0;
                startX = event.clientX;
                startScroll = el.scrollLeft;

                snap = el.style.scrollSnapType;
                el.style.scrollSnapType = 'none';
                el.classList.add('is-dragging');
            });

            el.addEventListener('pointermove', function (event) {
                if (!down) {
                    return;
                }

                var delta = event.clientX - startX;

                moved = Math.max(moved, Math.abs(delta));

                // Physical movement, so it reads correctly in both directions
                // without a sign flip: the content follows the hand.
                el.scrollLeft = startScroll - delta;
            });

            ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (type) {
                el.addEventListener(type, function () {
                    if (!down) {
                        return;
                    }

                    down = false;
                    el.style.scrollSnapType = snap;
                    el.classList.remove('is-dragging');

                    if (moved > THRESHOLD) {
                        self.suppressClick = true;
                    }
                });
            });

            // Capture phase: the suppression has to run before the link's own
            // handler, not after it has already navigated.
            el.addEventListener('click', function (event) {
                if (!self.suppressClick) {
                    return;
                }

                self.suppressClick = false;
                event.preventDefault();
                event.stopPropagation();
            }, true);

            el.addEventListener('dragstart', function (event) {
                if (down) {
                    event.preventDefault();
                }
            });
        },

        /**
         * How far one press of an arrow travels.
         */
        _step: function () {
            var visible = this.trackEl.clientWidth;

            if (this.options.mode === 'banner') {
                return visible;
            }

            var slideWidth = this.slides.first().outerWidth(true) || visible;

            // Leave one card of overlap so a shopper keeps a visual anchor
            // between pages instead of the rail jumping to an unrelated set.
            return Math.max(slideWidth, visible - slideWidth);
        },

        _scrollBy: function (distance) {
            this.trackEl.scrollBy({
                left: this.isRtl ? -distance : distance,
                behavior: 'smooth'
            });
        },

        _onPrev: function (event) {
            event.preventDefault();
            this._scrollBy(-this._step());
        },

        _onNext: function (event) {
            event.preventDefault();
            this._scrollBy(this._step());
        },

        _onDot: function (event) {
            event.preventDefault();

            var index = parseInt($(event.currentTarget).attr('data-index'), 10) || 0;
            var slide = this.slides.get(index);

            if (!slide) {
                return;
            }

            // offsetLeft is direction-agnostic; converting it to a scroll
            // position is the only place the RTL sign matters.
            var target = slide.offsetLeft;

            this.trackEl.scrollTo({
                left: this.isRtl ? -Math.abs(target) : target,
                behavior: 'smooth'
            });
        },

        /**
         * Coalesces any number of scroll events into at most one frame of
         * work. See the class note.
         */
        _queueUpdate: function () {
            if (this.frameQueued) {
                return;
            }

            this.frameQueued = true;

            window.requestAnimationFrame(function () {
                this.frameQueued = false;
                this._update();
            }.bind(this));
        },

        _update: function () {
            var el = this.trackEl;
            var maxScroll = el.scrollWidth - el.clientWidth;
            var offset = Math.abs(el.scrollLeft);
            var ratio = maxScroll > 0 ? Math.min(offset / maxScroll, 1) : 0;

            this._setDisabled(this.prev, offset <= 1);
            this._setDisabled(this.next, offset >= maxScroll - 1);

            if (this.progress.length) {
                // The bar is 25% wide, so it can travel 300% of its own width.
                // One custom property; the transform is composited.
                this.progress[0].style.setProperty(
                    '--spartrak-rail-progress',
                    (this.isRtl ? -300 * ratio : 300 * ratio) + '%'
                );
            }

            if (this.dots.length) {
                this._updateDots(offset, el.clientWidth);
            }
        },

        _updateDots: function (offset, visibleWidth) {
            var index = Math.round(offset / (visibleWidth || 1));

            this.dots.each(function (i, dot) {
                var selected = i === index;

                dot.setAttribute('aria-selected', selected ? 'true' : 'false');
            });

            // Keeps assistive tech in step with what is visually on screen.
            this.slides.each(function (i, slide) {
                if (i === index) {
                    slide.removeAttribute('aria-hidden');
                } else {
                    slide.setAttribute('aria-hidden', 'true');
                }
            });
        },

        _setDisabled: function (buttons, disabled) {
            if (!buttons.length) {
                return;
            }

            buttons.each(function (i, button) {
                button.disabled = disabled;
            });
        }
    });

    return $.mage.spartrakHomeCarousel;
});
