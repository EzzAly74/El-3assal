/**
 * Spartrak — routes Magento's guest-checkout sign-in prompt to the Spartrak
 * auth modal.
 *
 * ===========================================================================
 * THE BUG THIS FIXES
 * ===========================================================================
 * Guest checkout is off on this store, so a signed-out shopper pressing
 * "buy now" is asked to sign in. Two DIFFERENT things were doing the asking:
 *
 *   /checkout entered directly   -> Spartrak\Checkout\Plugin\PromptLoginForGuest
 *                                   redirects to the cart with #auth=login,
 *                                   and the Spartrak modal opens. Correct.
 *
 *   the minicart or cart button  -> never reaches a controller at all. Core
 *                                   handles the click in JavaScript and calls
 *                                   authenticationPopup.showModal(), which
 *                                   opens MAGENTO'S OWN popup - the
 *                                   "Checkout using your account / Checkout as
 *                                   a new customer" box, in English, with none
 *                                   of the Spartrak design on it.
 *
 * The plugin could only ever have covered the first path. This covers the
 * second.
 *
 * ===========================================================================
 * WHY THE MODEL AND NOT EACH CALLER
 * ===========================================================================
 * Two core modules call showModal() for this - Magento_Checkout/js/sidebar
 * (the minicart drawer) and Magento_Checkout/js/proceed-to-checkout (the cart
 * page button) - and Magento_Customer calls it from its own header view.
 * Mixing into each caller would be three mixins that have to be kept in step,
 * and would miss any fourth caller a module adds later.
 *
 * `showModal` is the single choke point they all go through, so wrapping it
 * once covers every present and future call site.
 *
 * ===========================================================================
 * IT FALLS BACK TO CORE, IT DOES NOT REPLACE IT
 * ===========================================================================
 * If the Spartrak modal is not on the page - or its widget has not
 * initialised yet - the original showModal() runs untouched. A shopper is
 * never left with no way to sign in because an enhancement did not load.
 *
 * `next: 'checkout'` is a KEY, resolved by the widget against the server-side
 * allowlist. See Spartrak\CustomerAuth\ViewModel\PostLoginDestinations for why
 * that is a security boundary rather than a convenience.
 */
define([
    'jquery',
    'mage/utils/wrapper'
], function ($, wrapper) {
    'use strict';

    return function (authenticationPopup) {
        authenticationPopup.showModal = wrapper.wrapSuper(
            authenticationPopup.showModal,
            function () {
                var $modal = $('.spartrak-auth');

                // `instance` is jQuery UI's own registry lookup: it returns
                // undefined when the widget has not been created on the
                // element, which is the honest test for "is the Spartrak modal
                // actually usable right now".
                if ($modal.length && $modal.spartrakAuth('instance')) {
                    $modal.spartrakAuth('open', 'login', 'checkout');

                    return;
                }

                // No Spartrak modal on this page - let Magento ask.
                this._super();
            }
        );

        return authenticationPopup;
    };
});
