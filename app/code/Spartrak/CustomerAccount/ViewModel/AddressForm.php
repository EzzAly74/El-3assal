<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAccount\ViewModel;

use Magento\Customer\Api\Data\AddressInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Spartrak\CustomerAddress\Model\GovernorateOptions;

/**
 * The six fields Figma's address form draws — 557:5173.
 *
 * ===========================================================================
 * WHY THE ACCOUNT FORM IS A TEMPLATE AND THE CHECKOUT FORM IS A PLUGIN
 * ===========================================================================
 * They are not the same kind of form, and the difference is core's, not ours.
 *
 * The CHECKOUT address form is a UI component tree assembled from attribute
 * metadata, so it is reshaped by a LayoutProcessor plugin
 * (Spartrak\Checkout\Plugin\Checkout\AddressFormLayout) — every field keeps its
 * own component, validation and data source, and that plugin's docblock records
 * why replacing it outright would have been a mistake.
 *
 * The ACCOUNT address form is not that. `Magento_Customer::address/edit.phtml`
 * is a fixed template with roughly fifteen hard-coded fields; there is no
 * metadata tree to reshape, so a template is the only seam there is. Figma
 * draws six of those fields, so this supplies what those six need and the
 * template renders them.
 *
 * WHAT IS SHARED ANYWAY: the governorate list (GovernorateOptions), the
 * `additional_phone` attribute, and the observer that fills `city` from the
 * chosen governorate on `customer_address_save_before` — which
 * Spartrak_CustomerAddress's events.xml already anticipated this exact form.
 * So the two forms agree on data even though they are built differently.
 *
 * ===========================================================================
 * THE HIDDEN FIELDS ARE SUBMITTED, NOT DROPPED
 * ===========================================================================
 * Same rule the checkout plugin states: `country_id` comes from configuration,
 * `city` is filled from the governorate by an observer, and `postcode` is
 * optional for Egypt. The template posts country_id and leaves the other two to
 * the observer — omitting country_id would produce an address Magento's own
 * validator rejects, from a form with no field to fix it in.
 */
class AddressForm implements ArgumentInterface
{
    public function __construct(
        private readonly GovernorateOptions $governorateOptions
    ) {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function getGovernorateOptions(): array
    {
        return $this->governorateOptions->toOptionArray();
    }

    /**
     * The country every address on this storefront gets, without being asked.
     */
    public function getCountryId(): string
    {
        return $this->governorateOptions->getCountryId();
    }

    /**
     * Figma draws ONE textarea for the whole address ("تفاصيل العنوان"), while
     * Magento stores street as an ordered list of lines.
     *
     * Joining with newlines rather than showing only line 1: an address saved
     * before this form existed — or by an admin, or by the REST API — can have
     * two or three lines, and printing just the first would silently discard
     * the rest the moment the shopper pressed save.
     */
    public function getStreetText(?AddressInterface $address): string
    {
        if ($address === null) {
            return '';
        }

        $street = $address->getStreet();

        return is_array($street) ? trim(implode("\n", $street)) : (string) $street;
    }

    /**
     * `additional_phone` is a custom attribute (Spartrak_CustomerAddress's
     * AddAdditionalPhoneAttribute patch), so it is not on AddressInterface and
     * has to be read through the custom-attribute bag.
     */
    public function getAdditionalPhone(?AddressInterface $address): string
    {
        if ($address === null) {
            return '';
        }

        $attribute = $address->getCustomAttribute('additional_phone');

        return $attribute === null ? '' : (string) $attribute->getValue();
    }

    /**
     * Figma's form has ONE "العنوان الافتراضي" toggle where Magento keeps two
     * defaults.
     *
     * Checked when EITHER is set, and the template posts both — the same rule
     * Controller\Address\SetDefault applies for the same reason: this storefront
     * collects one kind of address, so a shopper who marks one as default must
     * not end up with an invoice address pointing somewhere else, on a store
     * with no screen that could show them why.
     */
    public function isDefault(?AddressInterface $address): bool
    {
        if ($address === null) {
            return false;
        }

        return (bool) $address->isDefaultShipping() || (bool) $address->isDefaultBilling();
    }
}
