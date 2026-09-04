<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAddress\Model;

use Magento\Directory\Model\RegionFactory;

/**
 * Fills an address's `city` from the chosen governorate.
 *
 * ===========================================================================
 * WHY THIS EXISTS
 * ===========================================================================
 * Figma's address form (557:5173) collects six fields: first name, last name,
 * governorate, address details, mobile, and an extra number. It collects no
 * city and no postcode.
 *
 * Magento requires a city. Not "shows a field for" - REQUIRES: Magento\Customer
 * \Model\Address\Validator\General::checkRequiredFields() rejects an address
 * with an empty city outright, server-side, so simply hiding the field would
 * produce a form that looks right and cannot be submitted.
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
 * IT NEVER OVERWRITES A CITY SOMEBODY MEANT
 * ===========================================================================
 * A city typed in the admin, imported, or supplied by an integration that does
 * collect one is left alone. This fills a gap; it does not have an opinion.
 *
 * ===========================================================================
 * BUT A DERIVED CITY IS KEPT IN STEP WITH THE GOVERNORATE
 * ===========================================================================
 * The first version of this class stopped at "city is not empty, leave it",
 * and that produced a wrong address on the very ordinary path of EDITING one.
 *
 * Create an address in المنوفية and the city is filled with المنوفية. Edit it,
 * change the governorate to القاهرة, save: the region becomes القاهرة, the city
 * is not empty so it is skipped, and the row now says المنوفية AND القاهرة at
 * once. The checkout's address card prints both - `spartrakAddressLine()`
 * renders street, city, region, country as Figma 549:26288 writes it - so the
 * shopper reads two different governorates on one line, and the warehouse gets
 * a delivery address that contradicts itself.
 *
 * HOW A DERIVED CITY IS RECOGNISED, AND WHY THAT CHANGED (2026-09-03)
 * -------------------------------------------------------------------
 * It used to be recognised by comparing the city against the governorate the
 * address carried BEFORE the save, read from `getOrigData('region_id')`.
 * That works on a Magento model and only on a Magento model.
 *
 * The city now has to be filled one step earlier in the stack - on the
 * AddressInterface DATA OBJECT, before the repository validates it (see
 * Plugin\FillCityOnAddressRepositorySave for why) - and a data object has no
 * `getOrigData`. Under the old rule the guard silently returned '', the
 * "keep it in step" case stopped firing on the repository path, and the
 * two-governorates-on-one-line bug came back through the front door.
 *
 * So the test is no longer "does the city equal the PREVIOUS governorate" but
 * "does the city equal ANY governorate this store offers". Both answers are
 * yes for a value this class wrote, and the new one needs no history:
 *
 *   city == '' .................... nothing to protect, derive it
 *   city is a governorate name .... this class wrote it, re-derive it
 *   city is anything else ......... somebody chose it, leave it alone
 *
 * The set is one memoised region query (GovernorateOptions), so recognising a
 * derived city costs no more than resolving the new one did.
 *
 * The trade is explicit: an address whose city was deliberately typed as the
 * NAME of some other governorate - city "القاهرة" against region "الجيزة" -
 * is now re-derived to الجيزة instead of being preserved. On a storefront
 * whose only address form does not collect a city at all, that combination
 * can only arrive from the admin or an import, and it is far likelier to be
 * the stale half of an edit than a real address. The previous rule got this
 * one case right and the whole edit path wrong.
 */
class GovernorateCity
{
    /**
     * Region names already looked up during this request, keyed by region id.
     *
     * An address save resolves the same one to two ids, and a bulk import
     * resolves the same handful of governorates for every row.
     *
     * @var array<int, string>
     */
    private array $names = [];

    public function __construct(
        private readonly RegionFactory $regionFactory,
        private readonly GovernorateOptions $governorateOptions
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

        if (!array_key_exists($regionId, $this->names)) {
            $region = $this->regionFactory->create()->load($regionId);

            $this->names[$regionId] = $region->getId() ? (string) $region->getName() : '';
        }

        return $this->names[$regionId];
    }

    /**
     * @param object $address anything with getCity/setCity and getRegionId -
     *                        a Magento\Customer\Model\Address, a
     *                        Magento\Quote\Model\Quote\Address, or the
     *                        AddressInterface data object the repository takes
     */
    public function fill(object $address): void
    {
        if (!method_exists($address, 'getCity') || !method_exists($address, 'setCity')) {
            return;
        }

        if (!method_exists($address, 'getRegionId')) {
            return;
        }

        $name = $this->resolveName($address->getRegionId());

        if ($name === '') {
            // No governorate to derive from. Whatever the city says, it is the
            // only thing this address has, so it stays.
            return;
        }

        $city = trim((string) $address->getCity());

        if ($city === '' || $this->isGovernorateName($city, $address)) {
            $address->setCity($name);
        }
    }

    /**
     * Does this city string name one of the governorates the store offers?
     *
     * If it does, this class wrote it - the address form has no city field for
     * a shopper to have typed it in - so it is a derived value that should be
     * re-derived rather than preserved. See the class header.
     */
    private function isGovernorateName(string $city, object $address): bool
    {
        $country = null;

        if (method_exists($address, 'getCountryId')) {
            $country = (string) $address->getCountryId();
        }

        foreach ($this->governorateOptions->toOptionArray($country) as $option) {
            if (trim((string) $option['label']) === $city) {
                return true;
            }
        }

        return false;
    }
}
