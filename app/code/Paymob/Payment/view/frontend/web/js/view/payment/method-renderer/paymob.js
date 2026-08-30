define([
    'Magento_Checkout/js/view/payment/default',
    'jquery',
    'mage/url',
    'ko',
    'Magento_Checkout/js/model/full-screen-loader'
], function (
    Component,
    $,
    url,
    ko,
    fullScreenLoader
) {
    'use strict';

    return Component.extend({
        // Set to true so Magento handles redirect via getOrderPlaceRedirectUrl()
        redirectAfterPlaceOrder: true,

        defaults: {
            template: 'Paymob_Payment/payment/paymob'
        },
        
        isBillingAddressRequired: ko.observable(true),

        /**
         * After place order - called automatically by Magento
         * Since redirectAfterPlaceOrder is true, Magento will redirect
         * to the URL from getOrderPlaceRedirectUrl()
         */
        afterPlaceOrder: function () {
            // Just show loader - Magento will handle the redirect
            fullScreenLoader.startLoader();
        },

        logo: function () {
            var code = this.getCode();
            return window.checkoutConfig.payment[code] && window.checkoutConfig.payment[code].logo
                ? window.checkoutConfig.payment[code].logo
                : '';
        },
        
        getTitle: function () {
            var code = this.getCode();
            return window.checkoutConfig.payment[code] && window.checkoutConfig.payment[code].title
                ? window.checkoutConfig.payment[code].title
                : code;
        },
        
        getInstructions: function () {
            var code = this.getCode();
            return window.checkoutConfig.payment[code] && window.checkoutConfig.payment[code].instructions
                ? window.checkoutConfig.payment[code].instructions
                : '';
        }
    });
});