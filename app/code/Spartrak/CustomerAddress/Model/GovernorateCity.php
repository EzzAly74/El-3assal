<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAddress\Model;

use Magento\Directory\Model\RegionFactory;

/**
 * Fills an address's `city` from the governorate the shopper chose.
 *
 * ===========================================================================
 * WHY THIS EXISTS
 * ===========================================================================
 * Figma's address form (557:5173) collects six fields: first name, last name,
 * governorate, address details, mobile, and an extra number. It collects no
 * city and no postcode.
 *
 * Magento requires a city. Not "shows a field for" - REQUIRES: Magento\Customer
 * \Model\Address\Validator\General rejects an address with an empty city
 * outright, server-side, so simply hiding the field would produce a form that
 * looks right and cannot be submitted.
 *
 * ===========================================================================
 * WHY THE GOVERNORATE IS THE RIGHT VALUE
 * ===========================================================================
 * This is not a placeholder to satisfy a validator. In Egypt the governorate
 * (المحافظة) IS the city-level administrative division for addressing purposes
 * - Cairo, Giza and Alexandria are governorates and cities at once - and the
 * street-level detail the design does collect goes in `تفاصيل العنوان`, which
 * maps to `street`. So the address ends up correct, not merely valid.
 *
 * It is also already the rule elsewhere in this codebase: Spartrak_PickupLocation
 * sets city from a location's governorate for exactly the same reason. Two
 * places deriving the same value the same way is consistency; two places
 * deriving it differently would be a bug waiting to be found in a warehouse.
 *
 * ===========================================================================
 * IT NEVER OVERWRITES A REAL CITY
 * ===========================================================================
 * If a city is already set - typed in the admin, imported, or entered through
 * an API by an integration that does collect one - it is left alone. This fills
 * a gap; it does not have an opinion.
 */
class GovernorateCity
{
    public function __construct(
        private readonly RegionFactory $regionFactory
    ) {
    }

    /**
     * The governorate's name for a region id, or '' when it cannot be resolved.
     */
    public function resolveName(int|string|null $regionId): string
    {
        $regionId = (int) $regionId;

        if ($regionId <= 0) {
            return '';
        }

        $region = $this->regionFactory->create()->load($regionId);

        return $region->getId() ? (string) $region->getName() : '';
    }

    /**
     * @param object $address anything with getCity/setCity and getRegionId
     */
    public function fill(object $address): void
    {
        if (!method_exists($address, 'getCity') || !method_exists($address, 'setCity')) {
            return;
        }

        if (trim((string) $address->getCity()) !== '') {
            return;
        }

        if (!method_exists($address, 'getRegionId')) {
            return;
        }

        $name = $this->resolveName($address->getRegionId());

        if ($name !== '') {
            $address->setCity($name);
        }
    }
}
