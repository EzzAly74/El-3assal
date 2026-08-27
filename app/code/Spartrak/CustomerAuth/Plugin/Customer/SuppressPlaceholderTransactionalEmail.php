<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Plugin\Customer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\EmailNotificationInterface;
use Psr\Log\LoggerInterface;
use Spartrak\CustomerAuth\Model\Customer\PlaceholderEmail;

/**
 * Stops every EmailNotificationInterface method from mailing an address this
 * module synthesized.
 *
 * WIDENED 2026-08-26 from a newAccount()-only guard, per the explicit business
 * requirement: "Never send customer-facing emails to the synthetic .invalid
 * address." That is a blanket rule, not one scoped to the welcome email, so all
 * four methods on the interface are covered here rather than adding a second,
 * near-identical plugin class per method.
 *
 * ===========================================================================
 * WHY THIS INTERCEPTS THE PUBLIC METHODS, NOT TransportBuilder::addTo()
 * ===========================================================================
 * The obvious-looking alternative — filter recipients centrally in
 * Magento\Framework\Mail\Template\TransportBuilder::addTo() — was tried and
 * rejected after tracing what happens to a message with zero recipients.
 * Every EmailNotification method funnels through a private sendEmailTemplate()
 * that ends in ->addTo($email, ...)->getTransport(), and getTransport() builds
 * an EmailMessage via emailMessageInterfaceFactory. EmailMessage::setRecipients()
 * (vendor/magento/framework/Mail/EmailMessage.php) THROWS
 * InvalidArgumentException('Email message must have at least one addressee')
 * the moment the 'To' list is empty. Silently dropping the sole recipient at
 * addTo() therefore does not suppress the email — it turns the send into an
 * uncaught exception one call later, which is worse than the mail this is
 * trying to prevent. Intercepting the public method instead means the send is
 * never attempted at all when it would target only a placeholder.
 *
 * ===========================================================================
 * WHY credentialsChanged() SOMETIMES SUPPRESSES A NOTIFICATION THAT DID
 * PARTIALLY CONCERN A REAL ADDRESS
 * ===========================================================================
 * credentialsChanged() is the one case with two possible recipients in a
 * single call: when an email actually changes, core's private emailChanged()
 * (or emailAndPasswordChanged()) is invoked once for the OLD address and once
 * for the NEW one — each its own independent sendEmailTemplate() call, so
 * suppressing one does not touch the other. But those two calls are private;
 * there is no seam to let the "old" leg through the around-plugin while
 * blocking only the "new" leg, short of reimplementing core's branching here,
 * which would duplicate business logic this module does not own.
 *
 * The customer-visible case this affects is exactly the target flow: a shopper
 * with a synthesized email uses "Add Email" to supply a real one for the first
 * time. Both notification legs are suppressed together in that case, with this
 * reasoning:
 *   - The OLD-address leg is undeliverable by construction and must never be
 *     attempted regardless.
 *   - The NEW-address leg, here, is "your email was just added" — a
 *     confirmation of an action the shopper is actively completing in the same
 *     request, not an alert about a change they need to notice happening
 *     elsewhere. The success response the modal/form already shows covers that.
 *
 * Once a customer already has a REAL email on file, this plugin does nothing:
 * $origCustomerEmail is never a placeholder for such an account (the module
 * only ever writes a placeholder at account CREATION — see Registrar — and the
 * native edit form's email field is required + format-validated, so it cannot
 * be blanked back to one), so both legs proceed exactly as core intends.
 *
 * ===========================================================================
 * passwordReminder() / passwordResetConfirmation()
 * ===========================================================================
 * These belong to Magento's native EMAIL-LINK password-reset flow
 * (AccountManagement::initiatePasswordReset()), which this module's own
 * PasswordResetter deliberately bypasses in favour of the OTP flow — see that
 * class. Both should therefore be unreachable for a phone-registered customer
 * in normal operation. They are still guarded here in case the native
 * forgotpasswordpost action is ever posted to directly: the GET page is
 * redirected away from being customer-facing (see
 * Observer\RedirectNativeAuthPageToModal), but POST actions are deliberately
 * left functional per the architecture decision, so this is defence in depth
 * against that one residual path rather than a documented reachable flow.
 */
