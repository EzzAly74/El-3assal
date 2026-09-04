<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model;

use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * The four `spartrak_pickup_*` columns on an order's shipping address, read.
 *
 * ===========================================================================
 * WHY THIS IS SEPARATE FROM ViewModel\OrderPickup
 * ===========================================================================
 * It is the raw snapshot and nothing else — no channel logic, no labels, no
 * interpretation. It was split out of that view model so FulfilmentChannel can
 * consult the snapshot while OrderPickup consults FulfilmentChannel, without
 * the two depending on each other.
 *
 * The split also makes the layering honest: `shipping_method` (core's) answers
 * WHICH CHANNEL, and these columns answer WHICH PLACE. Only the second is this
 * module's to lose, and separating them is what let the first become the
 * primary source (see FulfilmentChannel's header for the bug that caused).
 *
 * Reads the ORDER's snapshot, never the live branch or depot record. An order
 * placed against a depot that has since been renamed must still print what the
 * shopper actually chose — see db_schema.xml.
 */
class OrderPickupSnapshot
{
    /**
     * 'branch' | 'depot' | null. Null means the snapshot is absent, which on a
     * pickup order is a fault and on a delivery is correct.
     */
    public function getType(?OrderInterface $order): ?string
    {
        $value = $this->field($order, 'spartrak_pickup_type');

        return PickupType::isValid($value) ? $value : null;
    }

    /**
     * The location's id at the time of the order. Kept alongside the name and
     * address so an operations report can still join back to the live record.
     */
    public function getLocationId(?OrderInterface $order): ?int
    {
        $value = $this->field($order, 'spartrak_pickup_id');

        return $value !== null && (int) $value > 0 ? (int) $value : null;
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
     * Reads from the shipping address unconditionally — it does NOT gate on the
     * type being valid first.
     *
     * That gate used to live here, and it was the whole bug: every getter
     * returned null the moment `spartrak_pickup_type` was empty, so a depot
     * order with a half-landed snapshot reported no name, no address and no
     * type, and every consumer concluded "home delivery". Now each column
     * speaks for itself and the caller decides what a missing one means.
     */
    private function field(?OrderInterface $order, string $key): ?string
    {
        $address = $this->address($order);

        if ($address === null) {
            return null;
        }

        $value = trim((string) $address->getData($key));

        return $value !== '' ? $value : null;
    }

    private function address(?OrderInterface $order): ?OrderAddressInterface
    {
        return $order?->getShippingAddress();
    }
}
