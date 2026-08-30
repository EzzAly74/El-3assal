<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Locale\Model;

use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * "Which language is this store view speaking?" - asked in exactly one place.
 *
 * ===========================================================================
 * WHY IT LIVES HERE
 * ===========================================================================
 * This class began life as Spartrak\Homepage\Model\LocaleContext, because the
 * homepage was the first thing that needed per-locale content columns. It is
 * not a homepage concern: pickup locations, and anything else that stores an
 * _ar/_en column pair, needs the identical question answered the identical
 * way. A fulfilment module depending on the HOMEPAGE module to find out what
 * language it is speaking would be a dependency pointing the wrong direction.
 *
 * Spartrak_Locale already owns this storefront's locale behaviour (Latin
 * digits in money formatting - see Model\Numbering), so it is the module that
 * should own the question.
 *
 * The old name is kept as a thin subclass rather than deleted - see
 * Spartrak\Homepage\Model\LocaleContext for why the eight homepage callers
 * were left alone.
 *
 * ===========================================================================
 * WHAT IT DOES NOT DO
 * ===========================================================================
 * It does not pick a value. Callers append the suffix it returns to a base
 * column name and read their own data. That keeps the resolution RULE in one
 * place while leaving every consumer's storage shape its own business - which
 * is what makes the _ar/_en to store-value-table migration a one-class change
 * if a third storefront language ever arrives.
 *
 * The store's own configured locale is the signal - NOT the theme, and not a
 * dir="rtl" guess. A store view is what an admin actually switches when they
 * publish Arabic content, and it is what Magento already scopes every other
 * translated value to.
 */
class StoreLanguage
{
    /**
     * In-request memo. A page that renders several banner sections, or a depot
     * list of forty rows, asks this once per row for a value that cannot
     * change mid-request.
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
     * ar_SA and any other Arabic store view all resolve to the Arabic content
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
     * Callers append it to a base column name (`title_`, `name_`, `address_`)
     * rather than branching on a boolean, which keeps a multi-column lookup to
     * one expression instead of a nest of conditionals.
     */
    public function getColumnSuffix(?int $storeId = null): string
    {
        return $this->isArabic($storeId) ? 'ar' : 'en';
    }

    /**
     * The suffix to fall back to when the current locale's column is empty.
     *
     * An admin who has only filled in the Arabic name should still get a
     * readable branch card on the English store view rather than a blank one.
     * A record in the other language is a far better failure than a hole.
     */
    public function getFallbackColumnSuffix(?int $storeId = null): string
    {
        return $this->isArabic($storeId) ? 'en' : 'ar';
    }

    /**
     * Reads a per-locale column pair off any array-shaped record.
     *
     * Centralised here because every consumer of this class was otherwise
     * writing the same three lines: try the current locale's column, fall back
     * to the other one, then give up. `$base` is the column name WITHOUT its
     * language suffix and without the trailing underscore - `name`, `address`,
     * `title`.
     *
     * @param array<string, mixed> $record
     */
    public function pick(array $record, string $base, ?int $storeId = null): ?string
    {
        foreach ([$this->getColumnSuffix($storeId), $this->getFallbackColumnSuffix($storeId)] as $suffix) {
            $value = $record[$base . '_' . $suffix] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
