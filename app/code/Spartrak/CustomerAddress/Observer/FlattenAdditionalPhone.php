<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAddress\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote\Address;

/**
 * Copies the additional phone out of the quote address's custom attributes and
 * onto its real column, immediately before the row is written.
 *
 * ===========================================================================
 * WHY THIS STEP IS NECESSARY AT ALL
 * ===========================================================================
 * quote_address is a FLAT table, not an EAV entity. When the checkout posts
 * `custom_attributes: {additional_phone: "..."}`,
 * AbstractExtensibleModel::setCustomAttribute() stores an AttributeValue object
 * under _data['custom_attributes'] and nowhere else - it never touches
 * _data['additional_phone']. The resource model then writes only real columns,
 * so the value is dropped without a word.
 *
 * Verified by reading the framework rather than assumed:
 * vendor/magento/framework/Model/AbstractExtensibleModel.php::setCustomAttribute.
 *
 * The customer address does NOT need this - it is a real EAV entity, and
 * AddressRepository already flattens custom attributes onto the model on save.
 *
 * ===========================================================================
 * WHY AN OBSERVER AND NOT A PLUGIN ON THE CHECKOUT SERVICE
 * ===========================================================================
 * sales_quote_address_save_before fires from AbstractModel::beforeSave, so it
 * covers EVERY path that persists a quote address: the shipping-information
 * endpoint, the billing-address endpoint, admin order create, the REST API and
 * any future one. Plugging ShippingInformationManagement would have covered
 * exactly one of those and silently missed the rest - the field would work at
 * checkout and vanish on an admin-created order.
 */
class FlattenAdditionalPhone implements ObserverInterface
{
    private const ATTRIBUTE_CODE = 'additional_phone';

    public function execute(Observer $observer): void
    {
        $address = $observer->getEvent()->getDataObject();

        if (!$address instanceof Address) {
            return;
        }

        $attribute = $address->getCustomAttribute(self::ATTRIBUTE_CODE);

        if ($attribute === null) {
            // Nothing was posted. Deliberately does NOT blank the column: the
            // address may have been loaded from the address book with a value
            // already on it, and a later save that happens to carry no custom
            // attributes must not erase it.
            return;
        }

        $value = $attribute->getValue();
        $value = is_string($value) ? trim($value) : '';

        $address->setData(self::ATTRIBUTE_CODE, $value !== '' ? $value : null);
    }
}
