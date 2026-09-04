/**
 * Spartrak - the checkout's single call to action.
 *
 * ===========================================================================
 * ONE BUTTON, TWO JOBS
 * ===========================================================================
 * Magento gives the shipping step a "Next" button and every payment method its
 * own "Place Order" button. Figma gives the checkout exactly one CTA, in the
 * order summary column, whose label changes with the step:
 *
 *   shipping step (549:26490)  ->  `المتابعة الي الدفع`
 *   payment step  (554:11804)  ->  `ادفع الان 1,748.98 ج.م`
 *
 * So this component reads the active step and dispatches. It does NOT
 * reimplement either action:
 *
 *   - Advancing to payment calls the core shipping component's own
 *     setShippingInformation(), which is what core's Next button calls. That
 *     method validates the address, saves it, and moves the step; duplicating
 *     any of that here would be a second copy of the checkout's most
 *     load-bearing transition.
 *
 *   - Placing the order calls the SELECTED payment renderer's own placeOrder().
 *     Paymob redirects to a hosted page afterwards, InstaPay stores a transfer,
 *     cash on delivery just places. Those differences belong to the
 *     integrations and must stay there. See
 *     Spartrak_Checkout/js/model/payment-registry for how the renderer is found.
 *
 * ===========================================================================
 * WHY THE AMOUNT IS IN THE LABEL
 * ===========================================================================
 * Figma puts the grand total on the button. That is a real safeguard, not
 * decoration: it is the last place a shopper sees the figure before they are
 * committed, and it updates with the totals - apply a coupon and the button
 * changes with the summary above it. It is read from quote.totals(), the same
 * source the summary rows use, so the two can never disagree.
 */
