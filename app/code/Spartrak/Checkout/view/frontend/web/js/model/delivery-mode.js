/**
 * Spartrak — which delivery mode the shopper is in, and which location they
 * picked.
 *
 * Figma's shipping step is one segmented control over three modes
 * (554:13250): الشحن, استلام من الفرع, استلام من الموقف. Choosing a segment
 * changes what the whole step shows — delivery shows the address book and the
 * shipping-method cards, branch shows a list of branches, depot shows a
 * searchable list of depots. Nothing else about the step differs.
 *
 * ===========================================================================
 * WHY A SHARED MODEL AND NOT STATE ON A COMPONENT
 * ===========================================================================
 * Four unrelated things need this answer:
 *
 *   the segmented control          to render which segment is active
 *   the shipping component         to decide whether to show the address book
 *   the pickup list component      to decide which list to render
 *   the shipping-save payload      to send the chosen location to the server
 *
 * They sit in different places in the component tree, and two of them are core
 * code we are extending rather than owning. A module-scope observable is how
 * Magento's own checkout shares state across exactly this kind of boundary
 * (see Magento_Checkout/js/model/quote), and it means none of the four has to
 * know where the others live.
 *
 * ===========================================================================
 * IT HOLDS NO PRICES AND NO LABELS
 * ===========================================================================
 * A mode is only meaningful if Magento actually returned a rate for its
 * carrier. The label on each segment is the carrier's own configured title,
 * read off the rate — so a merchant renaming "استلام من الفرع" in
 * Stores > Configuration changes the segment, and disabling the carrier
 * removes it. Nothing here is hardcoded, and there are no inert segments:
 * see view/delivery-mode.js, which builds the list from the live rates.
 */
define([
    'ko'
], function (ko) {
    'use strict';

    var config = window.checkoutConfig && window.checkoutConfig.spartrakPickup || {},

        /**
         * The carrier codes, straight from the server so the two sides cannot
         * disagree about a string.
         */
        BRANCH_CARRIER = config.branch && config.branch.carrier || 'spartrak_branch',
        DEPOT_CARRIER = config.depot && config.depot.carrier || 'spartrak_depot',

        /** 'delivery' | 'branch' | 'depot' */
        selectedMode = ko.observable('delivery'),

        /**
         * { type: 'branch'|'depot', id: Number } or null.
         *
         * Seeded from the quote so a shopper who reloads mid-checkout finds
         * their own choice still selected rather than an empty list.
         */
        selectedLocation = ko.observable(
            config.selected ? { type: config.selected.type, id: Number(config.selected.id) } : null
        );

    // A quote that already carries a pickup location opens on that segment.
    if (config.selected && config.selected.type) {
        selectedMode(config.selected.type);
    }

    return {
        BRANCH: 'branch',
        DEPOT: 'depot',
        DELIVERY: 'delivery',

        branchCarrier: BRANCH_CARRIER,
        depotCarrier: DEPOT_CARRIER,

        selectedMode: selectedMode,
        selectedLocation: selectedLocation,

        /**
         * Maps a carrier code onto a mode. Anything that is not one of the two
         * pickup carriers is a delivery — including flatrate, freeshipping and
         * anything a merchant installs later.
         */
        modeForCarrier: function (carrierCode) {
            if (carrierCode === BRANCH_CARRIER) {
                return this.BRANCH;
            }

            if (carrierCode === DEPOT_CARRIER) {
                return this.DEPOT;
            }

            return this.DELIVERY;
        },

        isDelivery: ko.computed(function () {
            return selectedMode() === 'delivery';
        }),

        isPickup: ko.computed(function () {
            return selectedMode() !== 'delivery';
        }),

        /**
         * The location id for the current mode, or null.
         *
         * Scoped to the mode on purpose: a shopper who picks a branch, changes
         * to depot and then submits must not send the branch id. The value is
         * kept rather than cleared so that switching back restores it.
         */
        currentLocationId: function () {
            var location = selectedLocation();

            return location && location.type === selectedMode() ? location.id : null;
        },

        select: function (type, id) {
            selectedLocation({ type: type, id: Number(id) });
        }
    };
});
