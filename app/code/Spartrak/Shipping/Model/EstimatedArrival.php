<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Shipping\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Spartrak\Shipping\Model\Carrier\Spartrak as SpartrakCarrier;

/**
 * "مقدر الوصول 30 يوليو 2026" — the estimated arrival date the account section
 * prints on an order card (562:17894), on the order page's header (562:18968)
 * and in its details panel (562:19516).
 *
 * ===========================================================================
 * IT IS DERIVED, NOT STORED
 * ===========================================================================
 * There is no promised-delivery-date column on an order, and adding one would
 * mean a value frozen at checkout that no later change could correct. The date
 * a shopper should read is a function of two things the order already carries:
 * when it was placed, and which shipping method it was placed with.
 *
 *     arrival = order placed at + the method's MAXIMUM window, in working days
 *
 * The maximum rather than the minimum, deliberately. Figma prints one date, not
 * a range, and the one date a merchant can safely put in front of a shopper is
 * the far end of their own promise — under-promise, arrive early. Quoting the
 * near end would make the majority of on-time deliveries look late.
 *
 * ===========================================================================
 * WORKING DAYS, AND WHERE THE WEEKEND COMES FROM
 * ===========================================================================
 * The merchant configures the window in WORKING days — DeliveryWindow's own
 * docblock and the Figma string "٥–٧ أيام عمل" both say so — which is useless
 * without knowing which days are not working days.
 *
 * That setting already exists on the platform: `general/locale/weekend`, edited
 * at Stores > Configuration > General > Locale Options > Weekend, and already
 * consumed by Magento's own calendar widget. Reading it here rather than adding
 * a Spartrak weekend setting means the storefront, the admin calendar and any
 * future working-day rule all agree, and the merchant has one field to change
 * rather than two that can disagree.
 *
 * Core's default for that field is `0,6` (Sunday, Saturday), which is a US
 * week. Spartrak_Shipping's config.xml overrides it to the Egyptian one; see
 * that file.
 *
 * ===========================================================================
 * NULL IS A REAL ANSWER
 * ===========================================================================
 * Returned for a pickup order, for a third-party carrier, and for a method the
 * merchant has not given a window to. A shopper collecting from a coach depot
 * has not been promised a delivery date and must not be shown one; the
 * templates omit the line entirely rather than print a guess. This mirrors
 * DeliveryWindow::get(), which returns null for the same reason.
 */
class EstimatedArrival
{
    /**
     * Magento's own path, not one of ours. See the class docblock.
     */
    private const XML_PATH_WEEKEND = 'general/locale/weekend';

    /**
     * A stop, so a misconfigured weekend (every day marked non-working) cannot
     * spin the loop below forever. Seven consecutive non-working days is
     * already impossible for any real week, so reaching this means the setting
     * is broken and no date should be printed at all.
     */
    private const MAX_SKIPPED_DAYS = 7;

    public function __construct(
        private readonly DeliveryWindow $deliveryWindow,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * The date this order should arrive, in the store's own timezone, or null
     * when no promise was made.
     */
    public function forOrder(OrderInterface $order): ?\DateTimeImmutable
    {
        $window = $this->windowFor($order);

        if ($window === null) {
            return null;
        }

        $placedAt = $this->placedAt($order);

        if ($placedAt === null) {
            return null;
        }

        return $this->addWorkingDays($placedAt, $window['max'], (int) $order->getStoreId());
    }

    /**
     * The configured window for the method this order was actually placed with.
     *
     * `getShippingMethod(true)` rather than splitting the stored
     * "carrier_method" string: the pickup carriers are themselves called
     * `spartrak_branch` and `spartrak_depot`, so a naive explode('_') on the
     * first underscore reads their carrier as "spartrak" and would go looking
     * for a delivery window that must never exist for a collection order.
     *
     * @return array{min: int, max: int}|null
     */
    private function windowFor(OrderInterface $order): ?array
    {
        if (!$order instanceof Order) {
            return null;
        }

        $method = $order->getShippingMethod(true);

        if ($method === null || (string) $method->getData('carrier_code') !== SpartrakCarrier::CODE) {
            return null;
        }

        $methodCode = (string) $method->getData('method');

        return $methodCode !== ''
            ? $this->deliveryWindow->get($methodCode, $order->getStoreId())
            : null;
    }

    /**
     * When the order was placed, as a date in the STORE's timezone.
     *
     * created_at is stored in UTC. An order placed at 01:30 Cairo time is
     * 23:30 UTC the previous day, so counting from the raw value would quote
     * an arrival one day early for every order placed after midnight — the
     * exact class of off-by-one that only shows up in production.
     */
    private function placedAt(OrderInterface $order): ?\DateTimeImmutable
    {
        $createdAt = $order->getCreatedAt();

        if (!$createdAt) {
            return null;
        }

        try {
            $local = $this->timezone->date(new \DateTime($createdAt, new \DateTimeZone('UTC')));
        } catch (\Exception) {
            return null;
        }

        return \DateTimeImmutable::createFromMutable($local)->setTime(0, 0);
    }

    /**
     * Advance by N working days, skipping the store's configured weekend.
     */
    private function addWorkingDays(\DateTimeImmutable $from, int $days, int $storeId): ?\DateTimeImmutable
    {
        $weekend = $this->weekendDays($storeId);
        $date = $from;

        for ($added = 0; $added < $days; $added++) {
            $skipped = 0;

            do {
                $date = $date->modify('+1 day');

                if (++$skipped > self::MAX_SKIPPED_DAYS) {
                    return null;
                }
            } while (in_array((int) $date->format('w'), $weekend, true));
        }

        return $date;
    }

    /**
     * The store's non-working days as PHP `w` numbers (0 = Sunday).
     *
     * Magento stores this field in exactly that numbering — see
     * Magento\Config\Model\Config\Source\Locale\Weekdays, whose option values
     * are 0..6 with Sunday first — so no translation is needed between the
     * config value and `date('w')`.
     *
     * @return int[]
     */
    private function weekendDays(int $storeId): array
    {
        $configured = (string) $this->scopeConfig->getValue(
            self::XML_PATH_WEEKEND,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (trim($configured) === '') {
            return [];
        }

        $days = [];

        foreach (explode(',', $configured) as $day) {
            $day = trim($day);

            if ($day !== '' && is_numeric($day)) {
                $days[] = (int) $day;
            }
        }

        return $days;
    }
}
