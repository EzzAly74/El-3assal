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
 * `Magento_Checkout/js/sidebar` returns `$.mage.sidebar`, a jQuery UI widget
 * CONSTRUCTOR. A constructor has no `.extend()` — that is the uiClass idiom for
 * UI components — so it is re-declared through `$.widget` with the original as
 * its base, which is the widget factory's own inheritance mechanism.
 *
 * ===========================================================================
 * THE COMMIT MUST BELONG TO THE SHOPPER — MEASURED, WITH A STACK TRACE
 * ===========================================================================
 * Choosing a quantity appeared to work and then snapped back to the old value
 * about a second later. Two requests were going out per change, in this order:
 *
 *     POST checkout/sidebar/updateItemQty   item_id=85&item_qty=5   <- the shopper
 *     GET  customer/section/load?sections=cart                       <- cart is 5
 *     POST checkout/sidebar/updateItemQty   item_id=85&item_qty=1   <- nobody
 *     GET  customer/section/load?sections=cart                       <- cart is 1
 *
 * The second one is Knockout, not a shopper. When the cart section reloads, the
 * `foreach` over the lines re-renders and the `options` binding rebuilds this
 * <select>. Knockout ends that rebuild by firing a `change` event so that
 * anything watching the field learns the selection moved — and it fires it at
 * the moment the freshly built list has defaulted to its FIRST option, before
 * the `value` binding has put the real quantity back. Core survives this
 * because in core a `change` only reveals a button and a shopper still has to
 * click it. Here `change` IS the commit, so Knockout's housekeeping event
 * committed "1" over the shopper's "5", and the reload it triggered rebuilt the
 * list again — the visible "works for a second, then reverts".
 *
 * The captured stack ended in `Object.trigger` -> `jQuery.fn.trigger`, which
 * names the mechanism precisely: `ko.utils.triggerEvent()` calls
 * `jQuery(element).trigger(type)` whenever jQuery is on the page. jQuery's
 * `.trigger()` does not dispatch a browser event — it walks its own handler
 * list — and the jQuery.Event it fabricates therefore has NO `originalEvent`.
 * An event the browser dispatched always has one. Verified on the live drawer,
 * both paths through the same handler:
 *
 *     $(select).trigger('change')                        -> originalEvent undefined
 *     select.dispatchEvent(new Event('change'))          -> originalEvent set
 *
 * So `event.originalEvent` is exactly the question being asked — "did this come
 * from the user agent?" — and it is jQuery's own documented property, not a
 * heuristic. It is read below and nothing else about core's flow changes.
 *
 * WHY THE FLAG IS SET IN A SEPARATE HANDLER. `_showItemButton` is handed the
 * FIELD, not the event, so it cannot inspect the event itself; and core's
 * handler is the one that calls it. Registering ahead of `_super()` is what
 * puts this widget's handler first in the delegated chain on the same element,
 * so the flag is already correct by the time core routes the same event in.
 */
define([
    'jquery',
    'jquery-ui-modules/widget'
], function ($) {
    'use strict';

    return function (targetWidget) {
        $.widget('mage.sidebar', targetWidget, {

            /**
             * Binds the intent recorder BEFORE core binds the handlers that
             * consume it.
             *
             * jQuery UI dispatches delegated handlers bound to the same element
             * in registration order, and core registers its own inside
             * `_initContent()`, which `_super()` reaches. So this must run
             * first, and the ordering is the reason for the override.
             *
             * The selector is read from the widget's own options rather than
             * restated, so it cannot drift from the one core is listening on
             * (Magento_Checkout/js/view/minicart.js sets it to
             * `:input.cart-item-qty`).
             *
             * @private
             */
            _create: function () {
                var qty = this.options.item && this.options.item.qty,
                    events = {};

                if (qty) {
                    events['change ' + qty] = '_recordQtyIntent';
                    events['keyup ' + qty] = '_recordQtyIntent';

                    this._on(this.element, events);
                }

                this._super();
            },

            /**
             * Records whether the user agent raised this event, or Knockout did.
             *
             * @param {jQuery.Event} event
             * @private
             */
            _recordQtyIntent: function (event) {
                this.qtyChangedByShopper = event.originalEvent !== undefined;
            },

            /**
             * Commit instead of revealing a button that is not in the design —
             * but only for a change the shopper actually made.
             *
             * The flag is cleared on the way through, so a later call that
             * arrives without a matching event (a re-render's echo, or another
             * module calling this method directly) can never inherit a shopper's
             * intent from an earlier one.
             *
             * @param {jQuery} elem - the quantity control that changed
             * @private
             */
            _showItemButton: function (elem) {
                var byShopper = this.qtyChangedByShopper;

                this.qtyChangedByShopper = false;

                if (!byShopper) {
                    return;
                }

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
