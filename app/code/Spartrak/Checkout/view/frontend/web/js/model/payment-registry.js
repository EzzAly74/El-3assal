/**
 * Spartrak - lets the single checkout CTA reach the chosen payment renderer.
 *
 * ===========================================================================
 * THE PROBLEM
 * ===========================================================================
 * Magento gives every payment method its own "Place Order" button, inside that
 * method's own template. This design does not: Figma has exactly one CTA, in
 * the order summary column, reading `ادفع الان 1,748.98 ج.م`.
 *
 * Placing an order is nonetheless the RENDERER's job and must stay there.
 * Paymob's renderer redirects to a hosted page afterwards; InstaPay's stores a
 * transfer record; cash on delivery just places. Reimplementing that sequence
 * in a summary component would be a second copy of every integration's
 * post-order behaviour, and it would silently go stale the first time one of
 * them changed.
 *
 * So the CTA does not place the order. It finds the renderer for the method
 * the shopper picked and calls that renderer's own placeOrder().
 *
 * ===========================================================================
 * WHY NOT uiRegistry
 * ===========================================================================
 * ===========================================================================
 * WHY IT LIVES IN Spartrak_Checkout AND NOT BESIDE THE MIXIN THAT FILLS IT
 * ===========================================================================
 * It is written by Spartrak_Payment's renderer mixin and read by this module's
 * checkout CTA, so it could have sat in either. It sits here because the
 * dependency has to point the same way as the module sequence: Spartrak_Payment
 * already sequences AFTER Spartrak_Checkout.
 *
 * The other arrangement had a real failure mode. With the registry in
 * Spartrak_Payment, a merchant who disabled that module - it is only
 * presentation, so disabling it looks harmless - would leave place-order.js
 * requiring a path that no longer resolves, and RequireJS would fail the whole
 * component. The checkout's only CTA would stop rendering because a cosmetic
 * module was switched off.
 *
 * This way round, the reader owns the contract and the writer is optional.
 *
 * uiRegistry can find components by name, but a renderer's name is built from
 * its parent's name plus the method code and differs between the one-page and
 * multi-shipping layouts, so a name query is a guess that breaks quietly. This
 * map is keyed on getCode(), which is the identity that actually matters and
 * is the same value quote.paymentMethod() reports.
 */
define([], function () {
    'use strict';

    var renderers = {};

    return {
        /**
         * Called by every renderer as it initialises.
         *
         * Late registrations overwrite earlier ones on purpose: Magento
         * destroys and rebuilds renderers when the available method list
         * changes (a coupon making a method free, an address changing what is
         * allowed), and the newest instance is the live one.
         *
         * @param {Object} renderer
         */
        register: function (renderer) {
            if (renderer && typeof renderer.getCode === 'function') {
                renderers[renderer.getCode()] = renderer;
            }
        },

        /**
         * @param {String} code
         * @return {Object|null}
         */
        get: function (code) {
            return Object.prototype.hasOwnProperty.call(renderers, code)
                ? renderers[code]
                : null;
        }
    };
});
