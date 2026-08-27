<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Model\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Resolves a phone number to a customer.
 *
 * Uses a narrow collection query rather than CustomerRepositoryInterface::getList
 * with a SearchCriteria: getList hydrates full customer objects with every EAV
 * attribute and extension attribute just to answer "does this number exist?",
 * and this runs on every sign-in attempt. Here we fetch two columns, then hand
 * the id to the repository only when a full entity is genuinely needed.
 *
 * SCOPE NOTE (deliberate, documented): the UNIQUE index behind this lookup is on
 * customer_entity.phone_number with no website column, so a phone number
 * identifies exactly one account store-wide. That is correct for this project (a
 * single Egyptian website) and it is what makes "one phone, one account" a
 * database guarantee rather than a hopeful application check. If a second
 * website is ever added AND customer accounts are scoped per-website, this
 * constraint has to be revisited before that launch — the same number could then
 * legitimately need an account on each website.
 */
class PhoneLocator
{
    public function __construct(
        private readonly CollectionFactory $customerCollectionFactory,
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
    }

    /**
     * Customer id for an E.164 number, or null.
     */
    public function findCustomerIdByPhone(string $phoneE164): ?int
    {
        if ($phoneE164 === '') {
            return null;
        }

        $collection = $this->customerCollectionFactory->create();
        $collection->addAttributeToSelect('entity_id')
            ->addFieldToFilter('phone_number', $phoneE164)
            ->setPageSize(1)
            ->setCurPage(1);

        $customerId = $collection->getFirstItem()->getId();

        return $customerId ? (int) $customerId : null;
    }

    /**
     * Full customer entity for an E.164 number, or null.
     */
    public function findCustomerByPhone(string $phoneE164): ?CustomerInterface
    {
        $customerId = $this->findCustomerIdByPhone($phoneE164);

        if ($customerId === null) {
            return null;
        }

        try {
            return $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException) {
            // The row existed a moment ago and does not now. Treat as absent
            // rather than propagating — the caller's contract is "found or not".
            return null;
        }
    }

    public function isPhoneRegistered(string $phoneE164): bool
    {
        return $this->findCustomerIdByPhone($phoneE164) !== null;
    }
}
