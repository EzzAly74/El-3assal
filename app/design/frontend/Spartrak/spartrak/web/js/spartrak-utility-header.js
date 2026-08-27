/**
 * Spartrak Header behaviour.
 *
 * Scoped entirely to this.element (the <header> root). No document-level
 * handlers: the two things that need cross-subtree wiring — the hamburger
 * drawer and the auth modal — already own their own delegated
 * [data-drawer-open] / [data-auth-open] handlers, so the markup here just
 * carries those attributes and needs no JS of its own.
 *
 * Three jobs, all of them things CSS genuinely cannot do:
 *
 *  1. ACCOUNT CHIP STATE AND NAME (Figma node 595:14513). Both chips are in
 *     the DOM; account-chip.phtml decides which one starts visible and this
 *     reconciles that guess against the customerData `customer` section,
 *     which is the authority. See _bindAccountState and account-chip.phtml's
 *     own header note for why the state cannot be settled in PHP alone — in
 *     short, an HTTP cache ahead of Magento can serve one visitor the HTML
 *     rendered for another, and no server-side branch survives that.
 *
 *     The NAME is client-side for a different and unchanged reason: a
 *     customer's name is not a cache-vary dimension, and making it one would
 *     give every shopper a private copy of every page.
 *
 *     HISTORY: 2026-08-26 removed the guest/signed-in toggle from here when
 *     that decision moved into PHP; 2026-08-27 restored it, with a guard
 *     (`data_id`) that the original pre-2026-08-25 version never had, so it
 *     can no longer demote a signed-in chip on a section that has simply not
 *     been fetched yet. The mobile drawer has used this same contract
 *     throughout and is the reference implementation.
 *
 *  2. ACCOUNT MENU disclosure. Figma draws the chevron but not the open panel,
 *     so this is the minimum: toggle, close on Escape, close on outside click.
 *
 *  3. SEARCH PLACEHOLDER per breakpoint. Figma specifies different placeholder
 *     copy at 1440 and at 440 (node 595:14526 vs 724:25853) and placeholder
 *     text cannot be swapped in CSS. Both strings come from the template as
 *     translatable attributes; this only chooses between them.
 *
 * REMOVED IN THIS PASS: the mobile search expand/collapse. Figma's 440 header
 * shows the search bar permanently expanded on its own row (node 724:25846),
 * so the toggle button, its markup and its handler are all gone — less DOM and
 * less JS than before, not more.
 *
 * Dependencies are jQuery, the jQuery UI widget factory and Magento's own
 * customer-data — all already on the page. No new library.
 */
