/**
 * Spartrak — commit a minicart quantity change without an Update button.
 *
 * ===========================================================================
 * WHY THIS EXISTS
 * ===========================================================================
 * Figma's cart drawer (820:16477) has no "Update" control. Magento's minicart
 * does, and it is load-bearing: core's sidebar widget binds `keyup` on the
 * quantity field to REVEAL that button, and the actual update request only
 * happens when the button is clicked.
 *
 * So the button cannot simply be deleted — deleting it deletes the update. It
 * is hidden instead (core's own `style="display:none"`, kept in the item
 * template), and this mixin supplies the missing half: when the quantity
 * changes, click it.
 *
 * ===========================================================================
 * WHY A MIXIN, AND WHY IT IS THIS SMALL
 * ===========================================================================
 * A mixin extends Magento_Checkout/js/sidebar in place — no core file is
 * touched, no widget is re-implemented, and core keeps ownership of the
 * request, the loader, the error handling and the section reload. All this
 * adds is one delegated event binding.
 *
 * `change` rather than `keyup` on purpose: it fires once when the value is
 * actually settled — on blur after typing, and immediately on a spinner click,
 * which core's keyup binding misses entirely. That last case is a real bug in
 * the stock minicart: click the spinner arrows and the number moves while the
 * cart does not.
 */
define([
    'jquery'
], function ($) {
    'use strict';

    return function (targetWidget) {
        $.widget('mage.sidebar', targetWidget, {

            /**
             * Adds the change binding on top of everything core already binds.
             *
             * @private
             */
            _initContent: function () {
                this._super();

                this._on(this.element, {
                    'change :input.cart-item-qty': function (event) {
                        var itemId = $(event.currentTarget).data('cart-item'),
                            button = $('#update-cart-item-' + itemId);

                        if (!button.length) {
                            return;
                        }

                        // Core's own handler. It reads the quantity straight
                        // out of the field, so nothing is passed to it and
                        // nothing about its behaviour changes.
                        button.trigger('click');
                    }
                });
            }
        });

        return $.mage.sidebar;
    };
});
