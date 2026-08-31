<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Checkout\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Address\CustomerAddressDataProviderFactory;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Customer\Model\Session as CustomerSession;

/**
 * The signed-in customer's addresses, in the shape the checkout's own address
 * list expects.
 *
 * ===========================================================================
 * WHY THE SERVER REBUILDS THE WHOLE LIST INSTEAD OF THE BROWSER PATCHING IT
 * ===========================================================================
 * After saving an address the checkout has to show the result. The tempting
 * shortcut is to splice the saved address into the list already in memory - one
 * array operation, no extra payload.
 *
 * It is wrong for the default-address flag specifically. Making address B the
 * default silently un-defaults address A, because `default_shipping` is a
 * single column on the CUSTOMER, not a flag on each address. A browser that
 * patched only the address it just saved would leave A still wearing the
 * `الافتراضي` badge, and the shopper would see two defaults - one of which is a
 * lie about what the database says.
 *
 * The same argument covers anything else a save can change indirectly: an
 * observer, a plugin, a normalising validator. Re-reading is the only way the
 * screen can be trusted to match the record.
 *
 * ===========================================================================
 * IT IS MAGENTO'S OWN FORMATTER, NOT A HAND-WRITTEN ARRAY
 * ===========================================================================
 * CustomerAddressDataProvider is exactly what Magento_Checkout's own
 * DefaultConfigProvider uses to build `checkoutConfig.customerData.addresses`,
 * so what comes back here is byte-identical in shape to what the page was
 * rendered with - including the `inline` string, the nested `region` object and
 * the custom attributes.
 *
 * Building that array by hand would have meant reimplementing address
 * formatting, and it would have diverged the first time a merchant changed the
 * address format template.
 *
 * ===========================================================================
 * WHY A FACTORY
 * ===========================================================================
 * CustomerAddressDataProvider memoises its result in a private property and
 * returns the same array on every subsequent call, forever. Injecting the
 * shared instance would mean that anything which had already asked it for
 * addresses in this request - and the checkout config provider is exactly that
 * - would hand back the list from BEFORE the save. A fresh instance per call
 * has no memory to be stale.
 */
class CheckoutAddressList
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerRegistry $customerRegistry,
        private readonly CustomerAddressDataProviderFactory $addressDataProviderFactory
    ) {
    }

    /**
     * @return array<int, array<string, mixed>> keyed by address id
     */
    public function getForCurrentCustomer(): array
    {
        $customerId = (int) $this->customerSession->getCustomerId();

        if ($customerId <= 0) {
            return [];
        }

        /**
         * The registry is flushed first because it is what the repository reads
         * from. Saving an address updates the customer row (default_shipping)
         * through the address resource model's relation handler, and a customer
         * already in the registry from earlier in this request still carries
         * the pre-save defaults and the pre-save address collection.
         */
        $this->customerRegistry->remove($customerId);
        $customer = $this->customerRepository->getById($customerId);

        return $this->addressDataProviderFactory->create()->getAddressDataByCustomer($customer);
    }
}
