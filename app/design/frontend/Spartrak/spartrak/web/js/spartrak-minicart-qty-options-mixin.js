/**
 * Spartrak — the option list behind the cart drawer's quantity dropdown.
 *
 * ===========================================================================
 * WHY THE LIST IS BUILT HERE AND NOT IN THE TEMPLATE
 * ===========================================================================
 * Figma 820:16477 draws the quantity as a dropdown. A dropdown needs a list,
 * and a list written inline in the Knockout template would be a bare number
 * sitting in markup — and, worse, a CEILING. A line added from a product page
 * at quantity 25 would find no matching <option>, and a <select> with no
 * matching option falls back to its first one: the drawer would render "1" and
 * the next change would commit 1, quietly throwing away 24 units the shopper
 * asked for.
 *
 * So the length comes from configuration (Magento_Checkout/layout/default.xml)
 * and the CURRENT quantity is always in the list, appended above the cap when
 * it sits above it. The dropdown can then never misrepresent what is in the
 * cart, whatever the cap is set to.
 *
 * ===========================================================================
 * WHY IT EXTENDS cart-item-renderer AND NOT view/minicart
 * ===========================================================================
 * MEASURED, from the browser console: with this on view/minicart the template
 * threw `$parent.getQtyOptions is not a function` and Knockout dropped the
 * whole binding, leaving a <select> with no options in it.
 *
 * `$parent` inside Magento_Checkout/minicart/item/default is the ITEM RENDERER,
 * not the minicart view. content.html renders each line through
 *
 *     <each args="$parent.getRegion(...)" render="{name: getTemplate(), data: item, ...}">
 *
 * so the context the item template is rendered in has the renderer element that
 * `each` is iterating as its parent — which is exactly why core's own item
 * template calls `$parent.getProductNameUnsanitizedHtml()`, a
 * cart-item-renderer method, and has to reach the minicart view as
 * `$parents[1]` for initSidebar().
 *
 * Reading a core template's OTHER `$parent` calls is what settles which
 * component a mixin belongs on. Both of that template's existing ones —
 * getProductNameUnsanitizedHtml and getOptionValueUnsanitizedHtml — are defined
 * in vendor/magento/module-checkout/view/frontend/web/js/view/cart-item-renderer.js.
 *
 * It extends that component in place: no core file is touched, no component is
 * re-implemented, no new dependency, and the file merges into the same bundle
 * as its target rather than adding a request.
 */
define([], function () {
    'use strict';

    /**
     * Ten, matching the brief, for the case where no config reaches the
     * component at all — a layout override removed, or a third-party module
     * rebuilding jsLayout. Named rather than inline so there is exactly one
     * place to read it from.
     */
    var FALLBACK_MAX = 10;

    return function (Component) {
        return Component.extend({

            /**
             * 1..max, plus the line's own quantity when it is above max.
             *
             * @param {Number|String} qty - the quantity currently on this line
             * @returns {Array<Number>}
             */
            getQtyOptions: function (qty) {
                var max = parseInt(this.maxQtyOptions, 10),
                    options = [],
                    current,
                    i;

                if (!max || max < 1) {
                    max = FALLBACK_MAX;
                }

                for (i = 1; i <= max; i++) {
                    options.push(i);
                }

                // Whatever is on the line goes in the list if 1..max did not
                // already cover it. That includes a decimal quantity — a
                // length of cable, a weight — which is passed through as it is
                // rather than rounded into something the shopper never chose.
                //
                // Sorted, so an appended 25 reads as the last row rather than
                // landing after 10 in an order nothing explains.
                current = Number(qty);

                if (current > 0 && options.indexOf(current) === -1) {
                    options.push(current);
                    options.sort(function (a, b) {
                        return a - b;
                    });
                }

                return options;
            }
        });
    };
});
