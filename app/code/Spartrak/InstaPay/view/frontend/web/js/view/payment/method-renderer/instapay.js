/**
 * The InstaPay renderer.
 *
 * ===========================================================================
 * IT DRAWS NOTHING OF ITS OWN
 * ===========================================================================
 * No template is declared here on purpose. Spartrak_Payment's mixin assigns
 * every payment renderer the same Figma row, so InstaPay's title, description
 * and logo come from the admin-managed presentation registry exactly like every
 * other method's. Declaring a template here would opt this method out of that
 * and make it the one row a merchant cannot edit.
 *
 * ===========================================================================
 * THE ONE THING IT DOES: REDIRECT
 * ===========================================================================
 * `redirectAfterPlaceOrder: false` stops core sending the shopper to the
 * success page, and afterPlaceOrder() sends them to the transfer page instead.
 *
 * That ordering is deliberate. The order is created FIRST, in
 * `pending_payment`, so the cart is converted and the stock reserved while the
 * shopper is in their banking app - two people cannot pay for the last one. If
 * they never come back, the merchant is left with a pending_payment order,
 * which is precisely the state that exists to be chased or cancelled.
 *
 * The loader is started and never stopped, which is correct: the page is
 * leaving, and a checkout that looks interactive during a redirect invites a
 * second click.
 */
define([
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/full-screen-loader'
], function (Component, fullScreenLoader) {
    'use strict';

    return Component.extend({
        redirectAfterPlaceOrder: false,

        /**
         * @return {String} the code this renderer is registered against
         */
        getCode: function () {
            return 'spartrak_instapay';
        },

        /** @inheritdoc */
        afterPlaceOrder: function () {
            var config = window.checkoutConfig || {},
                payment = (config.payment || {})['spartrak_instapay'] || {},
                url = payment.transferUrl;

            if (!url) {
                // The method was switched off between the page loading and the
                // order being placed. Fall back to core's behaviour rather than
                // stranding the shopper on a checkout for an order that exists:
                // the transfer controller redirects them onward from there too.
                this._super();

                return;
            }

            fullScreenLoader.startLoader();
            // replace(), not assign(): the checkout must not be reachable with
            // the back button once the order has been created.
            window.location.replace(url);
        }
    });
});
