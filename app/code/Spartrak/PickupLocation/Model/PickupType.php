<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model;

use Spartrak\PickupLocation\Model\Carrier\BranchPickup;
use Spartrak\PickupLocation\Model\Carrier\DepotPickup;

/**
 * The two kinds of pickup, and the mapping between a carrier code and a kind.
 *
 * A tiny class, but it exists so the string 'branch' is written down ONCE.
 * Without it, the same literal would appear in the plugin that saves the
 * choice, the config provider that publishes the list, the view model that
 * reads it back on the success page, and the admin block - four places to
 * update, and three of them silently wrong if one is missed.
 */
final class PickupType
{
    public const BRANCH = 'branch';
    public const DEPOT = 'depot';

    /**
     * Carrier code -> pickup type.
     *
     * A carrier that is not in this map is not a pickup carrier, which is how
     * every consumer decides whether the pickup columns apply at all.
     */
    private const BY_CARRIER = [
        BranchPickup::CODE => self::BRANCH,
        DepotPickup::CODE => self::DEPOT,
    ];

    public static function fromCarrierCode(?string $carrierCode): ?string
    {
        if ($carrierCode === null) {
            return null;
        }

        return self::BY_CARRIER[$carrierCode] ?? null;
    }

    /**
     * The pickup kind of a stored `shipping_method`, e.g. `spartrak_depot_depot`.
     *
     * ===========================================================================
     * WHY THIS EXISTS AND WHY IT DOES NOT USE Order::getShippingMethod(true)
     * ===========================================================================
     * `sales_order.shipping_method` is written by core on every non-virtual
     * order and carried across the quote/order boundary by core's own fieldset.
     * That makes it the most reliable statement of HOW an order is being
     * fulfilled that exists on the record — more reliable than this module's own
     * `spartrak_pickup_type` snapshot, which is one extra copy that can fail to
     * land (and did: an order placed against the depot carrier was showing no
     * pickup section at all in the admin, because the snapshot was empty and
     * every consumer keyed off it).
     *
     * Magento offers `Order::getShippingMethod(true)` to split it, and it CANNOT
     * be used here:
     *
     *     list($carrierCode, $method) = explode('_', $shippingMethod, 2);
     *
     * With a limit of 2, `spartrak_depot_depot` splits into `spartrak` and
     * `depot_depot`. Core's splitter is only correct for carrier codes with no
     * underscore in them, and both of this module's carriers have one. It would
     * return `spartrak`, match nothing, and report every pickup order as a home
     * delivery — which looks like working code.
     *
     * So the test is a PREFIX match against the carrier codes this module owns.
     * That is exact: a `shipping_method` begins with its carrier code followed
     * by an underscore, by construction (`$carrier . '_' . $method` in
     * Magento\Quote\Model\Quote\Address\Rate::setCode and everywhere else that
     * builds one).
     */
    public static function fromShippingMethod(?string $shippingMethod): ?string
    {
        $method = trim((string) $shippingMethod);

        if ($method === '') {
            return null;
        }

        foreach (self::BY_CARRIER as $carrierCode => $type) {
            if (str_starts_with($method, $carrierCode . '_')) {
                return $type;
            }
        }

        return null;
    }

    public static function isPickupCarrier(?string $carrierCode): bool
    {
        return self::fromCarrierCode($carrierCode) !== null;
    }

    public static function isValid(?string $type): bool
    {
        return $type === self::BRANCH || $type === self::DEPOT;
    }
}
