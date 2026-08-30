/**
 * Spartrak — the delivery-mode segmented control (Figma "Segements"
 * 554:13250, 720x44, three segments of 238.67).
 *
 * ===========================================================================
 * THE SEGMENTS ARE BUILT FROM LIVE RATES. THERE ARE NO INERT ONES.
 * ===========================================================================
 * A segment exists only when Magento actually returned a rate for its carrier,
 * and its LABEL is that rate's own configured title. So:
 *
 *   - a merchant who disables branch pickup loses the segment, no deploy;
 *   - a merchant who renames it in Stores > Configuration renames the segment;
 *   - a pickup carrier with no locations declines to quote at all (see
 *     Spartrak\PickupLocation\Model\Carrier\AbstractPickup), so a shopper is
 *     never offered a mode that leads to an empty list.
 *
 * Nothing about a segment is written down in this file — not a label, not a
 * price, not an order. The icon is the one thing the theme owns, because that
 * is presentation, and it is keyed off the mode in CSS rather than here.
 *
 * ===========================================================================
 * CHOOSING A SEGMENT SELECTS A REAL SHIPPING METHOD
 * ===========================================================================
 * Switching to "استلام من الفرع" calls core's own selectShippingMethodAction
 * with the branch carrier's rate. That keeps quote.shippingMethod() truthful,
 * lets core's own validation pass, and means the totals block recalculates
 * exactly as it would for any other method. This component adds a UI over
 * core's selection; it does not invent a parallel one.
 */
define([
    'uiComponent',
    'ko',
    'Magento_Checkout/js/model/shipping-service',
    'Magento_Checkout/js/action/select-shipping-method',
    'Magento_Checkout/js/checkout-data',
    'Magento_Checkout/js/model/quote',
    'Spartrak_Checkout/js/model/delivery-mode'
], function (Component, ko, shippingService, selectShippingMethodAction, checkoutData, quote, deliveryMode) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Spartrak_Checkout/delivery-mode'
        },

        /** Exposed for the template's bindings. */
        selectedMode: deliveryMode.selectedMode,

        initialize: function () {
            this._super();

            /**
             * One entry per mode that has at least one rate, in the reading
             * order Figma draws: delivery, branch, depot.
             *
             * A computed rather than a one-time build: rates arrive
             * asynchronously and change whenever the address does, so a static
             * list would either be empty on first paint or stale afterwards.
             */
            this.modes = ko.computed(function () {
                var rates = shippingService.getShippingRates()(),
                    byMode = {};

                rates.forEach(function (rate) {
                    if (rate['error_message']) {
                        return;
                    }

                    var mode = deliveryMode.modeForCarrier(rate['carrier_code']);

                    // First rate wins the label. For delivery that is the
                    // carrier title rather than any one method's name, because
                    // the segment stands for "have it delivered", not for a
                    // particular tier — the tier is chosen by the cards below.
                    if (!byMode[mode]) {
                        byMode[mode] = {
                            mode: mode,
                            label: mode === deliveryMode.DELIVERY
                                ? rate['carrier_title']
                                : rate['method_title'] || rate['carrier_title'],
                            rate: rate
                        };
                    }
                });

                return [deliveryMode.DELIVERY, deliveryMode.BRANCH, deliveryMode.DEPOT]
                    .map(function (mode) {
                        return byMode[mode];
                    })
                    .filter(Boolean);
            }, this);

            /**
             * A control with one option is not a control. When a merchant runs
             * delivery only, the segmented row is not drawn at all rather than
             * rendered as a single dead tab.
             */
            this.isVisible = ko.computed(function () {
                return this.modes().length > 1;
            }, this);

            return this;
        },

        isSelected: function (mode) {
            return deliveryMode.selectedMode() === mode;
        },

        /**
         * @param {Object} entry - one item from this.modes()
         */
        selectMode: function (entry) {
            deliveryMode.selectedMode(entry.mode);

            // Delivery re-uses whichever tier the shopper already had, if any;
            // the method cards below own that choice. Selecting the first rate
            // here would silently overwrite an express selection with standard
            // every time the shopper glanced at another segment.
            if (entry.mode === deliveryMode.DELIVERY && quote.shippingMethod()) {
                var current = quote.shippingMethod();

                if (deliveryMode.modeForCarrier(current['carrier_code']) === deliveryMode.DELIVERY) {
                    return true;
                }
            }

            this.applyRate(entry.rate);

            return true;
        },

        /**
         * Hands a rate to core's own selection action.
         *
         * checkoutData is written as well so the choice survives a reload —
         * core's shipping component reads it back when it restores state, and
         * skipping it is why a selection sometimes "un-picks" itself after a
         * refresh.
         */
        applyRate: function (rate) {
            selectShippingMethodAction(rate);
            checkoutData.setSelectedShippingRate(rate['carrier_code'] + '_' + rate['method_code']);
        }
    });
});