class SuppressPlaceholderTransactionalEmail
{
    public function __construct(
        private readonly PlaceholderEmail $placeholderEmail,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param callable $proceed
     * @param string   $origCustomerEmail
     * @param bool     $isPasswordChanged
     */
    public function aroundCredentialsChanged(
        EmailNotificationInterface $subject,
        callable $proceed,
        CustomerInterface $savedCustomer,
        $origCustomerEmail,
        $isPasswordChanged = false
    ) {
        $storeId = $this->resolveStoreId($savedCustomer);
        $oldWasPlaceholder = $this->placeholderEmail->isPlaceholder((string) $origCustomerEmail, $storeId);
        $newIsPlaceholder = $this->placeholderEmail->isPlaceholder($savedCustomer->getEmail(), $storeId);
        $emailChanged = $origCustomerEmail != $savedCustomer->getEmail();

        // Only the "old side was a placeholder, and the email actually changed"
        // case is suppressed — see the class comment for why the whole
        // notification, not just the undeliverable half, is dropped here.
        // A password-only change (no email change) or a real-to-real email
        // change proceeds untouched.
        if ($emailChanged && $oldWasPlaceholder) {
            $this->logSuppressed('credentialsChanged', $savedCustomer);

            return null;
        }

        // $newIsPlaceholder is not expected to ever be true — see the class
        // comment on why the native form cannot produce it — but if it somehow
        // is, suppressing here is the same safe default rather than mailing an
        // address we know is undeliverable.
        if ($emailChanged && $newIsPlaceholder) {
            $this->logSuppressed('credentialsChanged', $savedCustomer);

            return null;
        }

        return $proceed($savedCustomer, $origCustomerEmail, $isPasswordChanged);
    }

    /**
     * @param callable $proceed
     */
    public function aroundPasswordReminder(
        EmailNotificationInterface $subject,
        callable $proceed,
        CustomerInterface $customer
    ) {
        if ($this->isPlaceholderFor($customer)) {
            $this->logSuppressed('passwordReminder', $customer);

            return null;
        }

        return $proceed($customer);
    }

    /**
     * @param callable $proceed
     */
    public function aroundPasswordResetConfirmation(
        EmailNotificationInterface $subject,
        callable $proceed,
        CustomerInterface $customer
    ) {
        if ($this->isPlaceholderFor($customer)) {
            $this->logSuppressed('passwordResetConfirmation', $customer);

            return null;
        }

        return $proceed($customer);
    }

    /**
     * @param callable $proceed
     * @param string   $type
     * @param string   $backUrl
     * @param int      $storeId
     * @param string   $sendemailStoreId
     */
    public function aroundNewAccount(
        EmailNotificationInterface $subject,
        callable $proceed,
        CustomerInterface $customer,
        $type = EmailNotificationInterface::NEW_ACCOUNT_EMAIL_REGISTERED,
        $backUrl = '',
        $storeId = 0,
        $sendemailStoreId = null
    ) {
        if ($this->isPlaceholderFor($customer, $storeId)) {
            $this->logSuppressed((string) $type, $customer);

            return null;
        }

        return $proceed($customer, $type, $backUrl, $storeId, $sendemailStoreId);
    }

    private function isPlaceholderFor(CustomerInterface $customer, $storeId = null): bool
    {
        $storeIdForConfig = is_numeric($storeId) ? (int) $storeId : $this->resolveStoreId($customer);

        return $this->placeholderEmail->isPlaceholder($customer->getEmail(), $storeIdForConfig);
    }

    private function resolveStoreId(CustomerInterface $customer): ?int
    {
        $storeId = $customer->getStoreId();

        return $storeId === null ? null : (int) $storeId;
    }

    /**
     * Logged at debug: routine, expected behaviour for every phone-only
     * registration until a real email is added, not an error.
     */
    private function logSuppressed(string $context, CustomerInterface $customer): void
    {
        $this->logger->debug(
            sprintf(
                'Spartrak_CustomerAuth: suppressed the "%s" customer email for customer #%s — '
                . 'target address is a synthesized, undeliverable placeholder.',
                $context,
                (string) ($customer->getId() ?? 'new')
            )
        );
    }
}
