<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Checkout\Block\Checkout;

use Magento\Checkout\Block\Checkout\LayoutProcessorInterface;
use Magento\Payment\Api\PaymentMethodListInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * PAYMENT UI COMPONENTS FOR METHODS THAT ARE NOT SWITCHED ON DO NOT GET BUILT.
 *
 * ===========================================================================
 * THE DEFECT — measured on the live Arabic checkout, not assumed
 * ===========================================================================
 * `window.checkoutConfig` on this storefront reports:
 *
 *     "paymentMethods": []                              <- not one active method
 *     payment_services_paypal_hosted_fields  isVisible: false
 *     payment_services_paypal_smart_buttons  isVisible: false
 *     payment_services_paypal_apple_pay      isVisible: false
 *     payment_services_paypal_google_pay     isVisible: false
 *     payment_services_paypal_fastlane       isVisible: false
 *     payment_services_paypal_apm            isVisible: false
 *
 * and the only method carrying real configuration is `spartrak_instapay`.
 *
 * The same page nevertheless downloaded, parsed and executed the express
 * checkout, fastlane, smart-buttons, Google Pay, Apple Pay, PayLater and
 * Braintree component trees — roughly forty-five files — plus 29,977 bytes
 * pulled from js.braintreegateway.com by PayPal_Braintree's script helper.
 * All of it to render nothing.
 *
 * ===========================================================================
 * WHY IT HAPPENS, WHICH IS NOT WHAT IT LOOKS LIKE
 * ===========================================================================
 * It is tempting to read this as "a method is inactive but its renderer still
 * loads". That is not the mechanism. Magento_Checkout's payment list is
 * genuinely lazy: `Magento_Checkout/js/view/payment/list::createRenderer()`
 * only requires a METHOD renderer once a matching entry appears in the active
 * `paymentMethods` array.
 *
 * The files that ship are the ones AROUND that gate — the express-checkout
 * blocks on the shipping step, the fastlane watermarks, the renderer
 * registrars under `payment.renders`. Those are ordinary UI components sitting
 * in the checkout's jsLayout tree, and `Magento_Ui/js/core/renderer/layout`
 * instantiates every node it is handed. Nothing consults payment config on the
 * way, so an integration that is entirely switched off still costs a full
 * download → parse → compile → execute cycle on the main thread.
 *
 * ===========================================================================
 * WHY THIS IS A PROCESSOR AND NOT `componentDisabled` IN LAYOUT XML
 * ===========================================================================
 * Layout XML can silence these nodes — `componentDisabled: true` is honoured by
 * the renderer (layout.js line 291, and again in filterDisabledChildren) and it
 * would have been three dozen lines of XML and no PHP.
 *
 * It would also have been a trap. A merchant who switches PayPal on in Admin
 * next quarter would get a checkout that accepts the configuration, reports the
 * method as active, and then renders nothing — with the cause sitting in a
 * theme file nobody would think to look in. Layout XML cannot ask whether a
 * method is enabled; it can only assert that it never is.
 *
 * So the decision is made at render time from payment configuration itself. Turn
 * a method on and its components come back on the next request with no code
 * change; leave it off and the browser is never told it exists. The class needs
 * no maintenance when payment configuration changes, which is the whole point.
 *
 * `getActiveList()` and not the quote-filtered availability list, deliberately:
 * a method can be active in configuration yet unavailable for one particular
 * cart (order minimum, country, currency). Gating on ACTIVE keeps this class
 * conservative — it removes only what is switched off outright, and never
 * something a shopper might reach by changing their basket.
 *
 * Registered through Magento_Checkout's own `layoutProcessors` extension point;
 * nothing in vendor/ is touched and no core behaviour is replaced.
 */
