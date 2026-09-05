/**
 * The header cart badge, and nothing else.
 *
 * ===========================================================================
 * WHY THIS EXISTS
 * ===========================================================================
 * On every page the header's cart icon and its item-count badge are bound to
 * `minicart_content`, whose component is Magento_Checkout/js/view/minicart.
 * That component exists to drive the MINICART DRAWER, and it declares two
 * dependencies at define time to do it:
 *
 *     'sidebar'          Magento_Checkout/js/sidebar
 *     'mage/dropdown'    -> jquery-ui-modules/dialog
 *
 * `mage/dropdown` is the expensive one. It is a thin wrapper that extends
 * $.ui.dialog, so requiring it drags in the entire jQuery UI dialog tree.
 * Measured on the live Arabic checkout (Lighthouse, static version
 * 1788629457) that tree was:
 *
 *     widgets/dialog 15,647   widgets/resizable 22,435  widgets/draggable 22,190
 *     effect 15,117           position 10,587           jquery.color 10,621
 *     widgets/button 7,446    widgets/controlgroup 5,640
 *     widgets/checkboxradio 4,996   widgets/mouse 3,957
 *     form-reset-mixin 1,203  effects/effect-blind 1,146
 *     effects/effect-fade 599
 *     ----------------------------------------------------------------
 *     ~121,584 bytes over 13 requests, across three SERIAL waterfall layers
 *
 * Checkout does not need a cart drawer. The order summary on the page already
 * lists every line item, its quantity and its price — the drawer is a second,
 * smaller copy of information the shopper is already looking at.
 *
 * ===========================================================================
 * WHY A NEW COMPONENT RATHER THAN A TRIMMED COPY OF THE CORE ONE
 * ===========================================================================
 * The badge and the drawer share one component, so simply disabling
 * `minicart_content` on checkout would leave the badge bound to nothing and
 * rendering its static fallback of 0 — a wrong number is worse than no drawer.
 *
 * The alternative was to copy Magento_Checkout/js/view/minicart.js into the
 * theme with 'mage/dropdown' removed. That is ~150 lines of vendor code
 * forked to delete one dependency, and it would silently stop receiving
 * upstream fixes. CLAUDE.md section 9 rules that out. This component owns only
 * what the badge actually binds to, so there is nothing to keep in sync.
 *
 * The template markup is UNCHANGED: Magento_Checkout::cart/minicart.phtml binds
 * `getCartParam('summary_count')` and `blockLoader: isLoading` inside the
 * `.showcart` link, and both are provided below with the same semantics core
 * gives them. `customerData.get('cart')` is the same observable core reads, so
 * the badge stays live — it still updates on the invalidation events declared
 * in Spartrak_InstaPay's sections.xml.
 *
 * Scoped to checkout only, in Spartrak/Checkout/view/frontend/layout/
 * checkout_index_index.xml. Every other page keeps the full drawer. Reverting
 * is deleting that referenceBlock and the one guard in minicart.phtml.
 */
define([
    'uiComponent',
    'ko',
    'Magento_Customer/js/customer-data'
], function (Component, ko, customerData) {
    'use strict';

    return Component.extend({
        /**
         * Core declares this on the prototype too, so the loader binding in the
         * badge has something to read whether or not a section refresh is in
         * flight.
         */
        isLoading: ko.observable(false),

        /**
         * @returns {Object} this
         */
        initialize: function () {
            this._super();

            this.cart = customerData.get('cart');

            return this;
        },

        /**
         * One value out of the `cart` customer-data section, matching the
         * signature the shared template binds against.
         *
         * @param {String} name
         * @returns {*}
         */
        getCartParam: function (name) {
            var cart = this.cart();

            return cart ? cart[name] : undefined;
        }
    });
});
