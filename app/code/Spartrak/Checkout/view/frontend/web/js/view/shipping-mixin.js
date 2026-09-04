/**
 * Spartrak — gives the shipping step access to the delivery mode.
 *
 * The theme's shipping.html needs to know whether the shopper is delivering or
 * collecting, so it can show the address book or the location list. A Knockout
 * template can only bind to its own component, and this state deliberately
 * lives outside any one component (see js/model/delivery-mode for why), so the
 * component needs a handle on it.
 *
 * A MIXIN, NOT A SUBCLASS OR A PREFERENCE. Magento_Checkout/js/view/shipping is
 * a large component that owns the address form, the address list, validation
 * and the step transition. Extending it by hand would freeze all of that at the
 * version it was copied from; a mixin adds one property and leaves the rest to
 * core forever.
 *
 * It is one line of behaviour, and that is the point.
 */
define([
    'ko',
    // Magento_CUSTOMER, not Magento_Checkout. The address book belongs to the
    // customer module even though only the checkout reads it here, and the
    // wrong namespace 404s - which RequireJS reports as a script error that
    // aborts the whole component, leaving the checkout on its spinner forever.
    'Magento_Customer/js/model/address-list',
    'Magento_Checkout/js/model/shipping-service',
    'Spartrak_Checkout/js/model/delivery-mode'
], function (ko, addressList, shippingService, deliveryMode) {
    'use strict';

    return function (Shipping) {
        return Shipping.extend({
            /**
             * The shared model, exposed by name for the template.
             *
             * Not an observable of its own: the model's members already are
             * observables, so binding to `spartrakMode.isDelivery()` tracks
             * changes without this component holding a duplicate of the state
             * that could fall out of step with it.
             */
            spartrakMode: deliveryMode,

            /**
             * Whether the shopper has any address to choose from.
             *
             * Figma has two different screens for this - a list (549:2753) and
             * a van illustration with an invitation to add one (557:4898) - and
             * the template needs a single boolean to pick between them.
             *
             * `address-list` is Magento's own observable array of the addresses
             * offered on this checkout: the customer's saved book plus anything
             * added during the session. Reading it, rather than counting
             * `checkoutConfig.customerData.addresses` once at load, is what
             * makes the empty state disappear the moment the first address is
             * saved - without a page reload and without this component
             * subscribing to anything itself.
             *
             * @return {Boolean}
             */
            spartrakHasAddresses: ko.computed(function () {
                return addressList().length > 0;
            }),

            /**
             * The rates the shipping-method CARDS are allowed to show.
             *
             * ===================================================================
             * WHY THE FULL RATE LIST IS THE WRONG SOURCE
             * ===================================================================
             * The pickup carriers are ordinary Magento carriers - that is the
             * whole reason choosing a branch produces a real shipping method and
             * core's validation and totals work untouched (see
             * js/model/delivery-mode.js). The price of that is that
             * `shippingService.getShippingRates()` returns them alongside
             * `شحن عادي` and `شحن اكسبريس`, and the card list rendered every one
             * it was given.
             *
             * So the الشحن segment showed four cards: the two delivery tiers plus
             * `استلام من الفرع` and `استلام من الموقف` - the same two options the
             * segments above already offer, as cards that silently switch the
             * shopper out of the segment they are looking at. Figma's delivery
             * frame (549:26262) draws two.
             *
             * Filtered through `modeForCarrier`, not against a hardcoded pair of
             * codes, so a delivery carrier a merchant adds later is included and
             * a third pickup carrier is excluded without touching this file.
             *
             * `rates` itself is left alone. It is core's, the step's own
             * `rates().length` guards read it, and a pickup rate genuinely IS an
             * available rate - it is only the CARD LIST that must not offer it.
             *
             * @return {Array}
             */
            spartrakDeliveryRates: ko.computed(function () {
                return shippingService.getShippingRates()().filter(function (rate) {
                    return deliveryMode.modeForCarrier(rate['carrier_code']) === deliveryMode.DELIVERY;
                });
            })
        });
    };
});
