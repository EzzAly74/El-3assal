/**
 * Spartrak — the branch and depot pickers.
 *
 * Figma: branch 554:13119, depot 554:13750. Two visibly different flows over
 * one idea, which is why this is one component with two row templates rather
 * than two components: the selection, the persistence and the empty state are
 * identical, and only the row differs.
 *
 * ===========================================================================
 * THE OPERATOR CHIP IS A BADGE, NOT A FILTER
 * ===========================================================================
 * Corrected against the frame. A filter row of operator chips was drafted here
 * and removed: 554:13750 puts the chip INSIDE each depot row (the `Label`
 * instance at x=60 of "Address item" 569:14518), and draws no filter control
 * anywhere. Building one would have been inventing UI the design does not have.
 *
 * Filtering by operator is still possible, and without a new control: the
 * operator name is part of each row's search index, so typing it in the search
 * box narrows the list to that operator's depots.
 *
 * ===========================================================================
 * THE LISTS COME FROM THE SERVER, NOT FROM THIS FILE
 * ===========================================================================
 * window.checkoutConfig.spartrakPickup is published by
 * Spartrak\PickupLocation\Model\ConfigProvider, which reads the admin-managed
 * branch and depot tables. There is no fixture, no fallback list and no
 * hardcoded name anywhere in this component — an empty list renders the empty
 * state, and the carrier will not even have offered the segment (see
 * AbstractPickup::collectRates).
 *
 * ===========================================================================
 * SEARCH IS CLIENT-SIDE, DELIBERATELY
 * ===========================================================================
 * Figma 569:14542 - a 696x48 input with the loupe glyph, above the rows.
 *
 * The whole depot list is already in the page — see the config provider for
 * why it is inlined rather than fetched. Filtering it in the browser therefore
 * costs nothing and responds instantly, where an endpoint would put a round
 * trip between each keystroke and the result.
 *
 * The search normalises Arabic before comparing: أ إ آ all fold to ا, ة to ه,
 * ى to ي, and tashkeel is stripped. Without that, a shopper typing "الاسماعيليه"
 * finds nothing when the admin typed "الإسماعيلية" — the same normalisation the
 * catalogue search uses, and the reason it is written down in the skill's
 * traps table.
 */
define([
    'uiComponent',
    'ko',
    'Magento_Checkout/js/model/shipping-service',
    'Magento_Checkout/js/action/select-shipping-method',
    'Magento_Checkout/js/checkout-data',
    'Spartrak_Checkout/js/model/delivery-mode'
], function (Component, ko, shippingService, selectShippingMethodAction, checkoutData, deliveryMode) {
    'use strict';

    var config = window.checkoutConfig && window.checkoutConfig.spartrakPickup || {};

    /**
     * Folds the Arabic orthographic variants that shoppers and admins spell
     * differently, and drops diacritics. Latin text passes through lowercased.
     */
    function normalise(value) {
        return String(value || '')
            .toLowerCase()
            // tashkeel and tatweel
            .replace(/[ً-ْـ]/g, '')
            .replace(/[آأإ]/g, 'ا')
            .replace(/ة/g, 'ه')
            .replace(/ى/g, 'ي')
            .trim();
    }

    return Component.extend({
        defaults: {
            template: 'Spartrak_Checkout/pickup-locations'
        },

        initialize: function () {
            this._super();

            this.mode = deliveryMode.selectedMode;
            this.query = ko.observable('');

            this.branches = config.branch && config.branch.locations || [];
            this.depots = config.depot && config.depot.locations || [];
            this.operators = config.depot && config.depot.operators || [];
            this.disclaimer = config.depot && config.depot.disclaimer || '';

            // Precomputed once. Normalising forty depot names on every
            // keystroke is wasted work on a field a shopper types into fast.
            this.depots.forEach(function (depot) {
                depot.searchIndex = normalise(
                    [depot.name, depot.address, depot.region, depot.operator].join(' ')
                );
            });

            this.isBranch = ko.computed(function () {
                return deliveryMode.selectedMode() === deliveryMode.BRANCH;
            });

            this.isDepot = ko.computed(function () {
                return deliveryMode.selectedMode() === deliveryMode.DEPOT;
            });

            this.visibleDepots = ko.computed(function () {
                var needle = normalise(this.query());

                if (needle === '') {
                    return this.depots;
                }

                return this.depots.filter(function (depot) {
                    return depot.searchIndex.indexOf(needle) !== -1;
                });
            }, this);

            this.hasResults = ko.computed(function () {
                return this.isBranch() ? this.branches.length > 0 : this.visibleDepots().length > 0;
            }, this);

            return this;
        },

        /**
         * True when this location is the one currently chosen.
         */
        isSelected: function (location) {
            return deliveryMode.currentLocationId() === location.id;
        },

        /**
         * Records the choice and makes sure the matching carrier is the
         * quote's selected shipping method.
         *
         * Both halves matter. Without the first the server has no location;
         * without the second core would refuse the shipping information
         * because no method is selected — and the shopper would see a generic
         * error after picking a branch, with nothing on screen suggesting why.
         */
        selectLocation: function (location) {
            deliveryMode.select(deliveryMode.selectedMode(), location.id);

            var carrier = deliveryMode.selectedMode() === deliveryMode.BRANCH
                    ? deliveryMode.branchCarrier
                    : deliveryMode.depotCarrier,
                rate = shippingService.getShippingRates()().find(function (candidate) {
                    return candidate['carrier_code'] === carrier;
                });

            if (rate) {
                selectShippingMethodAction(rate);
                checkoutData.setSelectedShippingRate(rate['carrier_code'] + '_' + rate['method_code']);
            }

            return true;
        },

        /**
         * True when the shopper has searched and matched nothing - as opposed
         * to there being no depots at all. Two different messages, because
         * "try another search" is useless advice when the list is empty.
         */
        isEmptySearch: function () {
            return this.depots.length > 0 && this.visibleDepots().length === 0;
        }
    });
});
