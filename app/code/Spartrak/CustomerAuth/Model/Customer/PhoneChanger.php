<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Model\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Spartrak\CustomerAuth\Exception\FieldValidationException;
use Spartrak\CustomerAuth\Exception\OtpVerificationException;
use Spartrak\CustomerAuth\Model\Otp\Purpose;
use Spartrak\CustomerAuth\Model\Otp\Service as OtpService;

/**
 * Moves an existing account onto a new phone number, once the shopper has
 * proven they hold it.
 *
 * ===========================================================================
 * WHY THIS IS THE ONLY WRITER
 * ===========================================================================
 * `customer_entity.phone_number` is the LOGIN IDENTIFIER on this storefront
 * (Spartrak_CustomerAuth's Authenticator resolves a sign-in against it, and the
 * column is uniquely indexed — see etc/db_schema.xml). Anything that can write
 * it can move an account's front door.
 *
 * So there is exactly one path: a redeemed PHONE_CHANGE proof token, redeemed
 * here, against the number the token was issued for. The account edit form does
 * not post `phone_number` at all, and core's `customer/account/editPost` has
 * never known the attribute existed — which is what stops the obvious bypass of
 * simply submitting the field.
 *
 * ===========================================================================
 * VERIFY BEFORE COMMIT, AND WHERE THE PENDING NUMBER LIVES
 * ===========================================================================
 * Nothing is written until the code checks out, so a shopper who abandons the
 * flow — or fat-fingers the number — still signs in with the number they had.
 *
 * That needs somewhere to hold the number while it is pending, and it needs no
 * new table: `spartrak_customer_otp` already stores the number a code was
 * issued against, keyed with its purpose. The pending phone IS the OTP row. A
 * `pending_phone` column on the customer would have been a second copy of that
 * fact, with its own expiry to maintain and its own chance to disagree.
 *
 * ===========================================================================
 * THE UNIQUENESS CHECK HAPPENS TWICE, ON PURPOSE
 * ===========================================================================
 * Controller\Otp\Send checks before sending, so the shopper is told about a
 * clash before waiting for an SMS. This checks again at commit, because the two
 * moments are minutes apart and somebody else can register the number in
 * between. The second check is the one that matters; the first is a courtesy.
 *
 * Even so, `save()` is guarded: two requests can pass the same check
 * concurrently, and the unique index is the only thing that actually serialises
 * them. AlreadyExistsException is translated rather than left to surface as a
 * 500 with a database message in it.
 */
class PhoneChanger
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly PhoneLocator $phoneLocator,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Redeem a PHONE_CHANGE token and move the account onto its number.
     *
     * @param int    $customerId The SESSION's customer. Never taken from the
     *                           request: a customer id in a POST body is a
     *                           request to edit somebody else's account.
     * @param string $phoneE164  The new number, already normalized.
     * @param string $token      Proof token from Controller\Otp\Verify.
     *
     * @throws OtpVerificationException The token is wrong, expired, spent, or
     *                                 was issued for a different number or a
     *                                 different purpose.
     * @throws FieldValidationException The number became unusable since the code
     *                                  was sent.
     * @throws NoSuchEntityException    The session points at a deleted customer.
     */
    public function change(int $customerId, string $phoneE164, string $token): CustomerInterface
    {
        // FIRST, before anything is loaded or saved. redeemProofToken() re-checks
        // the purpose and the number the token was minted for, so a token
        // obtained for signup — or for a different number — dies here rather
        // than being spent on this account.
        $this->otpService->redeemProofToken($token, $phoneE164, Purpose::PHONE_CHANGE);

        $owner = $this->phoneLocator->findCustomerIdByPhone($phoneE164);

        if ($owner !== null && $owner !== $customerId) {
            throw new FieldValidationException(
                __('Another account already uses this phone number.'),
                'phone'
            );
        }

        $customer = $this->customerRepository->getById($customerId);

        // Already there — the token is spent and the state is what was asked
        // for, so this is a success, not a conflict. Happens on a double submit.
        if ($owner === $customerId) {
            return $customer;
        }

        $customer->setCustomAttribute('phone_number', $phoneE164);
        // The number is verified as of NOW: a code was just delivered to it and
        // read back. Written through the same attribute pair Registrar uses at
        // signup, so "when did we last prove this number" means one thing
        // whichever door the customer came through.
        $customer->setCustomAttribute('phone_verified_at', $this->dateTime->gmtDate('Y-m-d H:i:s'));

        try {
            return $this->customerRepository->save($customer);
        } catch (AlreadyExistsException $e) {
            throw new FieldValidationException(
                __('Another account already uses this phone number.'),
                'phone',
                $e
            );
        }
    }
}
