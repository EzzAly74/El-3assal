/**
 * Spartrak — the delivery-mode segmented control (Figma "Segements"
 * 554:13250, 720x44, three segments of 238.67).
 *
 * ===========================================================================
 * THE SEGMENTS ARE BUILT FROM LIVE RATES. THERE ARE NO INERT ONES.
 * ===========================================================================
 * A segment exists only when Magento actually returned a rate for its carrier.
 * So:
 *
 *   - a merchant who disables branch pickup loses the segment, no deploy;
 *   - a merchant who renames it in Stores > Configuration renames the segment;
 *   - a pickup carrier with no locations declines to quote at all (see
 *     Spartrak\PickupLocation\Model\Carrier\AbstractPickup), so a shopper is
 *     never offered a mode that leads to an empty list.
 *
 * The icon is the theme's, because that is presentation, and it is keyed off
 * the mode in CSS rather than here.
 *
 * ===========================================================================
 * ONE LABEL IS UI COPY, AND IT HAS TO BE
 * ===========================================================================
 * The pickup segments are named by their carrier: `استلام من الفرع` and
 * `استلام من موقف` are exactly what those two carriers are called, one carrier
 * per segment, and Figma writes them that way (554:13119 / 554:13750).
 *
 * DELIVERY is not one carrier. It is "have it brought to me", and it stands for
 * every delivery rate the store returns — two today (شحن عادي, شحن اكسبريس) and
 * whatever a merchant adds tomorrow. There is no merchant string that names the
 * GROUP, so this file used to borrow the first rate's `carrier_title`. That is
 * arbitrary the moment there are two delivery carriers, and on this store it
 * printed the brand: the English store view has no override for
 * `carriers/spartrak/title`, so the tab that Figma labels `الشحن` (551:4553)
 * read `SpareTrak`.
 *
 * So the delivery segment is a translated UI string, which is what Figma draws
 * it as. The tier underneath it is still entirely the merchant's — that is what
 * the shipping-method cards are.
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
    'Spartrak_Checkout/js/model/delivery-mode',
    'mage/translate'
], function (Component, ko, shippingService, selectShippingMethodAction, checkoutData, quote, deliveryMode, $t) {
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

                    // First rate wins the segment. For a PICKUP mode the label
                    // is that carrier's own name, one carrier per segment. For
                    // DELIVERY it is the design's own word for the group — see
                    // the header for why it cannot be a rate's title.
                    if (!byMode[mode]) {
                        byMode[mode] = {
                            mode: mode,
                            label: mode === deliveryMode.DELIVERY
                                ? $t('Shipping')
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

            /**
             * ===============================================================
             * THE SELECTED MODE HAS TO EXIST, OR THE STEP RENDERS EMPTY
             * ===============================================================
             * `selectedMode` is seeded from the quote (see
             * js/model/delivery-mode.js): a quote that carries a pickup
             * location opens on that segment, so a shopper who reloads finds
             * their own choice still made.
             *
             * Nothing checked that the seeded mode was still on offer. When it
             * is not — the merchant disabled branch pickup, the last branch was
             * removed, the carrier declined to quote for this address — the
             * step ended up in a state with no way out:
             *
             *   selectedMode()   'branch'
             *   modes()          [delivery]        <- branch returned no rate
             *   isVisible()      false             <- one mode is not a control
             *   isDelivery()     false             <- so no method cards
             *
             * i.e. `<!-- ko if: spartrakMode.isDelivery() -->` rendered empty,
             * the shipping-method heading stood over nothing, and the segmented
             * control that would have let the shopper switch back was hidden
             * because there was only one mode left to switch to.
             *
             * The reconcile runs on every rate change, because that is when the
             * set of available modes changes — a new address can withdraw a
             * carrier as easily as a config change can.
             *
             * It delegates to selectMode() rather than writing the observable
             * directly, so the fallback also picks up a real shipping method
             * exactly the way a click on the segment would, including its rule
             * about not overwriting a tier the shopper already chose.
             */
            this.modes.subscribe(this.spartrakEnsureModeIsOffered, this);
            this.spartrakEnsureModeIsOffered(this.modes());

            return this;
        },

        /**
         * Falls back to the first offered mode when the current one is gone.
         *
         * `modes` is built in Figma's reading order — delivery, branch, depot —
         * so the first entry is delivery whenever delivery has rates, which is
         * the mode a shopper who has lost their pickup option should land on.
         *
         * An empty list is left alone: it means rates have not arrived yet, or
         * no carrier quoted at all, and neither is a reason to change what the
         * shopper picked. The template's own `rates().length` guard covers the
         * second case.
         *
         * @param {Array} modes - the current value of this.modes()
         * @return {void}
         */
        spartrakEnsureModeIsOffered: function (modes) {
            var offered;

            if (!modes.length) {
                return;
            }

            offered = modes.map(function (entry) {
                return entry.mode;
            });

            if (offered.indexOf(deliveryMode.selectedMode()) === -1) {
                this.selectMode(modes[0]);
            }
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
