/**
 * Spartrak - puts the item count in the subtotal label.
 *
 * Figma's subtotal reads `المجموع الفرعي (3 منتجات)`: the count is part of the
 * label, not a separate row. The number is the quote's, read live, so it
 * follows a quantity change without this component knowing anything about how
 * quantities work.
 *
 * ===========================================================================
 * WHY LINE COUNT AND NOT TOTAL QUANTITY
 * ===========================================================================
 * `items_qty` counts units - a cart holding one product with qty 3 reports 3.
 * `items` counts lines - it reports 1. Figma's example shows two visible
 * product cards and reads "(3 منتجات)", which is units, not lines. So this
 * uses items_qty.
 *
 * ===========================================================================
 * NO toLocaleString
 * ===========================================================================
 * Same reason as the quantity badge: under ar_EG it produces Arabic-Indic
 * digits, which this storefront does not use. Prices go through
 * Spartrak_Locale; a bare count needs no formatter at all.
 */
define([
    'ko',
    'Magento_Checkout/js/model/quote',
    'mage/translate'
], function (ko, quote, $t) {
    'use strict';

    return function (target) {
        return target.extend({

            /**
             * Units in the quote, or 0 before totals have been collected.
             *
             * @return {Number}
             */
            spartrakItemCount: function () {
                var totals = quote.getTotals()();

                if (!totals) {
                    return 0;
                }

                // items_qty is a float on the quote (fractional quantities are
                // legal for weighted products); rounding keeps the label
                // reading "3" rather than "3.0000".
                return Math.round(Number(totals['items_qty']) || 0);
            },

            /**
             * The parenthesised half of the label.
             *
             * Singular and plural are separate translation keys rather than one
             * string with a number substituted, because Arabic pluralisation
             * does not follow English rules and a single form would be wrong in
             * one of the two locales this store serves.
             *
             * @return {String}
             */
            spartrakItemCountLabel: function () {
                var count = this.spartrakItemCount();

                return count === 1
                    ? '(' + count + ' ' + $t('product') + ')'
                    : '(' + count + ' ' + $t('products') + ')';
            }
        });
    };
});
