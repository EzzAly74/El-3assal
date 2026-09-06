/**
 * Spartrak — the shared modal dialog.
 *
 * Drives every `<dialog class="spartrak-dialog">` in the project. Two use it
 * today: the contact panel (Figma 1179:24007) and the signed-out panel
 * (1280:27572). A third is markup plus one widget config, not another file.
 *
 * ===========================================================================
 * WHAT IS *NOT* IN THIS FILE, AND WHY THAT IS THE WHOLE DESIGN
 * ===========================================================================
 * The element is a native `<dialog>`, so the browser already owns every hard
 * part — the top layer, the `::backdrop` scrim, Escape, focus containment, and
 * making the rest of the page inert. The same reasoning
 * js/spartrak-review-dialog.js records at length; it is not repeated here.
 *
 * Reaching for `Magento_Ui/js/modal` instead would pull jQuery UI's dialog
 * widget and its underscore templates in to reproduce behaviour the platform
 * underneath already has (CLAUDE.md §13: less JavaScript, fewer bytes, less to
 * go wrong).
 *
 * This file is NOT a second copy of spartrak-review-dialog.js. That one owns a
 * FORM — constraint validation, error-slot painting, first-invalid focus — and
 * none of that applies here. What the two share is roughly fifteen lines of
 * show/hide, and the thing they genuinely share is the design language, which
 * lives in LESS. What this one adds and that one has no use for is the
 * fragment entry point below.
 *
 * ===========================================================================
 * THE FRAGMENT ENTRY POINT
 * ===========================================================================
 * Two server-side redirects hand control to this file:
 *
 *   Spartrak\Contact\Observer\RedirectContactPageToDialog
 *       /contact/  ->  <where you were>#dialog=contact
 *   Spartrak\CustomerAuth\Observer\RedirectLogoutSuccessToDialog
 *       /customer/account/logoutSuccess/  ->  <home>#dialog=signed-out
 *
 * A FRAGMENT and not a query parameter, because fragments are never sent to
 * the server: the shopper lands on exactly the same full-page-cache entry they
 * would have had without it, where `?dialog=contact` would fork a second cache
 * entry of every page anyone ever reached this way. Each observer's own
 * docblock carries the full note.
 *
 * The fragment is REMOVED from the URL as soon as it has been read. Left in
 * place it would re-open the dialog on every reload and on every back
 * navigation to that entry, which after signing out is the shopper being told
 * they signed out a second time.
 *
 * ===========================================================================
 * WITHOUT THIS FILE
 * ===========================================================================
 * The contact dialog never opens and its trigger falls back to being what the
 * markup already says it is: a real link to `/contact/`. The signed-out dialog
 * never opens and the shopper is simply on the home page, signed out, which is
 * the truth. Nothing is hidden that the shopper had before — the contact
 * details are also plain server-rendered markup in the footer.
 */
define([], function () {
    'use strict';

    /**
     * On <html>, not on <body>: `overflow: hidden` on the root element is what
     * actually stops a mobile Safari page scrolling behind an overlay. Same
     * finding as the review dialog's.
     */
    var OPEN_CLASS = 'spartrak-dialog-open',
        CLOSE_TRIGGER = '[data-spartrak-dialog-close]',
        FRAGMENT_PREFIX = '#dialog=',
        root = document.documentElement;

    /**
     * @param {Object} config - `id`: the token that names this dialog, both in
     *        its triggers' `data-spartrak-dialog-open` and in the `#dialog=`
     *        fragment the observers redirect to.
     * @param {HTMLElement} element - the <dialog>
     * @return {void}
     */
    return function (config, element) {
        var dialog = element,
            id = config && config.id,
            openTrigger,
            // The control that opened the dialog, so focus can be handed back
            // to it on close — a dialog that dumps focus at the top of the
            // document loses a keyboard user their place (CLAUDE.md §15).
            opener = null;

        if (!dialog || !id) {
            return;
        }

        openTrigger = '[data-spartrak-dialog-open="' + id + '"]';

        function show() {
            /**
             * The `[open]` branch is not dead code for a browser without
             * showModal(): the stylesheet lays this out as a fixed, centred
             * overlay either way, so it still appears and its close control
             * still works — it simply is not modal.
             */
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', '');
            }

            root.classList.add(OPEN_CLASS);
        }

        function hide() {
            if (typeof dialog.close === 'function' && dialog.open) {
                // Fires `close`, which is where the tidy-up lives — so an
                // Escape dismissal and a button dismissal go through one path
                // rather than two that can drift.
                dialog.close();

                return;
            }

            dialog.removeAttribute('open');
            released();
        }

        function released() {
            root.classList.remove(OPEN_CLASS);

            if (opener && typeof opener.focus === 'function') {
                opener.focus();
            }

            opener = null;
        }

        /**
         * ONE listener on `document`, not one per trigger: the contact dialog
         * is opened from the header strip AND from the footer, which are
         * rendered by different templates and cached independently, so binding
         * per element would depend on render order.
         */
        document.addEventListener('click', function (event) {
            var trigger = event.target.closest ? event.target.closest(openTrigger) : null;

            if (!trigger) {
                return;
            }

            // The triggers are real <a href="/contact/"> links so that they
            // still work with this file absent. Opening the dialog instead of
            // following them is the enhancement.
            event.preventDefault();
            opener = trigger;
            show();
        });

        dialog.addEventListener('click', function (event) {
            if (event.target.closest && event.target.closest(CLOSE_TRIGGER)) {
                hide();

                return;
            }

            /**
             * A click whose target is the DIALOG ITSELF is a click on the
             * scrim: every pixel of the panel belongs to the `__panel` child,
             * and the dialog element carries no padding of its own (see the
             * stylesheet), so there is no third region this could be.
             */
            if (event.target === dialog) {
                hide();
            }
        });

        dialog.addEventListener('close', released);

        // --- the fragment entry point ---------------------------------------
        if (window.location.hash === FRAGMENT_PREFIX + id) {
            /**
             * replaceState and not `location.hash = ''`, which would leave a
             * bare '#' behind AND push a history entry — so the back button
             * would return to the same URL rather than to the previous page.
             *
             * Guarded because a fragment-only rewrite is still a same-document
             * navigation, and a browser without history.replaceState is a
             * browser where re-opening on reload is the lesser problem.
             */
            if (window.history && typeof window.history.replaceState === 'function') {
                window.history.replaceState(
                    window.history.state,
                    '',
                    window.location.pathname + window.location.search
                );
            }

            show();
        }
    };
});
