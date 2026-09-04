<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Checkout\ViewModel;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;

/**
 * The `الشحن الي` line on the cart - Figma 553:4765.
 *
 * ===========================================================================
 * IT SHOWS THE CUSTOMER'S DEFAULT SHIPPING ADDRESS, OR NOTHING
 * ===========================================================================
 * 553:4769 reads `ميدان رمسيس, القاهرة` - a real address, not a prompt. The row
 * exists to tell a shopper where this basket is going before they commit to it,
 * so with no address there is nothing for it to say and the design draws no
 * empty state for it. `hasAddress()` is how the template decides.
 *
 * The DEFAULT shipping address specifically, not the most recent or the first:
 * it is the one the checkout will preselect, so it is the one this row is
 * honestly predicting.
 *
 * ===========================================================================
 * SERVICE CONTRACTS, NOT `$session->getCustomer()`
 * ===========================================================================
 * `CustomerSession::getCustomer()` returns the old model and would let a
 * template walk into `getDefaultShippingAddress()->getRegion()`. Going through
 * the repositories keeps this on the API Magento actually supports, and keeps
 * every failure in one place - a customer or address that has been deleted
 * since the session was created throws NoSuchEntityException, and this class
 * answers "no address" rather than letting a 404 reach the cart page.
 *
 * ===========================================================================
 * EVERYTHING IS RESOLVED ONCE
 * ===========================================================================
 * The template asks twice - once for the guard, once for the line - and the
 * cart is a page a shopper reloads. Two repository round trips for one answer
 * is two more than the page needs (CLAUDE.md §4).
 */
class CartShipTo implements ArgumentInterface
{
    private bool $resolved = false;
    private ?AddressInterface $address = null;

    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Whether there is an address to draw the row for.
     */
    public function hasAddress(): bool
    {
        return $this->getAddress() !== null;
    }

    /**
     * The address as one line: `ميدان رمسيس, القاهرة`.
     *
     * Street then governorate, comma separated, empty parts dropped - the same
     * shape the checkout's address card prints, and for the same reason the
     * card drops a duplicate: this store fills `city` FROM the governorate
     * (Spartrak_CustomerAddress), so printing both would say Cairo twice.
     */
    public function getAddressLine(): string
    {
        $address = $this->getAddress();

        if ($address === null) {
            return '';
        }

        $region = $address->getRegion();
        $regionName = $region !== null ? trim((string) $region->getRegion()) : '';
        $city = trim((string) $address->getCity());

        $parts = array_merge(
            array_map('trim', $address->getStreet() ?? []),
            [$city === $regionName ? '' : $city, $regionName]
        );

        return implode(', ', array_filter($parts, static fn ($part) => $part !== ''));
    }

    private function getAddress(): ?AddressInterface
    {
        if ($this->resolved) {
            return $this->address;
        }

        $this->resolved = true;

        if (!$this->customerSession->isLoggedIn()) {
            return null;
        }

        try {
            $customer = $this->customerRepository->getById((int) $this->customerSession->getCustomerId());
            $defaultShippingId = $customer->getDefaultShipping();

            if ($defaultShippingId === null || $defaultShippingId === '') {
                return null;
            }

            $this->address = $this->addressRepository->getById((int) $defaultShippingId);
        } catch (NoSuchEntityException $e) {
            // The customer or the address was deleted while the session lived.
            // Not an error worth a page failure — the row simply has nothing to
            // show, which is a state the design already covers.
            $this->address = null;
        } catch (\Exception $e) {
            $this->logger->error('Spartrak: could not resolve the cart ship-to address.', ['exception' => $e]);
            $this->address = null;
        }

        return $this->address;
    }
}
