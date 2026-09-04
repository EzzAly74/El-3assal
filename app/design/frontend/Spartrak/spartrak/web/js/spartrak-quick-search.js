/**
 * Spartrak — opens and closes the "البحث السريع" quick-search dialog.
 *
 * ===========================================================================
 * THE BROWSER IS THE MODAL. THIS WIDGET ONLY PRESSES THE BUTTON.
 * ===========================================================================
 * The dialog is a native <dialog>, so `showModal()` supplies — with no library
 * and no CSS of ours — the backdrop, focus trapped inside the panel, focus
 * returned to the trigger on close, Escape to dismiss, `aria-modal="true"`
 * semantics, and the rest of the page made inert. Magento's own modal widget
 * would pull in jQuery UI dialog and then have to be styled out of its own
 * chrome to reach this design.
 *
 * So everything here is plumbing: three listeners and a feature test. Nothing
 * in this file animates, measures or writes a style.
 *
 * ===========================================================================
 * WHAT HAPPENS WITHOUT IT
 * ===========================================================================
 * A <dialog> with no `open` attribute is display:none, so if this file never
 * loads the button is inert rather than broken — it opens nothing and reveals
 * nothing half-rendered. The cascade inside is initialised by its own widget
 * independently of this one, so a failure here cannot break the finder and a
 * failure there cannot stop the dialog opening.
 *
 * The feature test is for `showModal` specifically, not for `HTMLDialogElement`:
 * a browser old enough to lack the method would otherwise throw on click and
 * take the rest of the page's JS with it.
 */
define(['jquery', 'jquery-ui-modules/widget'], function ($) {
    'use strict';

    $.widget('mage.spartrakQuickSearch', {
        _create: function () {
            this.dialog = this.element[0];

            // The widget is bound to the <dialog> itself, so a browser with no
            // showModal() support gets no listeners at all rather than a
            // button that throws.
            if (!this.dialog || typeof this.dialog.showModal !== 'function') {
                return;
            }

            // The trigger lives OUTSIDE the dialog — it is the button on the
            // promo banner, a sibling — so it is looked up from the document,
            // scoped to the section the two share.
            this.trigger = $(this.dialog)
                .closest('.spartrak-cat-promo')
                .find('[data-quick-search-open]');

            this._on(this.trigger, { click: this._open });
            this._on(this.element.find('[data-quick-search-close]'), { click: this._close });

            // Clicking the backdrop closes. The backdrop is not a node, so
            // there is nothing to bind to it: a click that lands on the
            // <dialog> element itself rather than on the panel inside it IS a
            // backdrop click, because the panel covers the dialog's whole
            // content box.
            this._on(this.element, { click: this._onDialogClick });
        },

        _open: function (event) {
            event.preventDefault();
            this.dialog.showModal();
        },

        _close: function (event) {
            event.preventDefault();
            this.dialog.close();
        },

        _onDialogClick: function (event) {
            if (event.target === this.dialog) {
                this.dialog.close();
            }
        }
    });

    return $.mage.spartrakQuickSearch;
});
