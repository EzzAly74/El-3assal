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
            mode: 'rail'
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

            // Also on resize: the step size and the maximum scroll both depend
            // on the container width.
            this._on($(window), { resize: this._queueUpdate });

            this._update();
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
