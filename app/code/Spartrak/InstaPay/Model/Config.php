<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\InstaPay\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Spartrak\InstaPay\Model\Ui\ConfigProvider;

/**
 * The merchant's InstaPay details, read once and asked for by name.
 *
 * Every consumer - the transfer page, the admin order block, the order comment
 * - would otherwise repeat the same `payment/spartrak_instapay/...` string, and
 * a typo in one of them fails silently as an empty value. Naming the paths once
 * is the whole job.
 */
class Config
{
    private const PATH = 'payment/' . ConfigProvider::CODE . '/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isActive(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH . 'active', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getTitle(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(self::PATH . 'title', ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * The number shoppers transfer TO.
     */
    public function getMerchantNumber(?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(
            self::PATH . 'merchant_number',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * The masked account name InstaPay shows against that number, so a shopper
     * can confirm they are paying the right person before they send anything.
     */
    public function getMerchantName(?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(
            self::PATH . 'merchant_name',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Whether the method is usable at all.
     *
     * Active is not enough: without a transfer number there is nowhere for the
     * money to go, and showing the method anyway would collect receipts for
     * transfers nobody received. The checkout and the transfer page both ask
     * this rather than `isActive()` alone.
     */
    public function isUsable(?int $storeId = null): bool
    {
        return $this->isActive($storeId) && $this->getMerchantNumber($storeId) !== '';
    }
}
