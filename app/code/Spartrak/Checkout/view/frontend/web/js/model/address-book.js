/**
 * Spartrak - saves a checkout address to the customer's address book, and puts
 * the refreshed list back into the checkout.
 *
 * ===========================================================================
 * ONE SERVICE, BOTH VIEWPORTS
 * ===========================================================================
 * The desktop modal (Figma 557:5173) and the mobile bottom sheet (687:15189)
 * are the SAME Magento modal at two widths - `responsive: true` is what turns
 * one into the other - so they are the same component, the same form and the
 * same save. Nothing in this file, or in the mixin that calls it, knows which
 * viewport it is on.
 *
 * ===========================================================================
 * WHAT IT DOES NOT DO
 * ===========================================================================
 * It does not decide anything about the address. Validation, region resolution,
 * ownership, and the default-shipping flag are all the server's - see
 * Spartrak\Checkout\Controller\Address\Save. This is the transport plus the
 * refresh, and the refresh is deliberately a REPLACEMENT rather than a patch:
 * making one address the default un-defaults another, and only the server knows
 * which.
 */
define([
    'jquery',
    'underscore',
    'mage/url',
    'mage/translate',
    'Magento_Customer/js/model/address-list',
    'Magento_Customer/js/model/customer/address',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/action/select-shipping-address',
    'Magento_Checkout/js/model/shipping-rate-registry',
    'Magento_Checkout/js/checkout-data',
    'mage/cookies'
], function (
    $,
    _,
    urlBuilder,
    $t,
    addressList,
    CustomerAddress,
    quote,
    selectShippingAddress,
    rateRegistry,
    checkoutData
) {
    'use strict';

    /**
     * Flatten the checkout form's address data into the parameters Magento's
     * `customer_address_edit` metadata form reads.
     *
     * That form extracts by ATTRIBUTE CODE off the request, so every value has
     * to sit at the top level under its own code. Two shapes need unwrapping:
     *
     *   custom_attributes  the checkout nests these; `additional_phone` has to
     *                      arrive as a plain `additional_phone` parameter or the
     *                      attribute's own extractor never sees it.
     *   street             stays nested. jQuery serialises {0: 'x'} as
     *                      `street[0]=x`, which PHP reads back as an array -
     *                      which is what a multiline attribute expects.
     *
     * @param {Object} formData
     * @return {Object}
     */
    function toRequestParams(formData) {
        var params = {};

        _.each(formData, function (value, key) {
            if (key === 'custom_attributes') {
                _.each(value, function (attributeValue, attributeCode) {
                    // The checkout sometimes nests a custom attribute as
                    // {value: x} and sometimes hands over the scalar, depending
                    // on which side of the REST boundary it came from.
                    params[attributeCode] = _.isObject(attributeValue) && 'value' in attributeValue
                        ? attributeValue.value
                        : attributeValue;
                });

                return;
            }

            if (_.isFunction(value)) {
                return;
            }

            params[key] = value;
        });

        return params;
    }

    return {
        /**
         * Create or update one address.
         *
         * `addressId` is what makes it an edit. Passing a falsy id creates;
         * passing a real one updates THAT address and cannot create a second -
         * the server loads it, checks it belongs to the signed-in customer, and
         * saves back onto the same record.
         *
         * @param {Object} formData        the checkout address form's own data
         * @param {Number|String|null} addressId
         * @param {Boolean} isDefaultShipping
         * @return {jQuery.Deferred}
         */
        save: function (formData, addressId, isDefaultShipping) {
            var params = toRequestParams(formData);

            if (addressId) {
                params['address_id'] = addressId;
            }

            // Always sent, never omitted. The server distinguishes "not
            // supplied" from "supplied as off", and only an explicit off clears
            // an existing default.
            params['default_shipping'] = isDefaultShipping ? 1 : 0;

            // Read at submit time, not at load: Magento rotates the form key
            // when a session is regenerated, and a key captured when this module
            // loaded would by then be stale.
            params['form_key'] = $.mage.cookies.get('form_key');

            return $.ajax({
                url: urlBuilder.build('spartrak_checkout/address/save'),
                type: 'POST',
                dataType: 'json',
                data: params
            });
        },

        /**
         * Replace the checkout's address list with what the server now holds.
         *
         * @param {Object} addresses keyed by address id, in checkoutConfig shape
         * @return {Array} the rebuilt address objects
         */
        refresh: function (addresses) {
            var items = [];

            _.each(addresses || {}, function (item) {
                // Core's own factory, so the objects are indistinguishable from
                // the ones the page was rendered with - same getKey(), same
                // isDefaultShipping(), same customAttributes shape.
                items.push(new CustomerAddress(item));
            });

            /**
             * window.customerData is the array Magento_Customer's own
             * customer-addresses model reads on first load. Anything that
             * rebuilds from it later - a component initialising after this
             * point - would otherwise get the pre-save list back.
             */
            if (window.customerData) {
                window.customerData.addresses = addresses;
            }

            addressList(items);

            return items;
        },

        /**
         * Point the quote at an address again after it has been edited.
         *
         * ===================================================================
         * WHY THE RATE CACHE HAS TO BE CLEARED
         * ===================================================================
         * An address's key is `customer-address` plus its id, so editing one
         * does NOT change its key. Shipping rates are cached against that key,
         * so selecting the edited address again would serve the rates quoted
         * for the OLD address - the wrong governorate, possibly the wrong
         * price, with nothing on screen to suggest it.
         *
         * Clearing the entry forces a fresh quote for the address as it now is.
         *
         * @param {Object} address
         */
        reselect: function (address) {
            if (!address) {
                return;
            }

            rateRegistry.set(address.getKey(), null);
            selectShippingAddress(address);
            checkoutData.setSelectedShippingAddress(address.getKey());
        },

        /**
         * Whether this address is the one the quote is currently shipping to.
         *
         * Asked BEFORE a save, so the caller knows whether re-selecting
         * afterwards is required or would be a surprise - editing an address
         * the shopper is not using must not silently switch them onto it.
         *
         * @param {Number|String} addressId
         * @return {Boolean}
         */
        isSelected: function (addressId) {
            var shipping = quote.shippingAddress();

            if (!shipping || !addressId) {
                return false;
            }

            return String(shipping.customerAddressId) === String(addressId);
        },

        /**
         * @param {Array} items
         * @param {Number|String} addressId
         * @return {Object|null}
         */
        find: function (items, addressId) {
            return _.find(items, function (item) {
                return String(item.customerAddressId) === String(addressId);
            }) || null;
        },

        /**
         * @return {String}
         */
        genericErrorMessage: function () {
            return $t('We could not save your address. Please try again.');
        }
    };
});
