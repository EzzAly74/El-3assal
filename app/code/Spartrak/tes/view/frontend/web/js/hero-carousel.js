/**
 * Spartrak Homepage Hero Carousel.
 *
 * 17-FIGMA-CODE-AUDIT.md §2/§3 fix — Module 5 Hero was a single static
 * image, Figma confirms a real multi-slide carousel (node 595:14562, 5-dot
 * pagination + arrows). New/shared functionality with no Porto equivalent
 * belongs in this module (see this module's requirejs-config.js for the full
 * RequireJS-collection rationale) — one file serves both themes.
 *
 * Deliberately minimal: no autoplay/infinite-loop claim, since neither was
 * confirmed against Figma for this specific node — prev/next + dot
 * navigation only. `transform: translateX` has no logical-property
 * equivalent (same documented limitation _plp.less's mobile filter drawer
 * already calls out), so the translate sign branches on the document's
 * own `dir` attribute rather than assuming LTR — flex already reverses
 * which physical side "next" moves toward under `dir="rtl"`; the
 * translate direction must match that or slides would move visually
 * backwards.
 */
define([
    'jquery',
    'jquery/ui'
], function ($) {
    'use strict';

    $.widget('mage.spartrakHeroCarousel', {
        options: {},

        _create: function () {
            this._track = this.element.find('[data-hero-slide]').parent();
            this._slides = this.element.find('[data-hero-slide]');
            this._dots = this.element.find('[data-hero-dot]');
            this._current = 0;

            if (this._slides.length <= 1) {
                return;
            }

            this._on({
                'click [data-hero-prev]': this._prev,
                'click [data-hero-next]': this._next,
                'click [data-hero-dot]': this._onDotClick
            });

            this._render();
        },

        _prev: function () {
            this._goTo(this._current - 1);
        },

        _next: function () {
            this._goTo(this._current + 1);
        },

        _onDotClick: function (event) {
            this._goTo($(event.currentTarget).data('index'));
        },

        _goTo: function (index) {
            var count = this._slides.length;

            this._current = (index + count) % count;
            this._render();
        },

        _render: function () {
            var isRtl = $('html').attr('dir') === 'rtl',
                sign = isRtl ? 1 : -1,
                offsetPercent = sign * this._current * 100;

            this._track.css('transform', 'translateX(' + offsetPercent + '%)');
            this._dots.each(function (index, dot) {
                $(dot).attr('aria-selected', String(index === this._current));
            }.bind(this));
        }
    });

    return $.mage.spartrakHeroCarousel;
});
