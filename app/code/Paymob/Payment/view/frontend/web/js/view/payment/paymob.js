define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (
    Component,
    rendererList
) {
    'use strict';

    var methods = window.checkoutConfig.payment || {};

    Object.keys(methods).forEach(function (code) {
        if (code.startsWith('paymob_')) {
            rendererList.push({
                type: code,
                component: 'Paymob_Payment/js/view/payment/method-renderer/paymob'
            });
        }
    });

    return Component.extend({});
});
