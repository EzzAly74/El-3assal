<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Model\Customer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\AccountConfirmation;
use Magento\Customer\Model\AuthenticationInterface;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\EmailNotConfirmedException;
use Magento\Framework\Exception\InvalidEmailOrPasswordException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\State\UserLockedException;
use Psr\Log\LoggerInterface;
use Spartrak\CustomerAuth\Model\Phone\Normalizer;

/**
 * Signs a shopper in with phone number + password.
 *
 * ===========================================================================
 * WHY ID-BASED AUTHENTICATION, NOT EMAIL-BASED
 * ===========================================================================
 * This used to resolve the phone to a customer, read that customer's email, and
 * hand the email to AccountManagementInterface::authenticate(). That worked, but
 * it made every sign-in depend on the email COLUMN — the one field this
 * storefront treats as an internal implementation detail that may hold a
 * synthesized placeholder, and which the shopper can later change.
 *
 * Magento 2.4.8 exposes an identifier-based path for exactly this case:
 * AuthenticationInterface::authenticate($customerId, $password) (@api, @since
 * 100.1.0). Credential handling stays entirely in core — Model\Authentication
 * does the hash validation, the failed-attempt increment and the lock check.
 * Nothing about password verification is reimplemented here.
 *
 * It also removes a query: core's email path does its own
 * customerRepository->get($email) lookup, which is redundant when PhoneLocator
 * has already resolved the customer. Sign-in is a hot path.
 *
 * ===========================================================================
 * WHAT HAD TO BE ORCHESTRATED BY HAND, AND WHY EACH ONE MATTERS
 * ===========================================================================
 * Model\Authentication::authenticate() verifies the password and nothing else.
 * The surrounding steps live in Model\AccountManagement\Authenticate::execute(),
 * which is email-keyed and therefore unusable here, so they are reproduced —
 * checked against that class in 2.4.8, in its order:
 *
 *   1. isLocked() BEFORE verifying. Core pre-checks (Authenticate.php:92); the
 *      low-level method only reports a lock AFTER burning an attempt. Skipping
 *      this lets a locked account keep counting failures.
 *   2. The account-confirmation gate (Authenticate.php:95-98).
 *   3. `customer_customer_authenticated` (Authenticate.php:108-111). This one is
 *      load-bearing and easy to miss: its observers perform legacy password-hash
 *      upgrade-on-login, the lockout-counter reset
 *      (CustomerLoginSuccessObserver -> unlock()), the CAPTCHA attempt reset,
 *      and VAT-based customer-group re-evaluation. Not dispatching it means
 *      failure counters never clear, so a shopper who mistypes their password
 *      three times over three months eventually locks out on a correct one.
 *
 * `customer_data_object_login` is deliberately NOT dispatched here, unlike core:
 * setCustomerDataAsLoggedIn() raises it a moment later (Session.php:472), and
 * emitting it twice per sign-in would double every observer bound to it.
 *
 * `customer_login` needs no handling at all — the session raises it
 * (Session.php:471), which is what makes Magento_Checkout's
 * LoadCustomerQuoteObserver merge the guest cart. Losing that silently empties
 * the basket a shopper was mid-purchase on, so module.xml sequences
 * Magento_Checkout after Magento_Customer to guarantee the observer is
 * registered.
 */
class Authenticator
{
    public function __construct(
        private readonly PhoneLocator $phoneLocator,
        private readonly AuthenticationInterface $authentication,
        private readonly AccountConfirmation $accountConfirmation,
        private readonly CustomerFactory $customerFactory,
        private readonly EventManager $eventManager,
        private readonly CustomerSession $customerSession,
        private readonly Normalizer $normalizer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Authenticate and start a customer session.
     *
     * @throws InvalidEmailOrPasswordException Wrong number or wrong password —
     *         one exception for both, so the response cannot be used to test
     *         whether a phone number has an account.
     * @throws UserLockedException
     * @throws EmailNotConfirmedException
     * @throws LocalizedException
     */
    public function login(string $phoneE164, string $password): CustomerInterface
    {
        $customer = $this->phoneLocator->findCustomerByPhone($phoneE164);

        if ($customer === null) {
            // No account for this number. Deliberately the same failure the
            // wrong-password path produces below: a distinct "no such account"
            // response would turn this endpoint into a phone-number enumerator,
            // and the numbers are guessable (Egyptian mobiles are a 10-digit
            // space with 4 known prefixes).
            //
            // The unavoidable cost is that a genuine typo also reads as "wrong
            // password". The storefront copy therefore names both possibilities
            // rather than blaming the password.
            throw new InvalidEmailOrPasswordException(
                __('The phone number or password is incorrect.')
            );
        }

        $customerId = (int) $customer->getId();

        // Step 1 — refuse a locked account before spending an attempt on it.
        if ($this->authentication->isLocked($customerId)) {
            $this->logger->info(
                sprintf(
                    'Spartrak_CustomerAuth: sign-in refused for locked account %s.',
                    $this->normalizer->mask($phoneE164)
                )
            );

            throw new UserLockedException(__('The account is locked.'));
        }

        // Step 2 — account confirmation. Reproduces core's gate so enabling
        // "require email confirmation" behaves identically to before this class
        // stopped using the email-keyed API.
        //
        // NOTE for whoever turns that setting on: an account registered by phone
        // has a placeholder address on an undeliverable .invalid domain, so its
        // confirmation mail can never arrive and the shopper would be locked out
        // permanently. Phone ownership is already proven by OTP at registration,
        // which is what makes email confirmation redundant here rather than an
        // extra layer.
        if ($customer->getConfirmation()
            && $this->accountConfirmation->isConfirmationRequired(
                $customer->getWebsiteId(),
                $customerId,
                (string) $customer->getEmail()
            )
        ) {
            throw new EmailNotConfirmedException(
                __('This account isn\'t confirmed. Verify and try again.')
            );
        }

        try {
            $this->authentication->authenticate($customerId, $password);
        } catch (InvalidEmailOrPasswordException) {
            // Re-thrown with our own wording so the message never mentions an
            // email address the shopper has not seen and may not know exists.
            throw new InvalidEmailOrPasswordException(
                __('The phone number or password is incorrect.')
            );
        } catch (UserLockedException $e) {
            // Reachable when this very attempt crossed the lockout threshold.
            $this->logger->info(
                sprintf(
                    'Spartrak_CustomerAuth: account %s locked out on a failed sign-in.',
                    $this->normalizer->mask($phoneE164)
                )
            );

            throw $e;
        }

        // Step 3 — see the class comment. Hash upgrade, lockout-counter reset,
        // CAPTCHA reset and VAT group re-evaluation all hang off this event.
        $this->eventManager->dispatch(
            'customer_customer_authenticated',
            [
                'model' => $this->customerFactory->create()->updateData($customer),
                'password' => $password,
            ]
        );

        $this->startSession($customer);

        return $customer;
    }

    /**
     * Start a session for a customer whose identity is already established.
     *
     * Used by registration (a shopper who just proved the number AND set the
     * password should not be made to type them again) and by password reset.
     */
    public function startSession(CustomerInterface $customer): void
    {
        // Fresh session id on every privilege change — this is the session
        // fixation defence, and it has to happen for registration and password
        // reset just as much as for sign-in.
        $this->customerSession->regenerateId();
        $this->customerSession->setCustomerDataAsLoggedIn($customer);
    }
}
