/**
 * Spartrak - renders EVERY payment method in the Figma row.
 *
 * ===========================================================================
 * WHY A MIXIN ON THE BASE RENDERER
 * ===========================================================================
 * Each payment method supplies its own Knockout component, and most supply
 * their own template with it: Magento's offline methods do, PayPal does, and
 * Paymob does (Paymob_Payment/payment/paymob, which ships an inline <style>
 * block that fights the design system outright).
 *
 * Overriding those templates one by one would mean a file per integration,
 * kept in step with each vendor's updates by hand, and would still miss the
 * next method someone installs.
 *
 * Every one of those components extends Magento_Checkout/js/view/payment/
 * default - verified for Paymob's renderer and for core's offline methods.
 * That is the single choke point, so the template is assigned there, once.
 *
 * ===========================================================================
 * WHY IN initialize() AND NOT IN defaults
 * ===========================================================================
 * A mixin's `defaults.template` loses to the subclass's own `defaults.template`
 * - that is exactly how Paymob sets its template today, and merging cannot
 * outrank it. Assigning after _super() runs is what actually wins, and it is
 * still before first render, so nothing flashes.
 *
 * ===========================================================================
 * THE ESCAPE HATCH
 * ===========================================================================
 * A method that genuinely needs its own layout - a hosted card form drawn
 * inline, a saved-cards picker - sets `spartrakKeepTemplate: true` in its
 * jsLayout config and keeps whatever template it declared. Nothing is trapped
 * in this row; it is the default, not a rule.
 */
define([
    'ko',
    'Spartrak_Checkout/js/model/payment-registry'
], function (ko, paymentRegistry) {
    'use strict';

    var ROW_TEMPLATE = 'Spartrak_Payment/payment/method-row';

    /**
     * The admin-managed presentation for one method code, or a safe blank.
     *
     * Reads window.checkoutConfig rather than taking a dependency on a
     * provider module: the value is a plain data map written by
     * Spartrak\Payment\Model\ConfigProvider, and a checkout that has not been
     * configured yet must render, not throw.
     */
    function presentationFor(code) {
        var config = window.checkoutConfig || {},
            payment = config.spartrakPayment || {},
            map = payment.presentation || {};

        return map[code] || {};
    }

    return function (target) {
        return target.extend({

            /** @inheritdoc */
            initialize: function () {
                this._super();

                if (!this.spartrakKeepTemplate) {
                    this.template = ROW_TEMPLATE;
                }

                // The one CTA in the order summary has to be able to reach the
                // renderer the shopper chose, because placing an order is that
                // renderer's job - Paymob redirects, InstaPay stores a
                // transfer, cash on delivery just places. See
                // Spartrak_Checkout/js/model/payment-registry.
                paymentRegistry.register(this);

                return this;
            },

            /**
             * The grey line under the title. Empty string when unconfigured,
             * which the template treats as "show the title alone".
             *
             * A plain function, not an observable: the presentation map is
             * written into the page once and never changes during a checkout,
             * so an observable would be a subscription that can never fire.
             */
            spartrakDescription: function () {
                return presentationFor(this.getCode()).description || '';
            },

            /**
             * @return {Array<{url: string, label: string}>}
             */
            spartrakBrands: function () {
                return presentationFor(this.getCode()).brands || [];
            }
        });
    };
});
