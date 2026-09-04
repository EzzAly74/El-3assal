/**
 * Spartrak — the mobile PLP filter drawer (Figma 864:20389).
 *
 * ===========================================================================
 * NO DEPENDENCIES. THAT IS THE POINT OF THIS FILE'S SHAPE.
 * ===========================================================================
 * All this does is toggle one class on <html>; everything visual — the panel,
 * the backdrop, the page behind it not scrolling — is CSS keyed off
 * `spartrak-filters-open`. It measures nothing, animates nothing and writes no
 * inline style, so opening the drawer costs no layout work on the main thread.
 *
 * It used to be a jQuery UI widget, and that cost it three rounds of silent
 * failure: the drawer needed jQuery to load, then the widget factory to load,
 * then $.widget to register, before a single click could do anything. One of
 * those links was broken (the factory was never declared as a dependency, so
 * $.widget was undefined and this module threw on its first statement) and the
 * symptom was indistinguishable from a CSS bug — the button simply did
 * nothing and .sidebar-main kept visibility:hidden.
 *
 * So the dependencies are gone rather than fixed. `define([])` cannot fail to
 * resolve, and there is no factory to be missing. This is also the cheaper
 * shape by every measure CLAUDE.md section 13 ranks: less JavaScript, no
 * library on the critical path, fewer bytes.
 *
 * ===========================================================================
 * WHY A PLAIN FUNCTION AND NOT A WIDGET
 * ===========================================================================
 * Magento's own mage/apply/main.js supports it directly:
 *
 *     if (_.isFunction(fn)) {
 *         fn = fn.bind(null, config, el);
 *     }
 *
 * A module whose export is a function is called with (config, element). No
 * jQuery plugin lookup, no widget bridge. The element is not even used here —
 * see the note on bind() below.
 *
 * ===========================================================================
 * WHAT STILL WORKS WITH NO JAVASCRIPT
 * ===========================================================================
 * The sidebar is a normal block in the page. Without this file the class is
 * never set, the CSS never turns it into a panel, and the filters render
 * inline below the grid — usable, just not a drawer. Nothing is hidden that
 * cannot be revealed again.
 */
define([], function () {
    'use strict';

    var OPEN_CLASS = 'spartrak-filters-open',
        root = document.documentElement,
        bound = false;

    /**
     * Keeps every trigger's aria-expanded in step with the drawer. Plural
     * because the toolbar renders on both the category and search routes and
     * Mageplaza's AJAX layer can leave more than one in the document.
     *
     * @param {String} state - 'true' or 'false'
     */
    function setExpanded(state) {
        var triggers = document.querySelectorAll('[data-filter-drawer-open]'),
            i;

        for (i = 0; i < triggers.length; i++) {
            triggers[i].setAttribute('aria-expanded', state);
        }
    }

    function open() {
        root.classList.add(OPEN_CLASS);
        setExpanded('true');
    }

    function close() {
        root.classList.remove(OPEN_CLASS);
        setExpanded('false');
    }

    /**
     * ONE listener on `document`, not one per trigger.
     *
     * The trigger lives in Magento's toolbar and the close controls live in
     * Mageplaza's sidebar — two blocks this file does not own, either of which
     * can be replaced wholesale by the AJAX layer. Delegating from `document`
     * means a re-rendered button is still wired without re-running anything.
     *
     * `bound` makes that safe: mage/apply re-runs after every AJAX filter and
     * calls this module's export again, and binding a second identical
     * listener would fire every handler twice.
     */
    function bind() {
        if (bound) {
            return;
        }

        bound = true;

        document.addEventListener('click', function (event) {
            var target = event.target;

            // A click can land on a text node or on `document` itself.
            if (!target || typeof target.closest !== 'function') {
                return;
            }

            if (target.closest('[data-filter-drawer-open]')) {
                event.preventDefault();
                open();

                return;
            }

            if (target.closest('[data-filter-drawer-close]')) {
                event.preventDefault();
                close();

                return;
            }

            /*
             * The scrim IS the sidebar wrapper, so a click on the wrapper
             * itself — and never one that bubbled up out of the panel inside
             * it — is a backdrop dismiss. Without the identity test, choosing
             * a filter would close the drawer before its link could navigate.
             */
            if (target.classList && target.classList.contains('sidebar-main')) {
                close();
            }
        });

        /*
         * Escape closes, matching every other dismissible surface in this
         * theme. The drawer cannot be a native <dialog>: the sidebar is
         * server-rendered where Magento puts it, and moving that node into one
         * would break Mageplaza's AJAX layer, which re-renders it in place.
         */
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && root.classList.contains(OPEN_CLASS)) {
                close();
            }
        });
    }

    return function () {
        bind();
    };
});
