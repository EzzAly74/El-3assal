<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Model\Customer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\AccountManagement;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Math\Random;
use Spartrak\CustomerAuth\Exception\OtpVerificationException;
use Spartrak\CustomerAuth\Model\Otp\Purpose;
use Spartrak\CustomerAuth\Model\Otp\Service as OtpService;

/**
 * Replaces a password after phone ownership was proven by OTP.
 *
 * Implemented as "mint a reset token, then spend it through core" rather than
 * writing a password hash directly. Core's resetPassword() does five things this
 * class must not skip and should not duplicate:
 *
 *   - enforces the configured password strength/length policy
 *   - rejects a password equal to the account email
 *   - clears the failed-login counter and any active lockout
 *   - invalidates the customer's OTHER sessions (SessionCleaner), so a reset
 *     actually evicts whoever prompted it
 *   - saves through the repository, firing the normal customer-save events
 *
 * A direct setPasswordHash() would quietly bypass all five, and the fourth is
 * the whole point of a password reset.
 */
class PasswordResetter
{
    /**
     * Length of the throwaway reset token. Never leaves the server — it is
     * minted and spent inside one request — but it is still generated from a
     * cryptographic source because core validates it as a real token.
     */
    private const RESET_TOKEN_LENGTH = 32;

    public function __construct(
        private readonly OtpService $otpService,
        private readonly PhoneLocator $phoneLocator,
        private readonly Registrar $registrar,
        private readonly Authenticator $authenticator,
        /**
         * The concrete class, not AccountManagementInterface.
         *
         * changeResetPasswordLinkToken() is public on the implementation but is
         * not part of the service contract, and it is the only supported way to
         * set rp_token without also sending the "reset your password" email that
         * initiatePasswordReset() dispatches — an email that would go to a
         * synthesized, undeliverable address for most of these customers.
         * Magento's own di.xml maps the interface to this class, so this injects
         * the same shared instance rather than a second object.
         */
        private readonly AccountManagement $accountManagement,
        private readonly Random $random
    ) {
    }

    /**
     * Set a new password for the account owning $phoneE164.
     *
     * @param string $proofToken Single-use token from OtpService::verify() that
     *                           was issued for Purpose::PASSWORD_RESET.
     * @param bool   $signIn     Start a session immediately on success.
     *
     * @throws OtpVerificationException
     * @throws LocalizedException
     */
    public function reset(
        string $phoneE164,
        string $proofToken,
        string $newPassword,
        bool $signIn = true
    ): CustomerInterface {
        // Redeem first: authorizes the request and makes the token single-use, so
        // a replayed request cannot reset the password a second time.
        $this->otpService->redeemProofToken($proofToken, $phoneE164, Purpose::PASSWORD_RESET);

        $customer = $this->phoneLocator->findCustomerByPhone($phoneE164);

        if ($customer === null || $customer->getEmail() === null) {
            // The account existed when the code was requested and does not now
            // (deleted mid-flow). Reported as an expired verification, which is
            // both true from the shopper's point of view and non-disclosing.
            throw new OtpVerificationException(
                __('Your verification has expired. Please start again.')
            );
        }

        $resetToken = $this->random->getRandomString(self::RESET_TOKEN_LENGTH);
        $this->accountManagement->changeResetPasswordLinkToken($customer, $resetToken);
        $this->accountManagement->resetPassword($customer->getEmail(), $resetToken, $newPassword);

        // Ownership of the number was just re-proven, so refresh the stamp.
        // Re-read the entity first: resetPassword() saved it, and continuing to
        // use the pre-save instance would write back a stale attribute set.
        $refreshed = $this->phoneLocator->findCustomerByPhone($phoneE164) ?? $customer;
        $this->registrar->markPhoneVerified($refreshed);

        if ($signIn) {
            // resetPassword() cleared every session for this customer, including
            // one this same browser may have held. Starting a new session here is
            // what makes "reset and continue shopping" work instead of dropping
            // the shopper back at a sign-in form.
            $this->authenticator->startSession($refreshed);
        }

        return $refreshed;
    }
}
