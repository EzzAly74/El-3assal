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

    public static function isPickupCarrier(?string $carrierCode): bool
    {
        return self::fromCarrierCode($carrierCode) !== null;
    }

    public static function isValid(?string $type): bool
    {
        return $type === self::BRANCH || $type === self::DEPOT;
    }
}
