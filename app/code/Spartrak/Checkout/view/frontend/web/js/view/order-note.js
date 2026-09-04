/**
 * Spartrak - the shopper's note on the order. Figma 552:11505 + 720:26810.
 *
 * Writes to `quote.customer_note`, which Magento already carries to
 * `sales_order.customer_note` through Magento_Sales' own fieldset.xml - so the
 * note reaches the admin order view and the confirmation email with no further
 * plumbing. See Spartrak\Checkout\Controller\Note\Save for why that column and
 * not a new one.
 *
 * ===========================================================================
 * WHY THE FORM KEY COMES FROM THE COOKIE
 * ===========================================================================
 * This is a state-changing POST, so Magento's CSRF validator requires a form
 * key. Magento mirrors the session's key into a `form_key` cookie for exactly
 * this case - an AJAX call with no surrounding <form> to read a hidden input
 * from. Reading it at submit time rather than at load time matters: the key is
 * rotated when a session is regenerated (on sign-in, for instance), and a key
 * captured when the component initialised would by then be stale.
 */
define([
    'ko',
    'jquery',
    'uiComponent',
    'mage/url',
    'mage/translate',
    'mage/cookies'
], function (ko, $, Component, urlBuilder, $t) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Spartrak_Checkout/order-note'
        },

        /** @inheritdoc */
        initialize: function () {
            this._super();

            this.note = ko.observable('');
            this.isSaving = ko.observable(false);

            /**
             * What is currently ON the quote, as far as this component knows.
             *
             * It exists for one case, and without it the control has a trap in
             * it: a shopper who saves a note and then wants to REMOVE it clears
             * the box, which makes it empty, which would disable the only
             * button that can tell the server about the change. They would be
             * stuck with a note they had already deleted on screen.
             *
             * So "empty" is only a reason to disable the button when there is
             * nothing saved to empty. See canSubmit().
             */
            this.savedNote = ko.observable('');
            // null = nothing said yet; the template shows no message at all
            // rather than an empty green line.
            this.feedback = ko.observable(null);
            this.hasError = ko.observable(false);

            // Any edit clears the previous outcome, so a stale "saved"
            // confirmation cannot sit under text that has since been changed
            // and not re-submitted.
            this.note.subscribe(function () {
                this.feedback(null);
                this.hasError(false);
            }, this);

            return this;
        },

        /**
         * Whether `أضف التعليق` is offered.
         *
         * Disabled while a save is in flight, and disabled on an empty box —
         * pressing it with nothing typed would post an empty note, get back
         * "Your note has been saved" and tell the shopper something happened
         * when nothing did.
         *
         * `.trim()` so whitespace counts as empty, which is the same answer the
         * controller reaches after its own trim.
         *
         * The exception is the clear case above: once something IS saved, an
         * empty box is a legitimate instruction to remove it.
         *
         * A plain function rather than a ko.computed on purpose — Knockout's
         * `enable` binding evaluates it inside its own dependency-tracking
         * context, so reading the three observables here is enough to make the
         * button react to all of them.
         *
         * @return {Boolean}
         */
        canSubmit: function () {
            if (this.isSaving()) {
                return false;
            }

            return this.note().trim() !== '' || this.savedNote() !== '';
        },

        /**
         * @return {void}
         */
        save: function () {
            var self = this;

            if (!this.canSubmit()) {
                return;
            }

            this.isSaving(true);

            $.ajax({
                url: urlBuilder.build('spartrak_checkout/note/save'),
                type: 'POST',
                dataType: 'json',
                data: {
                    // eslint-disable-next-line no-undef
                    'form_key': $.mage.cookies.get('form_key'),
                    note: self.note()
                }
            }).done(function (response) {
                var succeeded = !!(response && response.success);

                self.hasError(!succeeded);
                self.feedback(
                    (response && response.message) || $t('Your note has been saved.')
                );

                // Only on success: a failed save leaves the quote holding
                // whatever it held before, and recording the attempt would let
                // the button disable itself over a note the server never took.
                if (succeeded) {
                    self.savedNote(self.note().trim());
                }
            }).fail(function () {
                self.hasError(true);
                self.feedback($t('We could not save your note. Please try again.'));
            }).always(function () {
                self.isSaving(false);
            });
        }
    });
});
