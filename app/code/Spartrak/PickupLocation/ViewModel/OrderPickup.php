<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Spartrak\PickupLocation\Model\PickupType;

/**
 * "Where is this order being collected from?" - asked by the success page and
 * by the admin order view.
 *
 * Both surfaces need the same three facts and neither should be reaching into
 * a column name itself. A view model rather than a block because it carries no
 * markup and both consumers already have their own block hierarchy (CLAUDE.md
 * section 8: consume view models, do not duplicate logic).
 *
 * Reads the ORDER's snapshot, never the live branch record. An order placed
 * against a branch that has since been renamed must still print what the
 * shopper chose - see db_schema.xml.
 */
class OrderPickup implements ArgumentInterface
{
    public function isPickup(?OrderInterface $order): bool
    {
        return $this->address($order) !== null;
    }

    /**
     * 'branch' or 'depot', or null when the order was delivered.
     */
    public function getType(?OrderInterface $order): ?string
    {
        $address = $this->address($order);

        return $address !== null ? (string) $address->getData('spartrak_pickup_type') : null;
    }

    public function isBranch(?OrderInterface $order): bool
    {
        return $this->getType($order) === PickupType::BRANCH;
    }

    public function getName(?OrderInterface $order): ?string
    {
        return $this->field($order, 'spartrak_pickup_name');
    }

    public function getAddress(?OrderInterface $order): ?string
    {
        return $this->field($order, 'spartrak_pickup_address');
    }

    /**
     * The heading a shopper should read above the location.
     *
     * Two labels rather than one because "collect from our branch" and "collect
     * from a coach depot" are genuinely different promises, and the success
     * page is where a shopper decides whether they understood what they bought.
     */
    public function getLabel(?OrderInterface $order): ?string
    {
        return match ($this->getType($order)) {
            PickupType::BRANCH => (string) __('Collect from branch'),
            PickupType::DEPOT => (string) __('Collect from depot'),
            default => null,
        };
    }

    private function field(?OrderInterface $order, string $key): ?string
    {
        $address = $this->address($order);

        if ($address === null) {
            return null;
        }

        $value = trim((string) $address->getData($key));

        return $value !== '' ? $value : null;
    }

    /**
     * The shipping address, but only when it actually carries a pickup choice.
     *
     * Returning null for a delivered order is what lets every method above be
     * called unguarded from a template.
     */
    private function address(?OrderInterface $order): ?OrderAddressInterface
    {
        if ($order === null) {
            return null;
        }

        $address = $order->getShippingAddress();

        if ($address === null) {
            return null;
        }

        $type = $address->getData('spartrak_pickup_type');

        return PickupType::isValid(is_string($type) ? $type : null) ? $address : null;
    }
}