class StripInactivePaymentComponents implements LayoutProcessorInterface
{
    /**
     * Component subtrees, and the payment-method code prefixes that justify
     * each one existing.
     *
     * A subtree survives when ANY active method code starts with ANY of its
     * prefixes. Paths are child names under `components.checkout.children`;
     * the `children` hops between them are implied and added while walking, so
     * these read as they do in the layout files they come from.
     *
     * Every entry was taken from the vendor layout that declares it —
     * module-payment-services-paypal, module-braintree-core and module-paypal,
     * all `view/frontend/layout/checkout_index_index.xml` — rather than from
     * the network waterfall, so a component that happens not to load on one
     * particular cart is still covered.
     *
     * @var array<string, string[]>
     */
    private const GATED_COMPONENTS = [
        // --- Magento_PaymentServicesPaypal ------------------------------------
        // Express buttons appear on BOTH steps; core declares the group twice.
        'steps/shipping-step/shippingAddress/payment-services-express-payments'
            => ['payment_services_paypal'],
        'steps/billing-step/payment/payment-services-express-payments'
            => ['payment_services_paypal'],
        // The renderer registrar for every payment-services method.
        'steps/billing-step/payment/renders/payment_services'
            => ['payment_services_paypal'],
        // Fastlane's address swap and its three "powered by" watermarks.
        'steps/shipping-step/shippingAddress/before-fields/change-address'
            => ['payment_services_paypal'],
        'steps/shipping-step/shippingAddress/customer-email/paypal-fastlane-email-watermark'
            => ['payment_services_paypal_fastlane'],
        'steps/billing-step/payment/customer-email/paypal-fastlane-email-watermark'
            => ['payment_services_paypal_fastlane'],
        'sidebar/shipping-information/paypal-fastlane-shipping-watermark'
            => ['payment_services_paypal_fastlane'],

        // --- PayPal_Braintree --------------------------------------------------
        // One express group holding PayPal, PayLater, Credit, Google Pay and
        // Apple Pay; every Braintree method code starts `braintree`.
        'steps/shipping-step/shippingAddress/braintree-express-payments'
            => ['braintree'],
        'steps/billing-step/payment/renders/braintree'
            => ['braintree'],
        'steps/billing-step/payment/renders/braintree_applepay'
            => ['braintree_applepay'],
        'steps/billing-step/payment/renders/braintree_googlepay'
            => ['braintree_googlepay'],

        // --- Magento_Paypal ----------------------------------------------------
        // Covers paypal_express, payflow*, hosted_pro and the billing agreement.
        'steps/billing-step/payment/renders/paypal-payments'
            => ['paypal', 'payflow', 'hosted_pro'],
        'steps/billing-step/payment/payments-list/paypal-method-extra-content'
            => ['paypal', 'payflow'],
    ];

    public function __construct(
        private readonly PaymentMethodListInterface $paymentMethodList,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @param array $jsLayout
     * @return array
     */
    public function process($jsLayout)
    {
        $active = $this->activeMethodCodes();

        foreach (self::GATED_COMPONENTS as $path => $prefixes) {
            if ($this->anyActive($active, $prefixes)) {
                continue;
            }

            $jsLayout = $this->removeNode($jsLayout, explode('/', $path));
        }

        return $jsLayout;
    }

    /**
     * Active payment method codes for the current store.
     *
     * Returns [] on failure rather than throwing. A checkout that renders one
     * unnecessary component is a performance defect; a checkout that 500s
     * because a payment list could not be read is a lost order, and an empty
     * list simply means nothing is stripped.
     *
     * @return string[]
     */
    private function activeMethodCodes(): array
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            $codes = [];

            foreach ($this->paymentMethodList->getActiveList($storeId) as $method) {
                $codes[] = (string) $method->getCode();
            }

            return $codes;
        } catch (\Exception $exception) {
            return [];
        }
    }

    /**
     * @param string[] $active
     * @param string[] $prefixes
     */
    private function anyActive(array $active, array $prefixes): bool
    {
        foreach ($active as $code) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($code, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Unset one node, walking `children` between each named hop.
     *
     * Silent when the path does not resolve — a payment module that is not
     * installed simply has no node to remove, and that is not an error worth
     * interrupting a checkout render for.
     *
     * @param string[] $path
     */
    private function removeNode(array $jsLayout, array $path): array
    {
        if (!isset($jsLayout['components']['checkout']['children'])) {
            return $jsLayout;
        }

        // Reference-walk to the parent of the leaf, then unset the leaf.
        $cursor = &$jsLayout['components']['checkout']['children'];
        $leaf = array_pop($path);

        foreach ($path as $segment) {
            if (!isset($cursor[$segment]['children'])) {
                unset($cursor);

                return $jsLayout;
            }

            $cursor = &$cursor[$segment]['children'];
        }

        unset($cursor[$leaf]);
        unset($cursor);

        return $jsLayout;
    }
}