define([
    'jquery',
    'Magento_Customer/js/customer-data',
    'jquery/ui'
], function ($, customerData) {
    'use strict';

    // Must match @breakpoint-header-collapse in
    // web/css/source/foundations/_breakpoints.less, which is Magento core's
    // and Porto's own @screen__m (768px) minus 1.
    var COMPACT_QUERY = '(max-width: 767px)';

    $.widget('mage.spartrakUtilityHeader', {
        options: {},

        _create: function () {
            this._on({
                'click [data-account-toggle]': this._toggleAccountMenu,
                keydown: this._handleKeydown
            });

            this._bindAccountState();
            this._bindSearchPlaceholder();
        },

        _destroy: function () {
            if (this._outsideHandler) {
                $(document).off('click.spartrakAccountMenu', this._outsideHandler);
            }

            if (this._placeholderQuery && this._placeholderListener) {
                // removeEventListener is the modern API; removeListener is the
                // deprecated fallback Safari < 14 needs.
                if (this._placeholderQuery.removeEventListener) {
                    this._placeholderQuery.removeEventListener('change', this._placeholderListener);
                } else if (this._placeholderQuery.removeListener) {
                    this._placeholderQuery.removeListener(this._placeholderListener);
                }
            }
        },

        /**
         * Reconcile the account chip against the customerData `customer`
         * section, and fill in the signed-in chip's name and avatar initial.
         *
         * customerData.get() returns an observable that fires again whenever
         * the section is invalidated, so signing in through the auth modal
         * updates the chip without a bespoke event — and without a page
         * reload, since customerData.invalidate() in spartrak-auth.js's
         * _finish() triggers exactly this subscription before the reload
         * happens.
         *
         * ------------------------------------------------------------------
         * FIXED 2026-08-27 — "shows تسجيل الدخول although I am logged in".
         * ------------------------------------------------------------------
         * The 2026-08-26 pass DELETED the guest/customer toggle from here,
         * because account-chip.phtml had moved that decision into PHP. The
         * template has moved it back (see its header note: an HTTP cache layer
         * ahead of Magento, visible as `x-cache-nxaccel` on live responses, can
         * hand one visitor the HTML rendered for another, which no server-side
         * branch can defend against). So the toggle is restored — but with the
         * one guard the pre-2026-08-25 version lacked.
         *
         * THE GUARD: never demote to the guest chip on an UNRESOLVED section.
         * `customerData.get('customer')()` returns `{}` before the section has
         * been fetched, which is indistinguishable from a genuine guest by
         * `firstname` alone — so a naive check would flip a correctly-rendered
         * signed-in chip to "sign in" on every page load, then flip it back a
         * moment later. `data_id` is what separates the two: Magento stamps it
         * onto EVERY section it returns, guest or not
         * (Magento\Customer\CustomerData\Section\Identifier::markSections), so
         * its presence means "this section really has been fetched" and its
         * absence means "not yet — leave the server's guess alone".
         *
         * Net effect: on a correctly-keyed response nothing moves at all, and
         * on a mis-keyed one the chip self-corrects in both directions.
         */
        _bindAccountState: function () {
            var element = this.element,
                guest = element.find('[data-account-guest]'),
                customer = element.find('[data-account-customer]'),
                name = element.find('[data-account-name]'),
                initial = element.find('[data-account-initial]'),
                self = this,
                apply;

            if (!guest.length && !customer.length && !name.length && !initial.length) {
                return;
            }

            apply = function (data) {
                var firstname = data && data.firstname ? String(data.firstname) : '';

                if (firstname !== '') {
                    self._setAccountState(guest, customer, true);
                    name.text(firstname);
                    // The avatar plate carries the customer's own initial
                    // instead of Figma's stock placeholder photograph — see
                    // account-chip.phtml for why no photo is shipped.
                    // Array.from, not charAt: an Arabic first name is
                    // multi-byte, and charAt(0) would slice a surrogate pair in
                    // half and render a replacement glyph.
                    initial.text(Array.from(firstname)[0] || '');

                    return;
                }

                // No name. Only treat that as "guest" once the section has
                // actually been fetched — see the docblock.
                if (data && data.data_id) {
                    self._setAccountState(guest, customer, false);
                }
            };

            apply(customerData.get('customer')());
            customerData.get('customer').subscribe(apply);
        },

        /**
         * @param {jQuery} guest
         * @param {jQuery} customer
         * @param {Boolean} signedIn
         */
        _setAccountState: function (guest, customer, signedIn) {
            if (signedIn) {
                guest.prop('hidden', true);
                customer.prop('hidden', false);

                return;
            }

            // Close before hiding: the signed-in chip's menu binds a
            // document-level outside-click handler while open, and hiding its
            // trigger would strand that handler with nothing left to close.
            this._closeAccountMenu();
            customer.prop('hidden', true);
            guest.prop('hidden', false);
        },

        _toggleAccountMenu: function (event) {
            var trigger = $(event.currentTarget),
                menu = this.element.find('#spartrak-account-menu'),
                wrapper = trigger.closest('[data-account-chip]'),
                isOpen = trigger.attr('aria-expanded') === 'true';

            event.preventDefault();

            if (isOpen) {
                this._closeAccountMenu();

                return;
            }

            menu.prop('hidden', false);
            trigger.attr('aria-expanded', 'true');
            wrapper.attr('data-account-open', 'true');

            // Bound lazily and torn down on close, so there is no permanent
            // document listener while the menu is shut.
            this._outsideHandler = $.proxy(function (e) {
                if (!$(e.target).closest('[data-account-chip]').length) {
                    this._closeAccountMenu();
                }
            }, this);
            $(document).on('click.spartrakAccountMenu', this._outsideHandler);
        },

        _closeAccountMenu: function () {
            var wrapper = this.element.find('[data-account-chip]');

            this.element.find('#spartrak-account-menu').prop('hidden', true);
            this.element.find('[data-account-toggle]').attr('aria-expanded', 'false');
            wrapper.removeAttr('data-account-open');

            if (this._outsideHandler) {
                $(document).off('click.spartrakAccountMenu', this._outsideHandler);
                this._outsideHandler = null;
            }
        },

        /**
         * Figma uses a long placeholder at 1440 and a short one at 440.
         * The long one is the markup default, so if matchMedia is unavailable
         * the desktop copy simply stands.
         */
        _bindSearchPlaceholder: function () {
            var input = this.element.find('input#search'),
                full,
                compact,
                apply;

            if (!input.length || !window.matchMedia) {
                return;
            }

            full = input.attr('placeholder');
            compact = input.data('placeholder-compact');

            if (!compact) {
                return;
            }

            this._placeholderQuery = window.matchMedia(COMPACT_QUERY);

            apply = function (matches) {
                input.attr('placeholder', matches ? compact : full);
            };

            this._placeholderListener = function (e) {
                apply(e.matches);
            };

            apply(this._placeholderQuery.matches);

            if (this._placeholderQuery.addEventListener) {
                this._placeholderQuery.addEventListener('change', this._placeholderListener);
            } else if (this._placeholderQuery.addListener) {
                this._placeholderQuery.addListener(this._placeholderListener);
            }
        },

        _handleKeydown: function (event) {
            if (event.key === 'Escape') {
                this._closeAccountMenu();
            }
        }
    });

    return $.mage.spartrakUtilityHeader;
});
