/**
 * SEAM 5b — NO TRAILING ".00", ON THE PRICES THE BROWSER FORMATS.
 *
 * The JavaScript half of Plugin\WholePricePrecision. Same rule, same
 * tolerance, same reasoning — see that class; only the layer differs.
 *
 * ===========================================================================
 * WHY THE PHP SEAM COULD NEVER HAVE COVERED THESE
 * ===========================================================================
 * Seam 5 plugs Magento\Directory\Model\Currency::formatTxt(), which is where a
 * price rendered BY THE SERVER is formatted — the category grid, the homepage
 * rails, order emails. Those were correct.
 *
 * Three surfaces format their prices in the BROWSER instead, from a number and
 * a `priceFormat` object, and never call PHP to do it:
 *
 *   PDP               Magento_Catalog/js/price-box re-renders every
 *                     `[data-price-type]` span on `_create` — so the server's
 *                     correctly formatted price is overwritten with a
 *                     client-formatted one on page load, whether or not the
 *                     product has options. This is why the PDP was the odd one
 *                     out on a page whose PLP tile was already right.
 *   cart summary      Knockout totals (Magento_Tax/js/view/checkout/summary/*)
 *   checkout summary  the same components, on the checkout
 *
 * They all reach the same two functions, so this is one choke point for all
 * three, plus the minicart and every future Knockout price.
 *
 * ===========================================================================
 * WHY NOT JUST SET requiredPrecision: 0 IN SEAM 3
 * ===========================================================================
 * Because that is a global, and the rule is not global: a whole price shows no
 * decimals, a price with real fractions keeps them. `priceFormat` is built once
 * per page and handed to every price on it, so a 0 there would render 850.50 as
 * "851". The decision has to be made per amount, which means it has to be made
 * at the call.
 *
 * ===========================================================================
 * AND WHY EVERY CALL NOW CARRIES AN EXPLICIT PRECISION
 * ===========================================================================
 * A real trap in the code being wrapped. Both functions start with
 *
 *     format = _.extend(globalPriceFormat, format);
 *
 * — `globalPriceFormat` is the TARGET, so every call permanently writes its
 * format into the module's own defaults. A wrapper that passed
 * `{requiredPrecision: 0}` for a whole amount would leave 0 sitting in those
 * defaults, and the next call that passes no format at all — core has several —
 * would then render a fractional price with no decimals.
 *
 * So this does not pass a partial override. It resolves the precision it wants
 * and sets it on EVERY call, whole or not, which means the poisoned default is
 * never the value that gets read. The mutation still happens; it just cannot
 * decide anything any more.
 *
 * ===========================================================================
 * FRONTEND ONLY
 * ===========================================================================
 * price-utils lives in `view/base` and the admin uses it too — in product-form
 * price fields and grid renderers, where "1,000" for a value someone is about
 * to edit is a change nobody asked for. The admin's SERVER-rendered prices
 * already follow this store's rule through Seam 5, which is the half that
 * matters for reading an order. This mixin is registered from
 * view/frontend/requirejs-config.js only.
 */
define(['underscore'], function (_) {
    'use strict';

    /**
     * Half of the smallest unit two decimal places can express, so a real 0.01
     * is never rounded away. Identical to Plugin\WholePricePrecision's — the two
     * halves of one rule must not disagree about what "whole" means.
     */
    var TOLERANCE = 0.005,

        /**
         * What core falls back to when a format carries no precision. Restated
         * rather than read, because the module's own default is the mutable
         * object described above.
         */
        DEFAULT_PRECISION = 2;

    /**
     * @param {Number|String} amount
     * @return {Boolean}
     */
    function isWhole(amount) {
        var value = parseFloat(amount);

        return !isNaN(value) && Math.abs(value - Math.round(value)) < TOLERANCE;
    }

    /**
     * A copy of `format` with `requiredPrecision` decided and stated.
     *
     * @param {Number|String} amount
     * @param {Object} [format]
     * @return {Object}
     */
    function withPrecision(amount, format) {
        var resolved = _.extend({}, format);

        resolved.requiredPrecision = isWhole(amount)
            ? 0
            : format && format.requiredPrecision !== undefined
                ? format.requiredPrecision
                : DEFAULT_PRECISION;

        return resolved;
    }

    return function (priceUtils) {
        var formatPrice = priceUtils.formatPrice,
            formatPriceLocale = priceUtils.formatPriceLocale;

        /**
         * Both are wrapped. `formatPrice` is marked deprecated in core and is
         * still what price-box and the checkout totals actually call;
         * `formatPriceLocale` is the replacement. Wrapping only one would leave
         * whichever a given component happens to use rendering the other rule.
         */
        priceUtils.formatPrice = function (amount, format, isShowSign) {
            return formatPrice.call(this, amount, withPrecision(amount, format), isShowSign);
        };

        if (typeof formatPriceLocale === 'function') {
            priceUtils.formatPriceLocale = function (amount, format, isShowSign) {
                return formatPriceLocale.call(
                    this,
                    amount,
                    withPrecision(amount, format),
                    isShowSign
                );
            };
        }

        return priceUtils;
    };
});
