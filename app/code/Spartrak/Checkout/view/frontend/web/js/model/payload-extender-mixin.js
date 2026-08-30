/**
 * Spartrak — puts the chosen pickup location into the shipping-information
 * request.
 *
 * ===========================================================================
 * WHY THIS FILE IS THREE LINES OF LOGIC
 * ===========================================================================
 * Magento_Checkout/js/model/shipping-save-processor/payload-extender exists
 * for exactly this. Core's own implementation does nothing but create an empty
 * `extension_attributes` object, precisely so modules can extend it by mixin
 * rather than replacing the save processor.
 *
 * The alternative — overriding shipping-save-processor/default.js — would take
 * ownership of the whole request: the address serialisation, the billing
 * fallback, the error handling. All of that would then be frozen at the
 * version it was copied from. Extending the extender leaves core owning the
 * request and this module owning only its own two fields.
 *
 * The server half is Spartrak\PickupLocation\Plugin\Checkout\ApplyPickupLocation,
 * which reads these two attributes off ShippingInformationInterface.
 *
 * ===========================================================================
 * IT SENDS NOTHING WHEN THE MODE IS DELIVERY
 * ===========================================================================
 * A delivery order must not carry a pickup id, and the server plugin clears
 * any stale one it finds. Sending null here as well means the two agree
 * without either depending on the other having run.
 */
define([
    'mage/utils/wrapper',
    'Spartrak_Checkout/js/model/delivery-mode'
], function (wrapper, deliveryMode) {
    'use strict';

    return function (payloadExtender) {
        return wrapper.wrap(payloadExtender, function (originalExtender, payload) {
            // Core creates extension_attributes; call it first so the object
            // exists and any other module's contribution survives.
            payload = originalExtender(payload);

            if (!deliveryMode.isPickup()) {
                return payload;
            }

            var locationId = deliveryMode.currentLocationId();

            if (!locationId) {
                // The shopper is on a pickup segment but has not chosen a
                // location. Left absent rather than sent as 0 — the server
                // answers with "please choose a pickup location", which is the
                // message a shopper needs, and it is stated in one place.
                return payload;
            }

            payload.addressInformation['extension_attributes']['spartrak_pickup_type'] =
                deliveryMode.selectedMode();
            payload.addressInformation['extension_attributes']['spartrak_pickup_id'] =
                locationId;

            return payload;
        });
    };
});
