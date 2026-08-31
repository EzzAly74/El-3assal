<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAddress\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Spartrak\CustomerAddress\Model\GovernorateCity;

/**
 * Fills `city` from the chosen governorate on every address save.
 *
 * ===========================================================================
 * WHY AN OBSERVER AND NOT A SERVICE PLUGIN
 * ===========================================================================
 * An address is saved through more than one door: the checkout's REST call, the
 * My Account address form, the admin, an order edit, a data patch, an import.
 * Plugging the checkout's service would cover the door this design happens to
 * use today and leave the others producing addresses with no city - which
 * Magento would then reject at the validator, from a form that has no city
 * field to fix it in.
 *
 * `*_save_before` fires on the model itself, so every one of those doors is
 * covered by one small class. That is the same reasoning as the sibling
 * observer FlattenAdditionalPhone, and it is deliberate that the two are not
 * merged: they answer different questions and either could be removed without
 * the other.
 *
 * ===========================================================================
 * BEFORE, NOT AFTER
 * ===========================================================================
 * The value has to be present when the row is written, and validation runs on
 * the way in. An after-save observer would set a field on an object that had
 * already been persisted without it.
 */
class FillCityFromGovernorate implements ObserverInterface
{
    public function __construct(
        private readonly GovernorateCity $governorateCity
    ) {
    }

    public function execute(Observer $observer): void
    {
        $address = $observer->getEvent()->getDataObject();

        if (!is_object($address)) {
            // `customer_address_save_before` publishes the address under
            // `data_object`; the quote-address event uses `quote_address`.
            $address = $observer->getEvent()->getData('quote_address');
        }

        if (is_object($address)) {
            $this->governorateCity->fill($address);
        }
    }
}