define([
    'ko',
    'uiComponent',
    'uiRegistry',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/step-navigator',
    'Magento_Catalog/js/price-utils',
    'Magento_Checkout/js/model/payment/additional-validators',
    // Magento_CUSTOMER, not Magento_Checkout: the address book belongs to the
    // customer module even though only the checkout reads it. The wrong
    // namespace 404s, which RequireJS reports as a script error that takes the
    // whole component down - and this component is the checkout's only CTA.
    'Magento_Customer/js/model/address-list',
    'Spartrak_Checkout/js/model/delivery-mode',
    'Spartrak_Checkout/js/model/payment-registry',
    'mage/translate'
], function (
    ko,
    Component,
    registry,
    quote,
    stepNavigator,
    priceUtils,
    additionalValidators,
    addressList,
    deliveryMode,
    paymentRegistry,
    $t
) {
    'use strict';

    /**
     * Core's own name for the shipping component. Stable across 2.4.x and the
     * name core's Next button is itself bound to.
     */
    var SHIPPING_COMPONENT = 'checkout.steps.shipping-step.shippingAddress';

    return Component.extend({
        defaults: {
            template: 'Spartrak_Checkout/place-order'
        },

        /** @inheritdoc */
        initialize: function () {
            this._super();

            // Guards against a double submit: a second click while the first
            // request is still in flight would place two orders. Core's
            // per-method buttons each own an isPlaceOrderActionAllowed flag;
            // there is one button here, so there is one flag here.
            this.isBusy = ko.observable(false);

            return this;
        },

        /**
         * True while the shopper is on the payment step.
         *
         * ===================================================================
         * WHY NOT getActiveItemIndex()
         * ===================================================================
         * That method returns an index into `steps().sort(sortItems)` - a
         * SORTED array - while `steps()` itself is in registration order. Using
         * the index against the unsorted array reads whichever step happens to
         * sit at that position, which is right only by luck and wrong as soon
         * as a module registers a step out of order.
         *
         * One-page checkout shows exactly one step at a time, so the honest
         * question is simply "is the payment step the visible one". Asking it
         * directly needs no index and no assumption about ordering, and it
         * stays reactive: both `steps()` and each `isVisible()` are
         * observables, so the label and the enabled state re-evaluate when the
         * shopper moves between steps.
         *
         * @return {Boolean}
         */
        isPaymentStep: function () {
            return stepNavigator.steps().some(function (step) {
                return step.code === 'payment' && step.isVisible();
            });
        },

        /**
         * @return {String}
         */
        label: function () {
            if (!this.isPaymentStep()) {
                return $t('Continue to payment');
            }

            var total = this.grandTotal();

            return total === null
                ? $t('Pay now')
                : $t('Pay now') + ' ' + total;
        },

        /**
         * The grand total, formatted by Magento's own price formatter so it
         * matches the summary row exactly - same separators, same currency
         * symbol, same precision.
         *
         * @return {String|null}
         */
        grandTotal: function () {
            var totals = quote.getTotals()();

            if (!totals || totals['grand_total'] === undefined) {
                return null;
            }

            return priceUtils.formatPrice(
                totals['grand_total'],
                quote.getPriceFormat()
            );
        },

        /**
         * Whether the button can be pressed.
         *
         * Three questions, one per state the button can be in: is a submission
         * already in flight, is a payment method chosen (payment step), and is
         * there both a shipping method and - on delivery - an address to ship
         * to (shipping step). The last one is argued in full below.
         *
         * @return {Boolean}
         */
        isEnabled: function () {
            if (this.isBusy()) {
                return false;
            }

            if (this.isPaymentStep()) {
                return !!quote.paymentMethod();
            }

            // The shipping METHOD is the common requirement: every mode,
            // delivery and pickup alike, sets one.
            if (!quote.shippingMethod()) {
                return false;
            }

            /**
             * ===============================================================
             * DELIVERY ADDITIONALLY NEEDS SOMEWHERE TO DELIVER TO
             * ===============================================================
             * Pickup modes synthesise the shipping address from the chosen
             * location rather than from the address book (see
             * Spartrak\PickupLocation\Plugin\Checkout\ApplyPickupLocation), so
             * requiring an address for them would lock a branch-pickup shopper
             * out of their own checkout - which is exactly the bug this
             * `isPickup()` branch exists to prevent.
             *
             * Delivery is the other half of that rule, and it was missing.
             * Figma's empty-address state (552:11748) draws this button
             * DISABLED in `#8faef0`, which is the design saying: do not let
             * someone try to ship to nowhere. Without the check the CTA was
             * live with an empty address book, and pressing it posted a quote
             * with no shipping address for the server to reject.
             *
             * `addressList` is the same source the step's own empty state is
             * keyed on (`spartrakHasAddresses` in js/view/shipping-mixin), so
             * the button and the panel above it can never disagree about
             * whether there is an address. It covers a guest too: core's
             * `createShippingAddress` pushes the typed address onto this list,
             * so filling the pop-up enables the button for somebody with no
             * account.
             */
            if (deliveryMode.isPickup()) {
                return true;
            }

            return addressList().length > 0;
        },

        /**
         * @return {Boolean}
         */
        submit: function () {
            if (!this.isEnabled()) {
                return false;
            }

            return this.isPaymentStep() ? this.placeOrder() : this.continueToPayment();
        },

        /**
         * Hand off to core's shipping component - the same call its own Next
         * button makes.
         *
         * registry.async is used rather than registry.get because on a slow
         * connection the button can be clicked before the shipping component
         * has finished registering; async waits for it instead of failing
         * silently on an undefined.
         *
         * @return {Boolean}
         */
        continueToPayment: function () {
            registry.async(SHIPPING_COMPONENT)(function (shipping) {
                if (shipping && typeof shipping.setShippingInformation === 'function') {
                    shipping.setShippingInformation();
                }
            });

            return true;
        },

        /**
         * @return {Boolean}
         */
        placeOrder: function () {
            var renderer = paymentRegistry.get(quote.paymentMethod().method),
                self = this,
                subscription;

            if (!renderer || typeof renderer.placeOrder !== 'function') {
                return false;
            }

            // The agreement checkboxes and any other cross-cutting validator.
            // Core runs these inside each renderer's placeOrder too; running
            // them here as well is deliberate, so the button does not enter its
            // busy state for a submission that was never going to proceed.
            if (!additionalValidators.validate()) {
                return false;
            }

            this.isBusy(true);

            /**
             * Released by the renderer's own signal, not by a timer.
             *
             * Every renderer extending Magento_Checkout/js/view/payment/default
             * sets isPlaceOrderActionAllowed(false) as it submits and back to
             * true in the request's .always() handler - so it flips on success,
             * on failure, and on a network error alike. Watching it means the
             * button re-enables exactly when the order attempt is genuinely
             * over, instead of after a guessed number of seconds that is too
             * long on a fast connection and too short on a slow one.
             *
             * A redirecting gateway never flips it back, which is correct: the
             * page is leaving, and the button must stay dead until it does.
             */
            subscription = renderer.isPlaceOrderActionAllowed.subscribe(function (allowed) {
                if (allowed) {
                    self.isBusy(false);
                    subscription.dispose();
                }
            });

            // placeOrder() returns false when its own validation rejected the
            // submission before any request went out - in which case the
            // observable above will never fire and the button would stay stuck.
            if (renderer.placeOrder() === false) {
                this.isBusy(false);
                subscription.dispose();

                return false;
            }

            return true;
        }
    });
});
