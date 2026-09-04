/**
 * Spartrak — the product rating dialog (Figma 1207:30485 / 1204:27392).
 *
 * ===========================================================================
 * WHAT IS *NOT* IN THIS FILE, AND WHY THAT IS THE WHOLE DESIGN
 * ===========================================================================
 * The element this attaches to is a native `<dialog>`, so the browser already
 * owns every hard part:
 *
 *   the top layer        nothing on the page can stack over it — which is a
 *                        real bug this project has already had once, with the
 *                        cart drawer covering a confirmation dialog
 *                        (components/_modal.less records it)
 *   ::backdrop           the scrim, painted by the UA, no extra element
 *   Escape               dismiss, for free
 *   focus containment    Tab cannot leave the dialog
 *   inertness            the rest of the page is not clickable or readable to
 *                        assistive tech while it is open
 *
 * Magento_Ui/js/modal does all of that in JavaScript, and reaching for it here
 * would have pulled in jQuery UI's dialog widget and the underscore templates
 * it depends on to reproduce behaviour the platform underneath already has
 * (CLAUDE.md section 13: less JavaScript, fewer bytes, less to go wrong).
 *
 * So what is left is genuinely small: open it, close it, keep the scroll lock
 * in step, and paint the browser's own validation message into the design's
 * own error slot.
 *
 * ===========================================================================
 * NO DEPENDENCIES, AND A PLAIN FUNCTION EXPORT
 * ===========================================================================
 * The same shape as js/spartrak-plp-filter-toggle.js, for the reason recorded
 * there: `define([])` cannot fail to resolve, there is no jQuery UI widget
 * factory to be missing, and Magento's own mage/apply/main.js calls a function
 * export directly with (config, element). A widget here would add three links
 * to the chain between a click and the dialog opening, and this project has
 * already lost a control to exactly that.
 *
 * ===========================================================================
 * WITHOUT THIS FILE
 * ===========================================================================
 * The CTA does nothing and the dialog never opens — but nothing is broken and
 * nothing is hidden that the shopper had before: the panel, the average, the
 * counts and the bars are all server-rendered and fully readable. The FORM is
 * a real POST form with a real form key, so it is the enhancement that is
 * optional, not the submission.
 */
define([], function () {
    'use strict';

    /**
     * On <html>, not on <body>: the stylesheet locks the scroll here, and
     * `overflow: hidden` on the root element is what actually stops a mobile
     * Safari page scrolling behind an overlay.
     */
    var OPEN_CLASS = 'spartrak-review-dialog-open',
        OPEN_TRIGGER = '[data-review-dialog-open]',
        CLOSE_TRIGGER = '[data-review-dialog-close]',
        root = document.documentElement;

    /**
     * @param {Object} config - `externalTriggers`: extra selector(s) that
     *        should also open this dialog. Magento's own rating summary in the
     *        product-info column links to `#review-form`, an anchor core
     *        renders and this design does not; naming it here is what keeps
     *        that link working instead of leaving it a dead jump.
     * @param {HTMLElement} element - the <dialog>
     * @return {void}
     */
    return function (config, element) {
        var dialog = element,
            form,
            hint,
            hintText,
            openers,
            // The control that opened the dialog, so focus can be handed back
            // to it on close — a dialog that dumps focus at the top of the
            // document loses a keyboard user their place (CLAUDE.md §15).
            opener = null,
            // True for the duration of ONE constraint-validation pass, so the
            // FIRST invalid control is the one reported and focused rather
            // than the last.
            reporting = false;

        if (!dialog) {
            return;
        }

        form = dialog.querySelector('form');
        hint = dialog.querySelector('[data-review-dialog-error]');
        hintText = hint ? hint.querySelector('.spartrak-review-dialog__hint-text') : null;

        openers = OPEN_TRIGGER +
            (config && config.externalTriggers ? ', ' + config.externalTriggers : '');

        function show() {
            /**
             * The `[open]` branch is not dead code for a browser that lacks
             * showModal(): the dialog is styled as a fixed, centred overlay
             * either way, so it still appears and its close button still works
             * — it simply is not modal. Better than a CTA that does nothing.
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
                // Escape-key dismissal and a button dismissal go through the
                // same path rather than two that can drift.
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

        function showHint(message) {
            if (!hint || !hintText || !message) {
                return;
            }

            hintText.textContent = message;
            hint.hidden = false;
        }

        function hideHint() {
            if (!hint) {
                return;
            }

            hint.hidden = true;
        }

        /**
         * ONE listener on `document`, not one per trigger: the panel's CTA and
         * Magento's summary link sit in different parts of the page, and the
         * summary block is re-rendered by nothing here but is cached
         * separately, so binding per element would depend on render order.
         */
        document.addEventListener('click', function (event) {
            var trigger = event.target.closest ? event.target.closest(openers) : null;

            if (!trigger) {
                return;
            }

            // The summary trigger is an <a href="...#review-form">. Opening the
            // dialog instead of following it is the point.
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
             * scrim: every pixel of the panel belongs to the <form> inside,
             * and the dialog element carries no padding of its own (see the
             * stylesheet), so there is no third region this could be.
             */
            if (event.target === dialog) {
                hide();
            }
        });

        dialog.addEventListener('close', released);

        if (!form) {
            return;
        }

        /**
         * CAPTURE, because `invalid` does not bubble — a capturing listener on
         * an ancestor still sees it on the way down, which is what makes one
         * listener on the form enough for six controls.
         *
         * preventDefault() suppresses the browser's own validation bubble. The
         * VALIDATION is still the browser's — `required` on the textarea and on
         * the radio group is what refuses the submit, and it does so with
         * JavaScript disabled too. Only the PRESENTATION is taken over, so the
         * message lands in the slot Figma draws for it (1204:27430) instead of
         * in a UA popup that no stylesheet can reach.
         *
         * ONE SLOT SERVES BOTH CONTROLS, because Figma draws one. A missing
         * star therefore reports under the comment field — not ideal, and the
         * focus makes up for it: the first invalid control is focused, so the
         * star row lights up its focus ring and points at itself while the
         * sentence explains what is wrong. Inventing a second error slot the
         * design does not have would be the worse trade.
         */
        form.addEventListener('invalid', function (event) {
            event.preventDefault();

            if (reporting) {
                return;
            }

            reporting = true;
            showHint(event.target.validationMessage);

            if (typeof event.target.focus === 'function') {
                event.target.focus();
            }

            // The pass ends with the current task; the flag is cleared after it
            // so the next submit attempt reports afresh.
            setTimeout(function () {
                reporting = false;
            }, 0);
        }, true);

        // Any edit is an attempt to fix the thing that was wrong, so the
        // message goes as soon as one is made rather than sitting there
        // contradicting the field.
        form.addEventListener('input', hideHint);
        form.addEventListener('change', hideHint);
    };
});
