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
    'Spartrak_Checkout/js/model/delivery-mode'
], function (deliveryMode) {
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
            spartrakMode: deliveryMode
        });
    };
});
