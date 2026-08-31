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
         * @return {Boolean}
         */
        canSubmit: function () {
            return !this.isSaving();
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
                self.hasError(!response || !response.success);
                self.feedback(
                    (response && response.message) || $t('Your note has been saved.')
                );
            }).fail(function () {
                self.hasError(true);
                self.feedback($t('We could not save your note. Please try again.'));
            }).always(function () {
                self.isSaving(false);
            });
        }
    });
});
