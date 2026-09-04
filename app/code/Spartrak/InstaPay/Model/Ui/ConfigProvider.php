<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\InstaPay\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\UrlInterface;

/**
 * The InstaPay method's own slice of window.checkoutConfig.
 *
 * Exactly one thing crosses: the URL the shopper is sent to once the order
 * exists, so the renderer can hand them to the transfer page.
 *
 * ===========================================================================
 * THE MERCHANT'S NUMBER IS NOT PUBLISHED HERE
 * ===========================================================================
 * It would have been convenient - the transfer page needs it - but it would put
 * the merchant's banking number into the HTML of every checkout page load,
 * including for shoppers who never choose InstaPay and for anything crawling
 * the site. The transfer page is a server-rendered page of its own and reads
 * the number directly, so it is only ever sent to someone who has actually
 * placed an order and reached that step.
 */
class ConfigProvider implements ConfigProviderInterface
{
    public const CODE = 'spartrak_instapay';

    public function __construct(
        private readonly UrlInterface $url
    ) {
    }

    /**
     * =========================================================================
     * PUBLISHED UNCONDITIONALLY. THE `isActive()` GATE WAS A REAL OUTAGE.
     * =========================================================================
     * This used to return [] unless payment/spartrak_instapay/active read true
     * here. Reproduced on the live store: the method WAS offered in the payment
     * list (PaymentMethodManagement::getList had it), the shopper chose it, the
     * order was created - POST to /rest/ar/V1/carts/mine/payment-information
     * returned 200 with order id 13 - and then nothing happened.
     * `spartrak_instapay/transfer` was absent from the rendered checkout HTML,
     * so the renderer had no URL to send them to and left them on
     * /checkout/#payment reading "Current customer does not have an active cart".
     *
     * The order existed and the shopper had no way to reach the page that
     * collects the transfer receipt (Figma 586:7352) - so the money was never
     * paid and the order sat in pending_payment with nobody aware of it.
     *
     * The gate bought nothing to justify that. This value is a ROUTE URL, not a
     * secret: the merchant's transfer number and account name are deliberately
     * NOT published here (see the class docblock), and the transfer controller
     * does its own order and ownership checks before it renders anything. A
     * shopper who never chooses InstaPay simply never follows the URL.
     *
     * Two config reads disagreeing across two requests is a failure mode with
     * no upside, so it is removed rather than patched.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return [
            'payment' => [
                self::CODE => [
                    // Where the renderer sends the shopper once the order
                    // exists. Built server-side so it carries the store code and
                    // the right base URL, which a JS-assembled path would not.
                    'transferUrl' => $this->url->getUrl('spartrak_instapay/transfer/index'),
                ],
            ],
        ];
    }
}
