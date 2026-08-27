<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model;

use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * "Which language is this store view speaking?" — asked in exactly one place.
 *
 * Every per-locale column on a section or banner (title_ar/title_en,
 * image_desktop_ar/image_desktop_en, ...) is chosen through this class and
 * never by a template testing a locale string itself. That is what keeps the
 * db_schema.xml note honest: if a third language ever arrives and the _en/_ar
 * column pair has to become a store-value table, the resolution rule changes
 * HERE and the callers do not change at all.
 *
 * The store's own configured locale is the signal — NOT the theme, and not a
 * `dir="rtl"` guess. A store view is what an admin actually switches when
 * they publish Arabic content, and it is what Magento already scopes every
 * other translated value to.
 */
class LocaleContext
{
    /**
     * In-request memo. The homepage resolves the locale once per banner and
     * once per section title; on a page with several banner sections that is
     * a dozen-plus calls for a value that cannot change mid-request.
     *
     * @var array<int, bool>
     */
    private array $isArabicByStore = [];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * True when the given store view (default: current) renders in Arabic.
     *
     * Matches on the LANGUAGE subtag, not the full locale code, so ar_EG,
     * ar_SA and any other Arabic store view all resolve to the Arabic assets
     * without this class needing a list of countries.
     */
    public function isArabic(?int $storeId = null): bool
    {
        $storeId = $storeId ?? (int) $this->storeManager->getStore()->getId();

        if (!isset($this->isArabicByStore[$storeId])) {
            $locale = (string) $this->scopeConfig->getValue(
                DirectoryHelper::XML_PATH_DEFAULT_LOCALE,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );

            $this->isArabicByStore[$storeId] = str_starts_with(strtolower($locale), 'ar');
        }

        return $this->isArabicByStore[$storeId];
    }

    /**
     * The column suffix for the current locale: 'ar' or 'en'.
     *
     * Callers append it to a base column name (`title_`, `image_desktop_`)
     * rather than branching on a boolean, which keeps the four-image banner
     * lookup to one expression instead of four nested conditionals.
     */
    public function getColumnSuffix(?int $storeId = null): string
    {
        return $this->isArabic($storeId) ? 'ar' : 'en';
    }

    /**
     * The suffix to fall back to when the current locale's column is empty.
     *
     * An admin who has only uploaded the Arabic artwork should still get a
     * banner on the English store view rather than a hole in the page — the
     * brief calls the destination URL optional but says nothing about images
     * being optional per language, and a missing banner is a worse failure
     * than a banner in the other language.
     */
    public function getFallbackColumnSuffix(?int $storeId = null): string
    {
        return $this->isArabic($storeId) ? 'en' : 'ar';
    }
}
