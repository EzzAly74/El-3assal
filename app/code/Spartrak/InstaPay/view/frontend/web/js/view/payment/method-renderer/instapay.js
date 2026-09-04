/**
 * The InstaPay renderer.
 *
 * ===========================================================================
 * IT DRAWS NOTHING OF ITS OWN
 * ===========================================================================
 * No template is declared here on purpose. Spartrak_Payment's mixin assigns
 * every payment renderer the same Figma row, so InstaPay's title, description
 * and logo come from the admin-managed presentation registry exactly like every
 * other method's. Declaring a template here would opt this method out of that
 * and make it the one row a merchant cannot edit.
 *
 * ===========================================================================
 * IT DOES NOT PLACE THE ORDER. THAT IS THE WHOLE POINT.
 * ===========================================================================
 * This used to call core's placeOrder(), create the order in `pending_payment`
 * and then redirect to the transfer page. Reversed as of this change: the
 * shopper goes to the transfer page with their basket INTACT, and the order is
 * created by Controller\Transfer\Save at the moment the receipt is uploaded.
 *
 * What that fixes, and it is not cosmetic:
 *
 *   - opening the transfer page no longer creates an order. Every shopper who
 *     glanced at the page and left used to leave a row in the sales grid.
 *   - pressing back no longer CANCELS an order, because there is none. The
 *     grid stopped filling with `canceled` orders nobody placed.
 *   - the quote is never consumed, so the minicart, its counter and the cart
 *     page cannot disagree. That whole class of "the cart page has my items but
 *     the drawer says it is empty" is gone, because nothing empties the cart
 *     until there is a real order.
 *
 * THE TRADE, STATED. Stock is no longer reserved while the shopper is in their
 * banking app, so two people can both transfer for the last unit and the second
 * one's placeOrder fails. That is a real risk and it is the one the merchant
 * chose: it is bounded by how long the upload takes, where the old behaviour
 * held stock for as long as the shopper was gone - which could be days.
 *
 * ===========================================================================
 * WHY set-payment-information AND NOT JUST A REDIRECT
 * ===========================================================================
 * The transfer page has to know the shopper picked InstaPay, and Save has to be
 * able to place the order from the quote - which needs the payment method and
 * the billing address ON the quote. `set-payment-information` is core's own
 * action for exactly that: it saves both and does NOT create an order. Sending
 * them to the transfer page without it would give Save a quote with no payment
 * method and a placeOrder that throws.
 */
define([
  "Magento_Checkout/js/view/payment/default",
  "Magento_Checkout/js/model/full-screen-loader",
  "Magento_Checkout/js/model/payment/additional-validators",
  "Magento_Checkout/js/action/set-payment-information",
  "Magento_Checkout/js/action/redirect-on-success",
], function (
  Component,
  fullScreenLoader,
  additionalValidators,
  setPaymentInformationAction,
  redirectOnSuccessAction,
) {
  "use strict";

  /**
   * The transfer page, published by Model\Ui\ConfigProvider so the store code
   * and base URL come from Magento rather than being assembled here.
   *
   * @return {String}
   */
  function transferUrl() {
    var config = window.checkoutConfig || {},
      payment = (config.payment || {})["spartrak_instapay"] || {};

    return payment.transferUrl || "";
  }

  return Component.extend({
    redirectAfterPlaceOrder: false,

    /**
     * @return {String} the code this renderer is registered against
     */
    getCode: function () {
      return "spartrak_instapay";
    },

    /**
     * Save the choice to the quote, then hand over to the transfer page.
     *
     * The signature is core's, including the `event` argument, because
     * Spartrak_Checkout's single CTA and core's own per-method button both
     * call it and the second passes a click event.
     *
     * Returning false is how a renderer tells the CTA that its own
     * validation rejected - see Spartrak_Checkout/js/view/place-order.
     *
     * @param {Object} [data]
     * @param {Event} [event]
     * @return {Boolean}
     */
    placeOrder: function (data, event) {
      var self = this,
        url = transferUrl();

      if (event) {
        event.preventDefault();
      }

      if (!this.validate() || !additionalValidators.validate()) {
        return false;
      }

      if (!url) {
        /**
         * =========================================================
         * BACKSTOP: NO TRANSFER PAGE, SO FALL BACK TO A REAL ORDER
         * =========================================================
         * ConfigProvider publishes the URL unconditionally, so this
         * should be unreachable. It is kept because the alternative
         * when it is not is a button that silently does nothing: better
         * to place the order the ordinary way and let afterPlaceOrder()
         * below send the shopper to the success page, from which the
         * order view still carries the InstaPay instructions.
         */
        return this._super(data, event);
      }

      this.isPlaceOrderActionAllowed(false);
      fullScreenLoader.startLoader();

      setPaymentInformationAction(this.messageContainer, this.getData())
        .done(function () {
          // replace(), not assign(): the payment step must not be
          // reachable with the back button from the transfer page,
          // which has its own explicit way out.
          window.location.replace(url);
        })
        .fail(function () {
          // The loader is stopped ONLY on failure. On success the
          // page is leaving, and a checkout that looks interactive
          // during a redirect invites a second click.
          fullScreenLoader.stopLoader();
          self.isPlaceOrderActionAllowed(true);
        });

      return true;
    },

    /**
     * Only reached through the backstop above, where an order really was
     * created. `redirectAfterPlaceOrder: false` means core will not move
     * the shopper, so this has to.
     *
     * @return {void}
     */
    afterPlaceOrder: function () {
      fullScreenLoader.startLoader();
      redirectOnSuccessAction.execute();
    },
  });
});
