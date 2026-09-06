<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Contact\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed reader for this module's `spartrak_contact/details/*` configuration.
 *
 * One class between ScopeConfigInterface and everything else in the module, so
 * a path string is written exactly once. Every getter resolves at STORE scope:
 * the storefront is bilingual and the Arabic and English store views carry
 * different address and opening-hours strings.
 */
class Config
{
    private const PATH_PREFIX = 'spartrak_contact/details/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getWhatsApp(?int $storeId = null): string
    {
        return $this->value('whatsapp', $storeId);
    }

    public function getHotline(?int $storeId = null): string
    {
        return $this->value('hotline', $storeId);
    }

    /**
     * The showroom lines, as a list.
     *
     * Stored as one comma-separated field because it is one row in the design
     * and one thing in the merchant's head ("the showroom's numbers"), but the
     * dialog has to make each entry separately dialable — so the split belongs
     * here rather than in the template (CLAUDE.md §8: no business logic in
     * .phtml).
     *
     * @return string[]
     */
    public function getShowroomPhones(?int $storeId = null): array
    {
        $raw = $this->value('showroom_phones', $storeId);

        if ($raw === '') {
            return [];
        }

        // array_values so the caller gets a list, not a sparse array, after a
        // trailing comma or a double separator has been filtered out.
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $n): bool => $n !== ''));
    }

    public function getEmail(?int $storeId = null): string
    {
        return $this->value('email', $storeId);
    }

    public function getAddress(?int $storeId = null): string
    {
        return $this->value('address', $storeId);
    }

    public function getDirectionsUrl(?int $storeId = null): string
    {
        return $this->value('directions_url', $storeId);
    }

    public function getHours(?int $storeId = null): string
    {
        return $this->value('hours', $storeId);
    }

    /**
     * Trimmed, never null.
     *
     * A textarea saved with only whitespace in it is indistinguishable from an
     * empty one as far as the shopper is concerned, and the dialog drops a row
     * whose value is empty — so normalising here is what stops a blank card
     * with a heading and no content rendering.
     */
    private function value(string $field, ?int $storeId): string
    {
        return trim((string) $this->scopeConfig->getValue(
            self::PATH_PREFIX . $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }
}
