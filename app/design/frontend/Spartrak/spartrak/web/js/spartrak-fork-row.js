/**
 * Spartrak Homepage Fork Row (Shop by Machine/Brand selector).
 *
 * Model dropdown is intentionally disabled/static in Phase 5 (see
 * sections.phtml's comment: real per-brand model population needs an AJAX
 * endpoint that's Module 2/Phase 6 territory). This widget only combines
 * the selected brand into the native search query on submit so the "Show
 * results" button does something real today, rather than leaving it a
 * dead button until that module exists.
 */
define([
    'jquery',
    'jquery/ui'
], function ($) {
    'use strict';

    $.widget('mage.spartrakForkRow', {
        options: {},

        _create: function () {
            this._on({
                submit: this._handleSubmit,
            });
        },

        _handleSubmit: function (event) {
            var brand = this.element.find('[name="spartrak_brand"]').val();
            var queryField = this.element.find('[data-fork-query]');

            if (!brand) {
                event.preventDefault();
                this.element.find('[name="spartrak_brand"]').trigger('focus');
                return;
            }

            queryField.val(brand);
        }
    });

    return $.mage.spartrakForkRow;
});
