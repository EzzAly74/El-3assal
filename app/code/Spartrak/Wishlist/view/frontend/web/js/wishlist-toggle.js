/**
 * Spartrak — the product card's wishlist heart.
 *
 * ===========================================================================
 * ONE COMPONENT FOR THE WHOLE PAGE, NOT ONE PER CARD
 * ===========================================================================
 * A homepage rail can carry thirty cards and a category grid more. A widget
 * per heart would mean thirty jQuery UI instances, thirty element bindings and
 * thirty customer-data subscriptions to serve one shared piece of state, and
 * every one of them would be constructed during the page's initial JS
 * execution - the phase CLAUDE.md section 4 lists first.
 *
 * So there is exactly one of everything:
 *
 *   one click listener   delegated on `document`, so a heart works whether it
 *                        was server-rendered or inserted later
 *   one subscription     to the `wishlist` customer-data section
 *   one paint pass       driven off that subscription, which repaints EVERY
 *                        heart from the same data - including the second card
 *                        for the same product further down the page, which a
 *                        per-button update would leave stale
 *
 * ===========================================================================
 * WHERE THE STATE COMES FROM, AND WHY IT CANNOT COME FROM THE MARKUP
 * ===========================================================================
 * The card is inside full-page-cached HTML, so `aria-pressed` is rendered
 * `false` for everyone and this file is what makes it true. The set of
 * wish-listed product ids arrives on the `wishlist` customer-data section
 * (Spartrak\Wishlist\Plugin\CustomerData\AddProductIds), which the page is
 * fetching anyway for the header badge - so the hearts cost no request of
 * their own. See that plugin for why membership is private content.
 *
 * On a repeat visit customer-data is served from localStorage synchronously,
 * so the first paint happens with no network at all.
 *
 * ===========================================================================
 * WHY THE SERVER IS ASKED WHICH DIRECTION THE PRESS MEANT
 * ===========================================================================
 * The endpoint is a TOGGLE and the response says what it did; this file never
 * decides "this is an add" from what it has painted. Its own view of the list
 * can be stale - a second tab, an expired section, a wish list changed on
 * another device - and the one thing worse than a grey heart on a saved
 * product is a press that then removes it. See Controller\Ajax\Toggle.
 *
 * ===========================================================================
 * NO GLOBAL LOADER FOR A 40px CONTROL
 * ===========================================================================
 * `showLoader: false`, deliberately. The project loader (CLAUDE.md section 10)
 * is a page-level overlay; throwing it up for a heart press would black out
 * the viewport for ~100ms of round trip. The button carries `aria-busy` for
 * the duration instead, which the stylesheet animates in place and which is
 * also the correct thing for assistive tech.
 */
