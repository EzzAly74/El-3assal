<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Checkout\Plugin\Checkout;

use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Model\ShippingInformationManagement;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\AddressInterfaceFactory;

/**
 * The billing address IS the shipping address, because the design never asks
 * for another one.
 *
 * ===========================================================================
 * THE BUG THIS FIXES
 * ===========================================================================
 * Placing an order failed with
 *
 *     Please check the billing address information. "regionId" is required.
 *
 * raised by Magento\Quote\Model\QuoteValidator::validateBeforeSubmit() against
 * the BILLING address, while the shipping address the shopper actually chose
 * was complete.
 *
 * The chain: Setup\Patch\Data\AddEgyptGovernorates calls Magento's own
 * DataInstaller::addCountryRegions(), which adds EG to
 * general/region/state_required - so every Egyptian address must carry a
 * region_id. Magento_Checkout/js/checkout-data-resolver::applyBillingAddress()
 * then picks the customer's default_billing address in preference to the
 * shipping one, and an address saved before those governorates existed has no
 * region_id. Shipping passed; billing did not.
 *
 * ===========================================================================
 * WHY COPYING IS THE RIGHT ANSWER AND NOT A WORKAROUND
 * ===========================================================================
 * Figma's payment step (551:11313) draws four payment rows and an order note.
 * It draws NO billing address form, no address selector, and no "same as
 * shipping" checkbox - inspected, not assumed. Spartrak_Payment's
 * method-row.html is faithful to that: unlike core's per-method templates it
 * renders no `getBillingAddressFormName()` region, so
 * Magento_Checkout/js/view/billing-address never appears and its
 * useShippingAddress() - the thing that normally copies shipping onto billing -
 * is never invoked.
 *
 * A checkout that offers no way to enter a billing address cannot meaningfully
 * have one that differs from the shipping address. Making that explicit on the
 * server is the honest expression of the design, and it means the order no
 * longer depends on the state of a stale address the shopper cannot see or
 * edit. Repairing only the missing region was rejected: it would have produced
 * an address with one address's street and another's governorate.
 *
 * ===========================================================================
 * POSITION AND ORDER
 * ===========================================================================
 * `before`, for the same reason Spartrak\PickupLocation\Plugin\Checkout\
 * ApplyPickupLocation is: core validates the billing address inside this call
 * (addressValidator->validateForCart), so the value has to be in place before
 * it runs.
 *
 * It must also run AFTER ApplyPickupLocation, which SYNTHESISES the shipping
 * address from the chosen branch or depot. Copying first would copy the
 * shopper's own address onto billing and leave the pickup location only on
 * shipping. etc/di.xml gives this a higher sortOrder for that reason - the one
 * ordering constraint in the file.
 *
 * ===========================================================================
 * A NEW INSTANCE, NOT THE SAME OBJECT
 * ===========================================================================
 * Core calls setCustomerAddressId() on the billing address and then hands both
 * to $quote->setBillingAddress() / setShippingAddress(). Passing one object
 * twice would give the quote a single model for two addresses, and whichever
 * address_type was written last would win. So the fields are copied onto a
 * fresh address.
 *
 * `save_in_address_book` is cleared on the copy: the shipping half already
 * carries whatever the shopper asked for, and letting the billing half ask
 * again would write the same address to the book twice.
 */
class UseShippingAddressForBilling
{
    public function __construct(
        private readonly AddressInterfaceFactory $addressFactory
    ) {
    }

    /**
     * @param ShippingInformationManagement $subject
     * @param int|string $cartId
     * @param ShippingInformationInterface $addressInformation
     * @return array{0: int|string, 1: ShippingInformationInterface}
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeSaveAddressInformation(
        ShippingInformationManagement $subject,
        $cartId,
        ShippingInformationInterface $addressInformation
    ): array {
        $shipping = $addressInformation->getShippingAddress();

        if ($shipping === null) {
            // Nothing to copy from. Core raises its own error for this.
            return [$cartId, $addressInformation];
        }

        $addressInformation->setBillingAddress($this->copyOf($shipping));

        return [$cartId, $addressInformation];
    }

    /**
     * Every field Magento\Quote\Api\Data\AddressInterface exposes, onto a new
     * address.
     *
     * Written out rather than passed through DataObjectHelper::populateWithArray
     * so that `id`, `quote_id` and `address_type` cannot travel with it - those
     * identify the SHIPPING row, and carrying them over is how a copy silently
     * becomes an overwrite.
     */
    private function copyOf(AddressInterface $shipping): AddressInterface
    {
        $billing = $this->addressFactory->create();

        $billing->setCustomerId($shipping->getCustomerId())
            ->setCustomerAddressId($shipping->getCustomerAddressId())
            ->setEmail($shipping->getEmail())
            ->setPrefix($shipping->getPrefix())
            ->setFirstname($shipping->getFirstname())
            ->setMiddlename($shipping->getMiddlename())
            ->setLastname($shipping->getLastname())
            ->setSuffix($shipping->getSuffix())
            ->setCompany($shipping->getCompany())
            ->setStreet($shipping->getStreet())
            ->setCity($shipping->getCity())
            ->setRegion($shipping->getRegion())
            ->setRegionId($shipping->getRegionId())
            ->setRegionCode($shipping->getRegionCode())
            ->setPostcode($shipping->getPostcode())
            ->setCountryId($shipping->getCountryId())
            ->setTelephone($shipping->getTelephone())
            ->setFax($shipping->getFax())
            ->setVatId($shipping->getVatId())
            // `same_as_billing` is a flag the SHIPPING address carries about
            // billing, so it is deliberately not set here - on the billing row
            // it would read as a statement about itself.
            ->setSaveInAddressBook(false);

        /**
         * Carried across because Spartrak_CustomerAddress adds
         * `additional_phone` here - see its
         * Plugin\AllowAdditionalPhoneOnQuoteAddress. Any other module's
         * extension attribute rides along for free rather than being dropped.
         */
        $extension = $shipping->getExtensionAttributes();

        if ($extension !== null) {
            $billing->setExtensionAttributes($extension);
        }

        return $billing;
    }
}
