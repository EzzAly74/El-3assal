<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Plugin\Checkout;

use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Model\ShippingInformationManagement;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Store\Model\ScopeInterface;
use Spartrak\PickupLocation\Model\LocationCatalog;
use Spartrak\PickupLocation\Model\PickupType;

/**
 * Turns "I will collect it from Branch 3" into a real shipping address.
 *
 * ===========================================================================
 * WHY THE ADDRESS HAS TO BE SYNTHESISED
 * ===========================================================================
 * Figma's branch frame (554:13119) and depot frame (554:13750) replace the
 * address list with a location list. There is no address form, no address
 * card, and nothing for a shopper to pick - inspected, not assumed: the branch
 * frame's "Shipping address container" (554:13280) contains two "Address item"
 * rows whose content is a branch NAME and a branch ADDRESS, and no form fields
 * anywhere in the subtree.
 *
 * But an order still needs somewhere to go. Magento validates the shipping
 * address before it will accept a shipping method, the fulfilment team prints
 * it, and the shipment record is addressed with it. So the chosen location IS
 * the shipping address for a pickup order, and this plugin writes it as one.
 * That is the same thing Magento's own in-store-pickup module does, for the
 * same reason.
 *
 * ===========================================================================
 * WHY `before`
 * ===========================================================================
 * Core's saveAddressInformation() validates the address and only then hands it
 * to the quote. A `before` plugin is therefore the ONLY position where the
 * synthesised fields are in place in time to pass that validation - an `after`
 * plugin would run once the address had already been rejected, and an `around`
 * would put this class in the path of a method it has no business intercepting.
 *
 * ===========================================================================
 * WHAT IT DOES NOT DO
 * ===========================================================================
 * It does not touch the customer's own address book. A pickup order leaves no
 * trace on the shopper's saved addresses, because they did not enter one.
 */
class ApplyPickupLocation
{
    /**
     * Where the customer's own contact details are kept when we overwrite the
     * geography. A pickup order still needs a person to hand the parcel to.
     */
    private const PRESERVED_FIELDS = ['firstname', 'lastname', 'telephone', 'email', 'company'];

    public function __construct(
        private readonly LocationCatalog $locationCatalog,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @param ShippingInformationManagement $subject
     * @param int $cartId
     * @param ShippingInformationInterface $addressInformation
     * @return array{0: int, 1: ShippingInformationInterface}
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeSaveAddressInformation(
        ShippingInformationManagement $subject,
        $cartId,
        ShippingInformationInterface $addressInformation
    ): array {
        $address = $addressInformation->getShippingAddress();

        if ($address === null) {
            return [$cartId, $addressInformation];
        }

        $type = PickupType::fromCarrierCode($addressInformation->getShippingCarrierCode());

        if ($type === null) {
            // Delivery, not pickup. Clear any location left behind by an
            // earlier pass through the pickup segment, so an order that ends
            // up being delivered never carries a stale branch id.
            $this->clear($address);

            return [$cartId, $addressInformation];
        }

        $location = $this->resolveLocation($type, $addressInformation);

        $this->apply($address, $type, $location);

        return [$cartId, $addressInformation];
    }

    /**
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    private function resolveLocation(string $type, ShippingInformationInterface $addressInformation): array
    {
        $extension = $addressInformation->getExtensionAttributes();
        $locationId = $extension !== null ? (int) $extension->getSpartrakPickupId() : 0;

        if ($locationId <= 0) {
            throw new LocalizedException(
                __('Please choose a pickup location before continuing.')
            );
        }

        $location = $type === PickupType::BRANCH
            ? $this->locationCatalog->getBranchById($locationId)
            : $this->locationCatalog->getDepotById($locationId);

        if ($location === null) {
            // Disabled or deleted between the page rendering and the shopper
            // submitting. Saying so is better than silently falling back to a
            // different location than the one they picked.
            throw new LocalizedException(
                __('The pickup location you selected is no longer available. Please choose another one.')
            );
        }

        return $location;
    }

    /**
     * @param array<string, mixed> $location
     */
    private function apply(AddressInterface $address, string $type, array $location): void
    {
        $preserved = [];
        foreach (self::PRESERVED_FIELDS as $field) {
            $preserved[$field] = $address->getData($field);
        }

        $address->setStreet([(string) $location['address']]);

        // The governorate is the closest thing a pickup location has to a
        // city, and it is what a courier manifest needs. Falling back to the
        // location's own name keeps the field non-empty for a location whose
        // governorate an admin has not filled in yet - Magento requires a city.
        $city = (string) ($location['region'] ?? '');
        $address->setCity($city !== '' ? $city : (string) $location['name']);

        if (!empty($location['region_id'])) {
            $address->setRegionId((int) $location['region_id']);
            // region_id and the free-text region must agree, or the admin order
            // view prints one and the shipment label the other.
            $address->setRegion($city !== '' ? $city : null);
        }

        $address->setCountryId($this->defaultCountryId());

        // Egypt has no meaningful postcode for a coach depot, and the address
        // is not a postal one in any case. Blanked rather than invented: a
        // fabricated postcode would be printed on a label as though it meant
        // something. Whether it is REQUIRED is the store's own
        // general/country/optional_zip_countries setting to state, not this
        // plugin's to work around.
        $address->setPostcode('');

        foreach ($preserved as $field => $value) {
            $address->setData($field, $value);
        }

        // A pickup address is never one of the customer's saved addresses.
        $address->setCustomerAddressId(null);
        $address->setSaveInAddressBook(0);
        $address->setSameAsBilling(0);

        $address->setData('spartrak_pickup_type', $type);
        $address->setData('spartrak_pickup_id', (int) $location['id']);
        // Snapshotted for the life of the order - see db_schema.xml.
        $address->setData('spartrak_pickup_name', (string) $location['name']);
        $address->setData('spartrak_pickup_address', (string) $location['address']);
    }

    private function clear(AddressInterface $address): void
    {
        $address->setData('spartrak_pickup_type', null);
        $address->setData('spartrak_pickup_id', null);
        $address->setData('spartrak_pickup_name', null);
        $address->setData('spartrak_pickup_address', null);
    }

    /**
     * The store's configured default country, not a literal 'EG'.
     *
     * A pickup network is domestic by nature, but which country that is
     * belongs to the store's configuration, and it is already stated there.
     */
    private function defaultCountryId(): string
    {
        return (string) $this->scopeConfig->getValue(
            DirectoryHelper::XML_PATH_DEFAULT_COUNTRY,
            ScopeInterface::SCOPE_STORE
        );
    }
}
