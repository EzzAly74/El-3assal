<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAddress\Plugin;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Spartrak\CustomerAddress\Model\GovernorateCity;

/**
 * Fills `city` from the governorate BEFORE the address repository validates.
 *
 * ===========================================================================
 * THE BUG THIS FIXES
 * ===========================================================================
 * Saving an address from My Account failed with
 *
 *     "city" is required. Enter and try again.
 *
 * and bounced the shopper to /customer/address/edit/ — from a form that has
 * no city field, so there was nothing they could do about it.
 *
 * The city was always meant to be derived from the chosen governorate, and it
 * was: Observer\FillCityFromGovernorate does exactly that on
 * `customer_address_save_before`. The observer was simply too late.
 *
 * Magento\Customer\Model\ResourceModel\AddressRepository::save() runs, in this
 * order:
 *
 *     $addressModel->updateData($address);      // data object -> model
 *     $errors = $addressModel->validate();      // <-- THROWS HERE
 *     if ($errors !== true) { throw new InputException(...); }
 *     $addressModel->save();                    // fires *_save_before
 *
 * `customer_address_save_before` is dispatched from AbstractModel::beforeSave(),
 * i.e. inside that last line. The validator rejected the address two lines
 * earlier and the observer never ran. Nothing was wrong with the observer, the
 * form, the region select or the configuration — the fill just had to happen
 * upstream of `validate()`.
 *
 * ===========================================================================
 * WHY THE SERVICE CONTRACT IS THE RIGHT PLACE
 * ===========================================================================
 * `AddressRepositoryInterface::save()` is the one door every modern writer
 * goes through: core's Address\FormPost (this form), the REST endpoint
 * `/V1/customers/:id/addresses`, the admin customer form, and any extension
 * that follows Magento's own rules. Filling the data object as it arrives
 * covers all of them at once, and it covers them BEFORE validation because
 * validation lives inside the method being intercepted.
 *
 * A `before` plugin, not `around`: this adds a value and then gets out of the
 * way. `around` would put a closure between every address save on the store
 * and the platform's own, for no reason (Magento's own plugin guidance, and
 * CLAUDE.md §9's "minimal code").
 *
 * ===========================================================================
 * THE OBSERVERS STAY — THEY COVER DIFFERENT DOORS
 * ===========================================================================
 * This does NOT replace Observer\FillCityFromGovernorate, and the duplication
 * is only apparent: all three call sites are two lines that delegate to
 * GovernorateCity, which is the single place the rule is written.
 *
 *   THIS PLUGIN ......... the repository — My Account, REST, admin
 *   customer_address_save_before ... a direct `$addressModel->save()`: a data
 *                         patch, a legacy module, an import script. Still
 *                         reachable, and still needs the city.
 *   sales_quote_address_save_before ... the checkout's quote address, which
 *                         never touches the customer address repository at all.
 *
 * Running twice on the repository path is harmless and deliberate: the second
 * pass sees a city that already equals the governorate and writes the same
 * value again. Removing the model observer to avoid that would trade an
 * idempotent no-op for a real gap.
 */
class FillCityOnAddressRepositorySave
{
    public function __construct(
        private readonly GovernorateCity $governorateCity
    ) {
    }

    /**
     * @return array{0: AddressInterface}
     */
    public function beforeSave(
        AddressRepositoryInterface $subject,
        AddressInterface $address
    ): array {
        $this->governorateCity->fill($address);

        return [$address];
    }
}
