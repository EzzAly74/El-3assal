/**
 * Spartrak - tells the summary's shipping row when it is free.
 *
 * Figma renders a zero shipping charge in `text/success` green
 * (`0 ج.م (مجانا)`) and a real charge in the ordinary text colour. That is a
 * colour decision, so it belongs in CSS - this only supplies the boolean the
 * class hangs off.
 *
 * ===========================================================================
 * IT ASKS THE TOTAL, NOT THE CARRIER
 * ===========================================================================
 * The tempting version checks whether the selected method's code is the free
 * one. That breaks the moment free shipping comes from somewhere else: a cart
 * price rule with "free shipping", a coupon, an admin override on a single
 * order. `shipping_amount` is the figure the shopper is actually charged, and
 * it is zero in every one of those cases.
 *
 * A missing total is NOT treated as free - before a method is chosen there is
 * no amount at all, and painting that green would promise something the
 * checkout has not decided yet.
 */
define([
    'Magento_Checkout/js/model/quote'
], function (quote) {
    'use strict';

    return function (target) {
        return target.extend({

            /**
             * @return {Boolean}
             */
            spartrakIsFree: function () {
                var totals = quote.getTotals()();

                if (!totals || !this.isCalculated()) {
                    return false;
                }

                return Number(totals['shipping_amount']) === 0;
            }
        });
    };
});
