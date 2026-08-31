<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Payment\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * How each payment method is PRESENTED in the Spartrak checkout row.
 *
 * ===========================================================================
 * WHAT THIS IS NOT
 * ===========================================================================
 * It is not a list of payment methods. Which methods exist, and whether they
 * are available for a given quote, is Magento's answer and only Magento's -
 * the checkout renders whatever `paymentMethods()` gives it. Figma draws four
 * rows; the store might have one, or seven. Both must work.
 *
 * This class answers a much smaller question: GIVEN a method code Magento has
 * already decided to show, what description and which brand marks go on it.
 * A method with no entry here still renders - it just falls back to its own
 * Magento title and shows no marks. That fallback is the important part: it is
 * what makes enabling a new method a configuration change rather than a code
 * change.
 *
 * ===========================================================================
 * WHY DYNAMIC ROWS IN system.xml RATHER THAN AN ENTITY + ADMIN GRID
 * ===========================================================================
 * The sibling module Spartrak_PickupLocation is a full entity with a grid,
 * because a branch is a real business record: it has a lifecycle, hundreds of
 * instances, and an order has to keep pointing at one after it is renamed.
 *
 * A presentation row is none of those things. There is at most one per enabled
 * payment method, it holds no history, and nothing references it. Magento's
 * dynamic-rows field is the pattern designed for exactly that shape, and it
 * arrives store-view-scoped and config-cached for free. Building a table, a
 * repository, a UI listing and five controllers to hold a handful of rows
 * would be ceremony, not architecture.
 *
 * If this ever grows scheduling, per-store artwork, or a lifecycle, promote it
 * to an entity then - the ConfigProvider is the only caller, so the blast
 * radius is one class.
 *
 * ===========================================================================
 * CACHING
 * ===========================================================================
 * None of its own. ScopeConfig is already served from the `config` cache, and
 * the only work on top is one json_decode of a few hundred bytes. Adding a
 * second cache layer over a cached read would be a slower cache miss and one
 * more thing to invalidate.
 */
class PresentationCatalog
{
    public const XML_PATH_ROWS = 'checkout/spartrak_payment/rows';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly Json $json,
        private readonly BrandMarks $brandMarks
    ) {
    }

    /**
     * Presentation keyed by payment method code, for the current store view.
     *
     * @return array<string, array{description: string, brands: array<int, array{url: string, label: string}>}>
     */
    public function getForCurrentStore(): array
    {
        $raw = $this->scopeConfig->getValue(
            self::XML_PATH_ROWS,
            ScopeInterface::SCOPE_STORE,
            $this->storeManager->getStore()->getId()
        );

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $rows = $this->json->unserialize($raw);
        } catch (\InvalidArgumentException) {
            // A hand-edited or half-migrated config value must not take the
            // checkout down. Falling back to "no presentation" renders every
            // method under its own Magento title, which is ugly but sells.
            return [];
        }

        if (!is_array($rows)) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = trim((string) ($row['method'] ?? ''));

            if ($code === '') {
                continue;
            }

            $out[$code] = [
                'description' => trim((string) ($row['description'] ?? '')),
                'brands'      => $this->brandMarks->resolve($this->splitBrands($row['brands'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * The dynamic-rows multiselect stores its value as a comma-joined string
     * when it round-trips through the config table, but hands back a real array
     * on the same request it was saved. Accept both rather than depending on
     * which side of that boundary the caller is on.
     *
     * @param mixed $value
     * @return string[]
     */
    private function splitBrands(mixed $value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } elseif (is_string($value) && $value !== '') {
            $parts = explode(',', $value);
        } else {
            return [];
        }

        $keys = [];

        foreach ($parts as $part) {
            $key = trim((string) $part);

            if ($key !== '' && $this->brandMarks->has($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
