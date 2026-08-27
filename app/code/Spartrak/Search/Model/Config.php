<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Search\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed reader for the `spartrak_search/suggestions` store configuration.
 *
 * A class rather than scattered scopeConfig->getValue() calls so the config
 * paths are written once, the casts happen once, and no consumer has to know
 * that Magento returns everything from core_config_data as a string.
 */
class Config
{
    private const XML_PATH_ENABLED = 'spartrak_search/suggestions/enabled';
    private const XML_PATH_PRODUCT_LIMIT = 'spartrak_search/suggestions/product_limit';
    private const XML_PATH_TERM_LIMIT = 'spartrak_search/suggestions/term_limit';
    private const XML_PATH_CACHE_LIFETIME = 'spartrak_search/suggestions/cache_lifetime';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * How many product cards the rail may show.
     *
     * Clamped rather than trusted: the admin field is digits-validated, but a
     * value pasted straight into core_config_data is not, and an unbounded
     * page size here would let one request load the whole catalogue.
     */
    public function getProductLimit(): int
    {
        return $this->clamp((int) $this->scopeConfig->getValue(
            self::XML_PATH_PRODUCT_LIMIT,
            ScopeInterface::SCOPE_STORE
        ), 0, 24);
    }

    public function getTermLimit(): int
    {
        return $this->clamp((int) $this->scopeConfig->getValue(
            self::XML_PATH_TERM_LIMIT,
            ScopeInterface::SCOPE_STORE
        ), 0, 20);
    }

    /**
     * Seconds a rendered panel may be reused. 0 disables the cache entirely.
     */
    public function getCacheLifetime(): int
    {
        return max(0, (int) $this->scopeConfig->getValue(
            self::XML_PATH_CACHE_LIFETIME,
            ScopeInterface::SCOPE_STORE
        ));
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
