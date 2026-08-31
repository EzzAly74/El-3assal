/**
 * Registers the InstaPay method with the checkout's renderer list.
 *
 * Boilerplate Magento requires: a component whose only job is to say "the
 * method `spartrak_instapay` is drawn by this renderer". The renderer itself is
 * the file below it.
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'spartrak_instapay',
        component: 'Spartrak_InstaPay/js/view/payment/method-renderer/instapay'
    });

    return Component.extend({});
});