define([
    'jquery',
    'Magento_Customer/js/customer-data',
    'mage/cookies'
], function ($, customerData) {
    'use strict';

    var TOGGLE = '[data-spartrak-wishlist]',

        /**
         * Module-level, so a layout that manages to render the init block
         * twice still produces one listener and one subscription rather than
         * two of each firing on every press.
         */
        started = false,

        /** productId (as a string key) -> true. Rebuilt on every section update. */
        saved = {},

        /** Presses awaiting a response, so a double-click cannot double-post. */
        inFlight = {},

        options = {};

    /**
     * Writes a single heart's state, and ONLY when it actually changed.
     *
     * The guard is not premature: this runs for every heart on the page on
     * every customer-data update, and setting `aria-pressed` to the value it
     * already holds still invalidates the element's style and re-runs the
     * `[aria-pressed="true"]` match. On a 40-card grid that is 40 pointless
     * invalidations per update.
     *
     * @param {HTMLElement} button
     */
    function paint(button) {
        var id = button.getAttribute('data-spartrak-wishlist'),
            on = Object.prototype.hasOwnProperty.call(saved, id),
            next = on ? 'true' : 'false';

        if (button.getAttribute('aria-pressed') === next) {
            return;
        }

        button.setAttribute('aria-pressed', next);

        // The accessible name has to follow the state: a control whose name
        // stays "Add to Wish List" while it is pressed tells a screen-reader
        // user the opposite of what it does (CLAUDE.md section 15). The two
        // strings are translated ONCE, server-side, into this component's
        // options - not emitted as data attributes on every card, which on a
        // 40-card grid would be 40 copies of both.
        button.setAttribute('aria-label', on ? options.labelRemove : options.labelAdd);
        button.setAttribute('title', on ? options.labelRemove : options.labelAdd);
    }

    /**
     * @param {Document|HTMLElement} [root]
     */
    function paintAll(root) {
        var buttons = (root || document).querySelectorAll(TOGGLE),
            i;

        for (i = 0; i < buttons.length; i++) {
            paint(buttons[i]);
        }
    }

    /**
     * @param {Object} data the `wishlist` customer-data section
     */
    function onSection(data) {
        var ids = data && data.product_ids,
            next = {},
            i;

        // An absent key means "not known yet" (the section has never been
        // fetched), and the hearts are left exactly as they are rather than
        // being cleared - clearing would flash every saved heart grey on the
        // first load of every page.
        if (!$.isArray(ids)) {
            return;
        }

        for (i = 0; i < ids.length; i++) {
            next[String(ids[i])] = true;
        }

        saved = next;
        paintAll();
    }

    /**
     * A signed-out shopper. Opens the Spartrak auth modal in place, exactly
     * the way Magento's own sign-in prompt is routed to it
     * (js/spartrak-auth-popup-mixin.js), and falls back to navigating only
     * when the modal is not on the page - so the heart is never a dead
     * control because an enhancement did not load.
     */
    function promptSignIn() {
        var modal = $('.spartrak-auth');

        if (modal.length
            && typeof modal.spartrakAuth === 'function'
            && modal.spartrakAuth('instance')
        ) {
            modal.spartrakAuth('open', 'login');

            return;
        }

        if (options.signInUrl) {
            window.location.href = options.signInUrl;
        }
    }

    /**
     * @param {jQuery.Event} event
     */
    function onClick(event) {
        var button = event.currentTarget,
            id = button.getAttribute('data-spartrak-wishlist');

        // The control is a <button type="button"> and navigates nowhere, so
        // there is no default to prevent - but it can sit inside a card whose
        // whole surface is a link, and that click must not be followed.
        event.preventDefault();
        event.stopPropagation();

        if (!id || inFlight[id]) {
            return;
        }

        inFlight[id] = true;
        button.setAttribute('aria-busy', 'true');

        $.ajax({
            url: options.toggleUrl,
            type: 'POST',
            dataType: 'json',
            // The form key Magento's CsrfValidator requires before the
            // controller is entered at all. Read from the cookie rather than
            // printed into the page, because the page is full-page cached and
            // a baked-in key goes stale;
            // Magento_PageCache/js/form-key-provider keeps the cookie valid.
            data: {
                product: id,
                form_key: $.mage.cookies.get('form_key')
            },
            showLoader: false
        }).done(function (response) {
            if (!response) {
                return;
            }

            if (response.requires_login) {
                promptSignIn();

                return;
            }

            if (!response.ok) {
                return;
            }

            /*
             * Painted from the RESPONSE, before customer-data comes back, so
             * the heart turns over on the press rather than a round trip
             * later. The section update that follows is what makes it true
             * for every other copy of this product on the page - this is the
             * head start, not the source of truth.
             */
            if (response.added) {
                saved[id] = true;
            } else {
                delete saved[id];
            }

            paint(button);
        }).always(function () {
            delete inFlight[id];
            button.removeAttribute('aria-busy');
        });
    }

    /**
     * @param {Object} config emitted by templates/toggle-init.phtml
     */
    return function (config) {
        if (started) {
            return;
        }

        started = true;
        options = config || {};

        $(document).on('click', TOGGLE, onClick);

        // Anything Magento inserts dynamically announces itself with
        // `contentUpdated`; cards arriving that way get painted without this
        // file having to observe the whole document for mutations.
        $(document).on('contentUpdated', function (event) {
            paintAll(event.target);
        });

        customerData.get('wishlist').subscribe(onSection);
        onSection(customerData.get('wishlist')());
    };
});
