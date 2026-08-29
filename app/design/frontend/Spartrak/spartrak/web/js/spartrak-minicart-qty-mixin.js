/**
 * Spartrak — commit a minicart quantity change with no Update button.
 *
 * ===========================================================================
 * WHY THIS EXISTS
 * ===========================================================================
 * Figma's cart drawer (820:16477) draws the quantity as a dropdown and draws
 * no "Update" control at all. Magento's minicart has one, and in stock core it
 * is load-bearing: `change` on the quantity field only REVEALS the button, and
 * the update request happens when the button is clicked.
 *
 * That extra step is the whole reason this file exists. It replaces core's
 * reveal with the commit itself, so choosing a number IS the update — which is
 * what a dropdown with no button beside it promises.
 *
 * ===========================================================================
 * WHY IT OVERRIDES _showItemButton RATHER THAN ADDING A HANDLER
 * ===========================================================================
 * The first version of this file bound its own `change` listener and clicked a
 * hidden button. That left core's handler running as well, so every change
 * still started `$('#update-cart-item-N').show('fade', 300)` — a 300ms jQuery
 * animation on an element the design says does not exist. Overriding the one
 * method core routes both `keyup` and `change` into removes the animation, the
 * hidden element and the synthetic click in one go.
 *
 * `_updateItemQty` is called with the FIELD, not the button, and that is safe:
 * read it in vendor/magento/module-checkout/view/frontend/web/js/sidebar.js —
 * it uses only `elem.data('cart-item')` and then reads the value back out of
 * `#cart-item-<id>-qty`. The <select> carries both. Core keeps ownership of
 * the request, the loader, the error handling and the section reload.
 *
 * `_isValidQty` is core's own guard: it returns false when the value has not
 * actually changed, so re-selecting the number that is already chosen sends
 * nothing.
 *
 * `Magento_Checkout/js/sidebar` returns `$.mage.sidebar`, a jQuery UI widget
 * CONSTRUCTOR. A constructor has no `.extend()` — that is the uiClass idiom for
 * UI components — so it is re-declared through `$.widget` with the original as
 * its base, which is the widget factory's own inheritance mechanism.
 */
define([
    'jquery',
    'jquery-ui-modules/widget'
], function ($) {
    'use strict';

    return function (targetWidget) {
        $.widget('mage.sidebar', targetWidget, {

            /**
             * Commit instead of revealing a button that is not in the design.
             *
             * @param {jQuery} elem - the quantity control that changed
             * @private
             */
            _showItemButton: function (elem) {
                if (this._isValidQty(elem.data('item-qty'), elem.val())) {
                    this._updateItemQty(elem);
                }
            },

            /**
             * Core hides the same button here. There is no button.
             *
             * @private
             */
            _hideItemButton: function () {
            },

            /**
             * Core's guard, made safe for a <select>.
             *
             * MEASURED, from a real console trace: core does `changed.length`,
             * and `.val()` on a <select> returns NULL when no <option> matches
             * the bound value, where `.val()` on an <input> always returned a
             * string. That is a direct consequence of swapping the control, so
             * handling it belongs here and not in core.
             *
             * It happens for real: between a cart re-render and Knockout
             * re-populating the list there is a frame where the select is empty,
             * and `focusout` in that frame took the whole widget down with a
             * TypeError — which also killed the remove button and the checkout
             * button, because they are bound by the same widget instance.
             *
             * A null value means "no quantity is selected", which is never a
             * valid change, so it returns false and core carries on.
             *
             * @param {*} origin - the quantity the line was rendered with
             * @param {*} changed - the quantity now in the control
             * @returns {Boolean}
             * @private
             */
            _isValidQty: function (origin, changed) {
                if (changed === null || changed === undefined || changed === '') {
                    return false;
                }

                return this._super(origin, String(changed));
            }
        });

        return $.mage.sidebar;
    };
});
