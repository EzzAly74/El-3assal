/**
 * Spartrak Mobile Hamburger Drawer.
 *
 * Scoped entirely to this.element (the drawer root) plus one delegated
 * document-level click ONLY for the external open-trigger button living in
 * the header (a separate component's markup — this is the one legitimate
 * exception to "never document-level," since the trigger and the drawer
 * are necessarily two different DOM subtrees; the delegation is narrowly
 * scoped to [data-drawer-open], not a generic document handler).
 */
define([
    'jquery',
    'Magento_Customer/js/customer-data',
    'jquery/ui'
], function ($, customerData) {
    'use strict';

    $.widget('mage.spartrakMobileDrawer', {
        options: {},

        _create: function () {
            this._on({
                'click [data-drawer-close]': this._close,
                'click [data-accordion-trigger]': this._toggleAccordion,
                'click [data-l1-expand]': this._toggleL1,
                // The auth modal's own document-level handler opens the overlay;
                // this only gets the drawer out from under it. Two handlers on
                // one click, each owning its own component.
                'click [data-auth-open]': this._close,
                keydown: this._handleKeydown
            });
            $(document).on('click.spartrakDrawerOpen', '[data-drawer-open]', $.proxy(this._open, this));
            this._bindAccountState();
        },

        /**
         * Swap the drawer's account row between guest and signed-in.
         *
         * Driven by customer section data rather than PHP, because this block is
         * block-cached inside a full-page-cached page — see the template comment.
         * customerData.get() returns an observable that fires again whenever the
         * section is invalidated, so signing in through the modal updates this
         * row without a bespoke event.
         */
        _bindAccountState: function () {
            var guest = this.element.find('[data-account-guest]'),
                authenticated = this.element.find('[data-account-customer]'),
                name = this.element.find('[data-account-name]'),
                initial = this.element.find('[data-account-initial]'),
                customer,
                apply;

            if (!guest.length) {
                return;
            }

            apply = function (data) {
                var signedIn = Boolean(data && data.firstname),
                    fullName;

                guest.prop('hidden', signedIn);
                authenticated.prop('hidden', !signedIn);

                if (signedIn) {
                    // .text(), never .html(): firstname is customer-supplied and
                    // arrives here as JSON, so it has had no HTML escaping applied.
                    //
                    // Figma's profile row (node 645:39283) shows the FULL name
                    // ("Karim Khaled"), unlike the desktop chip which shows only
                    // the first name — hence lastname is appended when present.
                    fullName = data.lastname ?
                        String(data.firstname) + ' ' + String(data.lastname) :
                        String(data.firstname);

                    name.text(fullName);

                    // Node 645:39284: the avatar plate carries initials ("KK").
                    // Array.from, not charAt: Arabic names are multi-byte and
                    // charAt(0) would split a surrogate pair into a replacement
                    // glyph.
                    initial.text(
                        fullName.split(/\s+/)
                            .filter(Boolean)
                            .slice(0, 2)
                            .map(function (part) {
                                return Array.from(part)[0] || '';
                            })
                            .join('')
                    );
                }
            };

            customer = customerData.get('customer');

            // Applied once with the value already in localStorage, THEN
            // subscribed. subscribe() alone only fires on a later change, so a
            // returning signed-in shopper would be shown the guest row until
            // something happened to invalidate the section.
            apply(customer());
            customer.subscribe(apply);
        },

        _destroy: function () {
            $(document).off('click.spartrakDrawerOpen');
        },

        /**
         * TOGGLE, not just open (changed 2026-08-25).
         *
         * Figma's menu view (node 645:39265) has no in-panel close button and
         * no scrim — the header's own hamburger swaps to an X and IS the close
         * control. So the one trigger has to work both ways; previously a
         * second tap on it re-ran open() and the menu could not be dismissed.
         *
         * Focus is no longer moved into the panel either: there is nothing to
         * move it to now that the close button is gone, and yanking focus off
         * the control the shopper just tapped is worse than leaving it there.
         */
        _open: function (event) {
            event.preventDefault();

            if (this.element.attr('data-open')) {
                this._close();

                return;
            }

            this._positionBelowHeader();

            this.element.attr('data-open', 'true').attr('aria-hidden', 'false');
            $(event.currentTarget).attr('aria-expanded', 'true');
            this._openedBy = event.currentTarget;

            // Stop the page behind the fixed panel scrolling with it.
            $('body').addClass('spartrak-drawer-open');
        },

        _close: function () {
            this.element.removeAttr('data-open').attr('aria-hidden', 'true');
            // Every trigger, not only the one that opened it — the desktop
            // "Shop by categories" CTA can also be a trigger, and a stale
            // aria-expanded="true" would leave that button showing the X glyph.
            $('[data-drawer-open]').attr('aria-expanded', 'false');
            $('body').removeClass('spartrak-drawer-open');
        },

        /**
         * The panel is fixed and must start exactly where the header ends.
         *
         * Measured rather than hardcoded because the header's height genuinely
         * varies: the promo banner, the utility strip and the two-row mobile
         * navbar each wrap differently by viewport width and by locale (Arabic
         * copy is longer than the English source). getBoundingClientRect gives
         * the real painted bottom edge, including any wrapping that happened.
         *
         * Written as a custom property so the value lives in CSS and the
         * stylesheet keeps ownership of the layout (_mobile-drawer.less reads
         * --spartrak-drawer-top with a 0 fallback).
         */
        _positionBelowHeader: function () {
            var header = document.querySelector('.spartrak-header'),
                bottom;

            if (!header) {
                return;
            }

            bottom = Math.max(0, Math.round(header.getBoundingClientRect().bottom));
            document.documentElement.style.setProperty('--spartrak-drawer-top', bottom + 'px');
        },

        _toggleAccordion: function (event) {
            var trigger = $(event.currentTarget),
                panel = trigger.next('.spartrak-mobile-drawer__group-panel'),
                expanded = trigger.attr('aria-expanded') === 'true';

            trigger.attr('aria-expanded', String(!expanded));
            panel.prop('hidden', expanded);
        },

        _toggleL1: function (event) {
            event.stopPropagation();
            var trigger = $(event.currentTarget),
                list = trigger.siblings('.spartrak-mobile-drawer__l2-list'),
                expanded = trigger.attr('aria-expanded') === 'true';

            trigger.attr('aria-expanded', String(!expanded));
            list.prop('hidden', expanded);
        },

        _handleKeydown: function (event) {
            if (event.key === 'Escape') {
                this._close();
            }
        }
    });

    return $.mage.spartrakMobileDrawer;
});
