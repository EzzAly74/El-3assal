<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAccount\ViewModel;

use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Helper\Session\CurrentCustomer;
use Magento\Directory\Model\CountryFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * The "عناويني" card grid — Figma 562:18596.
 *
 * ===========================================================================
 * WHY ALL ADDRESSES IN ONE LIST
 * ===========================================================================
 * Core splits the address book in two: a "Default Addresses" block and a grid
 * of "additional" ones, which is why Block\Address\Grid::getAdditionalAddresses()
 * deliberately excludes the defaults. Figma draws ONE grid with the default
 * card carrying a badge (721:35504) — a better model, because a shopper thinks
 * in "my addresses", not in "my defaults and my others".
 *
 * So this returns the whole book with the default first, and the template
 * badges it. Core's blocks are still used for what they are good at — the
 * add/edit/delete URLs — rather than reimplemented here.
 *
 * ===========================================================================
 * WHY THE ADDRESS LINE IS COMPOSED HERE AND NOT BY MAGENTO'S RENDERER
 * ===========================================================================
 * Magento can render an address from the merchant-configured template
 * (customer/address_templates/html), and that is normally the right thing to
 * use. It is not usable here: it emits one <br>-separated blob containing the
 * NAME, the street, the region and the TELEPHONE together, while Figma gives
 * the name its own 18px heading node and the phone its own row with a phone
 * glyph beside it.
 *
 * Feeding the configured template into the card would mean either accepting a
 * layout the design does not draw, or parsing its output back apart — so the
 * card's three parts are assembled here, from the same fields, and the
 * configured template continues to serve everywhere Magento uses it
 * (transactional email, invoices, the admin).
 *
 * ===========================================================================
 * THERE IS NO ADDRESS NICKNAME, AND NONE IS INVENTED
 * ===========================================================================
 * Figma's three cards are titled "عنوان العمل", "المنزل" and "عنوان المكتب",
 * which read like nicknames. The address form Figma actually draws — the
 * checkout modal at 557:5173, which the address book reuses — collects six
 * fields and none of them is a label, and there is no frame anywhere in the
 * file for an account-specific address form that might collect one.
 *
 * Adding a `address_label` attribute would therefore mean inventing a form
 * field the design does not contain, in the one place CLAUDE.md section 3 is
 * strictest about. The card is titled with the RECIPIENT NAME instead: it is
 * real data, the shopper typed it, it is what goes on the parcel, and it
 * distinguishes one saved address from another. If nicknames are wanted, they
 * need a Figma frame for the field first.
 */
class AddressBook implements ArgumentInterface
{
    public function __construct(
        private readonly CurrentCustomer $currentCustomer,
        private readonly CountryFactory $countryFactory,
        private readonly UrlInterface $url
    ) {
    }

    /**
     * The whole book, default first.
     *
     * @return AddressInterface[]
     */
    public function getAddresses(): array
    {
        try {
            $customer = $this->currentCustomer->getCustomer();
        } catch (NoSuchEntityException) {
            return [];
        }

        $addresses = $customer->getAddresses() ?? [];
        $defaultId = (int) ($customer->getDefaultShipping() ?: 0);

        if ($defaultId === 0) {
            return $addresses;
        }

        usort(
            $addresses,
            static fn (AddressInterface $a, AddressInterface $b): int
                => ((int) $b->getId() === $defaultId ? 1 : 0) <=> ((int) $a->getId() === $defaultId ? 1 : 0)
        );

        return $addresses;
    }

    public function getCount(): int
    {
        return count($this->getAddresses());
    }

    public function hasAddresses(): bool
    {
        return $this->getCount() > 0;
    }

    /**
     * The badge. Shipping rather than billing, because the shipping default is
     * the one this storefront's checkout actually consumes — and the
     * SetDefault controller keeps the two in step, so the distinction only
     * matters for rows created before it existed.
     */
    public function isDefault(AddressInterface $address): bool
    {
        try {
            $default = (int) ($this->currentCustomer->getCustomer()->getDefaultShipping() ?: 0);
        } catch (NoSuchEntityException) {
            return false;
        }

        return $default !== 0 && $default === (int) $address->getId();
    }

    /**
     * The card's heading. See the class docblock for why this is the name.
     */
    public function getTitle(AddressInterface $address): string
    {
        $name = trim(implode(' ', array_filter([
            $address->getFirstname(),
            $address->getMiddlename(),
            $address->getLastname(),
        ])));

        if ($name !== '') {
            return $name;
        }

        // A saved address always has a name — both are required attributes —
        // but a row imported from elsewhere might not, and an untitled card is
        // worse than one titled by where it is.
        return (string) ($address->getCity() ?: __('Saved address'));
    }

    /**
     * "27 El-Melatty Street, Zamalek, Cairo, Egypt 11211" — one line.
     */
    public function getAddressLine(AddressInterface $address): string
    {
        $parts = array_map('trim', (array) ($address->getStreet() ?? []));

        $region = $address->getRegion();

        $parts[] = (string) $address->getCity();
        $parts[] = $region !== null ? (string) $region->getRegion() : '';
        $parts[] = $this->countryName((string) $address->getCountryId());
        $parts[] = (string) $address->getPostcode();

        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));

        return implode('، ', $parts);
    }

    public function getPhone(AddressInterface $address): ?string
    {
        $phone = trim((string) $address->getTelephone());

        return $phone !== '' ? $phone : null;
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    public function getAddUrl(): string
    {
        return $this->url->getUrl('customer/address/new');
    }

    public function getEditUrl(AddressInterface $address): string
    {
        return $this->url->getUrl('customer/address/edit', ['id' => $address->getId()]);
    }

    /**
     * The PREFIX core's own delete widget expects, not a complete URL.
     *
     * Magento_Customer/js/address appends "id/<n>/form_key/<cookie>" and shows
     * the confirmation dialog. Reusing that widget rather than writing a
     * Spartrak delete script is what keeps this card's destructive action on
     * the platform's CSRF-checked path with no new JavaScript — see the
     * template's x-magento-init.
     */
    public function getDeleteUrlPrefix(): string
    {
        return $this->url->getUrl('customer/address/delete');
    }

    public function getSetDefaultUrl(): string
    {
        return $this->url->getUrl('spartrak_account/address/setDefault');
    }

    private function countryName(string $countryId): string
    {
        if ($countryId === '') {
            return '';
        }

        $name = $this->countryFactory->create()->loadByCode($countryId)->getName();

        return is_string($name) ? $name : '';
    }
}
