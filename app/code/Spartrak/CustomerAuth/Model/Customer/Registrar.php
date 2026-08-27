<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Model\Customer;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\State\InputMismatchException;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;
use Spartrak\CustomerAuth\Model\Otp\Purpose;
use Spartrak\CustomerAuth\Model\Otp\Service as OtpService;

/**
 * Creates an account from a phone number whose ownership was just proven.
 *
 * Account creation itself is delegated to AccountManagementInterface, not
 * reimplemented. That matters: password strength policy, the password-history
 * check, the customer-created event, welcome-email dispatch and the website/group
 * assignment all live there, and a hand-rolled customerRepository->save() would
 * silently skip every one of them. This class contributes exactly three things
 * Magento does not do — proof-token redemption, phone-to-identity mapping, and
 * the synthesized email.
 */
class Registrar
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly PhoneLocator $phoneLocator,
        private readonly PlaceholderEmail $placeholderEmail,
        private readonly CustomerInterfaceFactory $customerFactory,
        private readonly AccountManagementInterface $accountManagement,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Register a verified phone number as a customer account.
     *
     * @param string $phoneE164  Canonical phone number.
     * @param string $proofToken Single-use token from OtpService::verify().
     * @param string $firstName  As typed by the shopper.
     * @param string $lastName   As typed by the shopper.
     * @param string $password   Plain text; validated and hashed downstream.
     *
     * There is deliberately NO $email parameter. The designed registration step
     * collects a phone number, two name fields and a password — never an email —
     * so the address is ALWAYS synthesized from the phone number here. Accepting
     * a caller-supplied email would reopen the exact hole the business rule
     * closes: a value posted by the frontend becoming the account's identity.
     * A shopper who later wants a real address changes it through My Account,
     * which goes through Magento's own email-change validation.
     *
     * @throws LocalizedException
     */
    public function register(
        string $phoneE164,
        string $proofToken,
        string $firstName,
        string $lastName,
        string $password
    ): CustomerInterface {
        // Redeem FIRST. This both authorizes the request and burns the token, so
        // a duplicate submit (double-click, retried request) fails here rather
        // than racing to create a second account.
        $this->otpService->redeemProofToken($proofToken, $phoneE164, Purpose::SIGNUP);

        // Cheap pre-check for a clear error message. The real guarantee is the
        // UNIQUE index, enforced below — this check can lose a race and that is
        // fine, because losing it produces AlreadyExistsException, which is
        // handled.
        if ($this->phoneLocator->isPhoneRegistered($phoneE164)) {
            throw new InputMismatchException(
                __('An account already exists for this phone number. Please sign in instead.')
            );
        }

        $store = $this->storeManager->getStore();
        $storeId = (int) $store->getId();
        $websiteId = (int) $store->getWebsiteId();

        $customer = $this->customerFactory->create();
        $customer->setFirstname($this->requireName($firstName, __('Please enter your first name.')));
        $customer->setLastname($this->requireName($lastName, __('Please enter your last name.')));
        $customer->setEmail($this->placeholderEmail->generate($phoneE164, $storeId));
        $customer->setStoreId($storeId);
        $customer->setWebsiteId($websiteId);
        $customer->setCustomAttribute('phone_number', $phoneE164);
        $customer->setCustomAttribute('phone_verified_at', $this->dateTime->gmtDate('Y-m-d H:i:s'));

        try {
            return $this->accountManagement->createAccount($customer, $password);
        } catch (AlreadyExistsException | InputMismatchException $e) {
            // Either unique index fired: phone (someone registered the same
            // number in the last few milliseconds) or email (a real address
            // already in use). Both are the same instruction to the shopper.
            throw new InputMismatchException(
                __('An account already exists for this phone number or email. Please sign in instead.'),
                $e
            );
        }
    }

    /**
     * Re-stamp phone verification on an existing account.
     *
     * Used after a password reset, where ownership of the number was proven
     * again. Kept here so the phone_verified_at attribute has exactly one writer.
     */
    public function markPhoneVerified(CustomerInterface $customer): void
    {
        $customer->setCustomAttribute('phone_verified_at', $this->dateTime->gmtDate('Y-m-d H:i:s'));
        $this->customerRepository->save($customer);
    }

    /**
     * Trim a name field and reject an empty one.
     *
     * Magento's own createAccount() would also refuse a blank name, but its
     * message identifies neither which field was wrong nor what to do about it,
     * and the modal renders errors against a specific input. Validating here
     * also keeps the service contract honest: Registrar is a public boundary, so
     * it should not forward a value it already knows Magento will reject three
     * layers down.
     *
     * Interior whitespace is collapsed so a pasted "Mohamed   Ali" stores
     * cleanly. Nothing else is touched — no case forcing and no
     * transliteration, both of which mangle Arabic names.
     */
    private function requireName(string $name, Phrase $emptyMessage): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ($normalized === '') {
            throw new LocalizedException($emptyMessage);
        }

        return $normalized;
    }
}
