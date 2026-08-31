<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\InstaPay\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The InstaPay method's own slice of window.checkoutConfig.
 *
 * Only two things cross: the URL the shopper is sent to after the order is
 * created, and whether the method is active.
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
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly UrlInterface $url
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        if (!$this->isActive()) {
            return [];
        }

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

    private function isActive(): bool
    {
        return $this->scopeConfig->isSetFlag(
            'payment/' . self::CODE . '/active',
            ScopeInterface::SCOPE_STORE
        );
    }
}
