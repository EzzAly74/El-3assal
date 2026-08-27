/**
 * Spartrak "Shop by categories" browse panel.
 *
 * QA FIX (2026-08-25): the first pass attached one flyout per L1 nav item,
 * opened by hovering that item. Live testing showed two problems: the panel
 * should open from the "تسوق حسب الفئات" CTA, not from hovering an L1 item,
 * and the old layout had a real hover dead-zone (a 16px gap between trigger
 * and panel) that dropped the panel before the pointer could reach it. Both
 * are fixed structurally in the markup/CSS (topmenu.phtml and
 * _mega-nav.less) — the trigger and panel are now DOM siblings inside one
 * `.spartrak-mega-nav__browse` wrapper, and `:hover`/`:focus-within` on that
 * wrapper is what shows the panel, with no gap to lose hover in. This widget
 * only handles what CSS still can't:
 *
 *  1. L1 TAB SWITCHING inside the panel — which L1's group of L2s is shown.
 *  2. THE SCRIM — dim/blur the rest of the page while the panel is open.
 *     Needs to react to hover/focus entering .spartrak-mega-nav__browse,
 *     which a plain CSS sibling selector can't do without `:has()`.
 *  3. Escape-to-close, moving focus back to the trigger.
 *
 * Every listener is bound within this.element (the nav root), never
 * document-level (10-THEME-ARCHITECTURE.md JS architecture rule 2).
 */
define([
    'jquery',
    'jquery/ui'
], function ($) {
    'use strict';

    $.widget('mage.spartrakMegaNav', {
        options: {},

        _create: function () {
            var browse = this.element.find('.spartrak-mega-nav__browse');

            this._on({
                'click [data-flyout-tab]': this._handleTabActivate,
                'keydown .spartrak-mega-nav__shop-by-categories': this._handleTriggerKeydown
            });
            // mouseenter/mouseleave/focusin/focusout don't bubble the way a
            // delegated map on this.element can use, so they're bound
            // directly to .browse via the widget factory's own
            // "element, handlers" _on() form.
            this._on(browse, {
                mouseenter: this._showScrim,
                focusin: this._showScrim,
                mouseleave: this._hideScrim
            });
        },

        _handleTabActivate: function (event) {
            var tab = $(event.currentTarget),
                flyout = tab.closest('.spartrak-mega-nav__flyout'),
                panelId = tab.attr('aria-controls');

            flyout.find('[data-flyout-tab]').attr('aria-selected', 'false');
            tab.attr('aria-selected', 'true');

            flyout.find('.spartrak-mega-nav__flyout-l1-panel').prop('hidden', true);
            flyout.find('#' + panelId).prop('hidden', false);
        },

        _handleTriggerKeydown: function (event) {
            if (event.key === 'Escape') {
                this._hideScrim();
                $(event.currentTarget).trigger('blur');
            }
        },

        _showScrim: function () {
            this.element.find('.spartrak-mega-nav__shop-by-categories').attr('aria-expanded', 'true');
            this.element.find('.spartrak-mega-nav__scrim').attr('data-visible', 'true');
        },

        _hideScrim: function () {
            this.element.find('.spartrak-mega-nav__shop-by-categories').attr('aria-expanded', 'false');
            this.element.find('.spartrak-mega-nav__scrim').removeAttr('data-visible');
        }
    });

    return $.mage.spartrakMegaNav;
});
