/**
 * Spartrak_Locale — the JavaScript half of "no trailing .00".
 *
 * Seam 5 (Plugin\WholePricePrecision) covers every price the SERVER formats.
 * Three surfaces format theirs in the browser instead and so were untouched by
 * it: the PDP, whose price-box re-renders the price on load, and the cart and
 * checkout summaries, whose totals are Knockout. All of them go through
 * Magento_Catalog/js/price-utils, which is what this mixin extends.
 *
 * A MIXIN AND NOT A `map` OVERRIDE: a mixin is merged into the same bundle as
 * its target, so it adds no request, and it leaves core's module in place
 * rather than replacing a file that other modules also extend.
 *
 * See web/js/price-utils-precision-mixin.js for the rule, for why the
 * precision cannot be set globally in Seam 3, and for the mutable-default trap
 * in the code it wraps.
 */
var config = {
    config: {
        mixins: {
            'Magento_Catalog/js/price-utils': {
                'Spartrak_Locale/js/price-utils-precision-mixin': true
            }
        }
    }
};
