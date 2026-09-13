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
define(["jquery", "mage/url", "mage/utils/wrapper"], function (
  $,
  url,
  wrapper,
) {
  "use strict";

  return function (authenticationPopup) {
    authenticationPopup.showModal = wrapper.wrapSuper(
      authenticationPopup.showModal,
      function () {
        var $modal = $(".spartrak-auth");

        // `instance` is jQuery UI's own registry lookup: it returns
        // undefined when the widget has not been created on the
        // element, which is the honest test for "is the Spartrak modal
        // actually usable right now".
        if ($modal.length && $modal.spartrakAuth("instance")) {
          $modal.spartrakAuth("open", "login", "checkout");

          return;
        }

        /*
         * ===============================================================
         * THE FALLBACK IS A PAGE, NOT A POPUP, AND HAS TO BE
         * ===============================================================
         * This used to call `this._super()`, which opens Magento's own
         * authentication popup. That popup no longer exists: the theme
         * removes its block in Magento_Customer/layout/default.xml,
         * because it was rendering on every page only to be ignored and
         * dragging ~170KB of validation JavaScript in with it (the file
         * carries the measurements).
         *
         * `_super()` would therefore reach for `#authenticationPopup`,
         * find nothing, and fail SILENTLY - the shopper clicks "sign in"
         * and the page just sits there. A dead end is worse than a
         * redirect, so this branch now navigates to the account login
         * page, which is always present and needs no JavaScript.
         *
         * It should stay unreachable in practice: the Spartrak modal is
         * mounted by Magento_Theme's default handle, so it is on every
         * frontend page. This exists for the case where it is not -
         * a page that strips the header, or a widget that has not
         * finished initialising when the shopper clicks.
         *
         * NO `next` PARAMETER. Where the shopper goes after signing in
         * is decided server-side against an allowlist
         * (Spartrak\CustomerAuth\ViewModel\PostLoginDestinations); the
         * modal passes a KEY for that reason, and appending a
         * destination to this URL from the browser would be exactly the
         * open-redirect the allowlist exists to prevent.
         */
        window.location.href = url.build("customer/account/login");
      },
    );

    return authenticationPopup;
  };
});
