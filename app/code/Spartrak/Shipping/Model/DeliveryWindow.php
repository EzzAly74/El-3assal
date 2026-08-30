<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Shipping\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * How long a shipping method takes, as a range of working days.
 *
 * ===========================================================================
 * WHY THIS IS A SERVICE AND NOT A STRING IN THE CARRIER
 * ===========================================================================
 * Figma writes the window as "٥–٧ أيام عمل" and "١–٣ أيام عمل" (551:9689 /
 * 551:9706). It is tempting to store that whole sentence as the method title
 * and be done — and it would be wrong twice over.
 *
 * It is CONTENT, so it belongs to the merchant (CLAUDE.md §7): the range moves
 * with the courier contract, and changing it must not require a developer. And
 * it is DATA, not a phrase: the storefront renders it inside a specific type
 * ramp, the English store needs "5–7 working days" rather than a transliterated
 * Arabic string, and the order confirmation may want to render the same range
 * as a date. A baked sentence can do none of that.
 *
 * So the merchant configures two integers per method and the presentation layer
 * decides how to say it. This class is the one place that knows where those
 * integers live, and it is consumed by BOTH the carrier and the plugin that
 * publishes them to the checkout — so the config path is written down once.
 */
class DeliveryWindow
{
    /**
     * Every field this module owns lives under the carrier's own config group,
     * which is what makes it appear in Stores > Configuration > Delivery
     * Methods alongside the rest of the carrier's settings.
     */
    private const PATH = 'carriers/' . Carrier\Spartrak::CODE . '/%s_days_%s';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * The configured window for one method, or null when it is not set.
     *
     * Null rather than a guessed default: a merchant who has not stated a
     * delivery window has not made a promise, and inventing one on their behalf
     * is the kind of thing that ends up in a dispute. The storefront simply
     * omits the line when this returns null.
     *
     * @param string $methodCode 'standard' or 'express'
     * @param int|string|null $store
     * @return array{min: int, max: int}|null
     */
    public function get(string $methodCode, $store = null): ?array
    {
        $min = $this->read($methodCode, 'min', $store);
        $max = $this->read($methodCode, 'max', $store);

        if ($min === null || $max === null) {
            return null;
        }

        // A merchant who types 7 and 5 means the same thing as 5 and 7; the
        // storefront should not have to render a backwards range to punish a
        // typo.
        return $min <= $max
            ? ['min' => $min, 'max' => $max]
            : ['min' => $max, 'max' => $min];
    }

    /**
     * @param int|string|null $store
     */
    private function read(string $methodCode, string $bound, $store): ?int
    {
        $value = $this->scopeConfig->getValue(
            sprintf(self::PATH, $methodCode, $bound),
            ScopeInterface::SCOPE_STORE,
            $store
        );

        if ($value === null || $value === '') {
            return null;
        }

        $days = (int) $value;

        // Zero or negative days is not a window, it is an unset field with
        // something odd in it.
        return $days > 0 ? $days : null;
    }
}
