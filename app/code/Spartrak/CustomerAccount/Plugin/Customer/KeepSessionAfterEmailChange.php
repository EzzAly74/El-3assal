<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAccount\Plugin\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Controller\Account\EditPost;
use Magento\Customer\Model\AuthenticationInterface;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Customer\Model\ResourceModel\Visitor as VisitorResource;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Visitor;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Session\SessionManagerInterface;

/**
 * Keeps a shopper signed in after they change their EMAIL ADDRESS.
 *
 * NEW2B-5613.
 *
 * ===========================================================================
 * WHAT HAPPENS WITHOUT THIS — MEASURED, NOT INFERRED
 * ===========================================================================
 * Magento\Customer\Controller\Account\EditPost, on a successful email change:
 *
 *     $this->session->logout();
 *     $this->session->start();
 *     return $resultRedirect->setPath('customer/account/login');
 *
 * customer/account/login is not a page on this storefront — sign-in is a
 * modal, and Spartrak\CustomerAuth\Observer\RedirectNativeAuthPageToModal
 * bounces that GET back to the referring page with `#auth=login`. The referrer
 * is customer/account/edit, which Magento\Customer\Controller\Plugin\Account
 * guards: no session, so it redirects to customer/account/login. Which bounces
 * back to the referrer. Which redirects to login.
 *
 * That is not a bad landing page, it is a LOOP. Confirmed from production as
 * ERR_TOO_MANY_REDIRECTS the moment the save started succeeding.
 *
 * WHY IT TOOK SO LONG TO SEE: every earlier attempt to reproduce it hit an
 * unrelated failure first. core's UpgradeOrderCustomerEmailObserver syncs a
 * changed address onto the customer's existing orders, one of which was a depot
 * order this project's own consignment gate refused to let anything write (see
 * Spartrak\PickupLocation\Observer\RequireConsignmentBeforeDispatch, "IT GATES
 * THE TRANSITION"). The save threw every time, so core never reached the logout
 * branch above, and this plugin's guards correctly did nothing. Everything
 * about the logout was being diagnosed against a code path that never ran.
 *
 * ===========================================================================
 * WHY CORE IS RIGHT IN GENERAL AND WRONG HERE
 * ===========================================================================
 * In stock Magento the email address IS the sign-in username, so changing it
 * changes a credential and invalidating every session is exactly correct.
 *
 * It is not the username here. Spartrak_CustomerAuth signs customers in with
 * PHONE + password, and a phone-registered account may not have a real email
 * at all (Model\Customer\PlaceholderEmail). Changing it changes no credential.
 *
 * ===========================================================================
 * WHAT IS DELIBERATELY NOT UNDONE
 * ===========================================================================
 *   THE PASSWORD CHECK. Untouched. core still refuses the change without the
 *   account's current password, so the actor has proved knowledge of the
 *   credential before this plugin does anything.
 *
 *   OTHER DEVICES STAY SIGNED OUT. See keepVisitorPastSessionCutoff(): exactly
 *   one visitor record is moved past the watermark — this browser's. Every
 *   other session the customer has is still destroyed on its next request.
 *
 *   A PASSWORD CHANGE STILL SIGNS YOU OUT. beforeExecute() stands down the
 *   moment `change_password` is present. That IS a credential change.
 *
 *   A LOCKED OR NEWLY UNCONFIRMED ACCOUNT STAYS SIGNED OUT. Both guarded in
 *   afterExecute().
 */
class KeepSessionAfterEmailChange
{
    /**
     * The signed-in customer as they were BEFORE core ran, captured because by
     * the time afterExecute() is reached the session no longer has an id.
     */
    private ?int $customerId = null;

    /**
     * Whether this request is an email-only change worth inspecting at all.
     */
    private bool $applies = false;

    /**
     * The confirmation key the row held BEFORE core ran.
     *
     * "Awaiting confirmation" is only a reason to stay signed out if THIS
     * change caused it. On a store with "Require Emails Confirmation" on, an
     * unconfirmed account carries a key for its whole life, so a bare non-null
     * test would fire on every save and reinstate the bug this removes.
     */
    private ?string $confirmationBefore = null;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly CustomerSession $session,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly AuthenticationInterface $authentication,
        private readonly Visitor $visitor,
        private readonly VisitorResource $visitorResource,
        private readonly CustomerResource $customerResource,
        private readonly SessionManagerInterface $visitorSession
    ) {
    }

    /**
     * Records who is signed in, and whether this is a case to act on.
     */
    public function beforeExecute(EditPost $subject): void
    {
        $this->customerId = null;
        $this->applies = false;

        if (!$this->request->isPost()) {
            return;
        }

        // What core requires before it will write the field at all
        // (populateNewCustomerDataObject restores the stored address without
        // it), so its absence means no email was changed.
        if (!$this->request->getParam('change_email')) {
            return;
        }

        // A credential change. core's logout is correct; stand down.
        if ($this->request->getParam('change_password')) {
            return;
        }

        $customerId = (int) $this->session->getCustomerId();

        if ($customerId <= 0) {
            return;
        }

        $this->customerId = $customerId;
        $this->applies = true;

        try {
            // The same repository call core's execute() makes on the next line,
            // and the repository memoises by id — the same loaded instance
            // rather than a second query.
            $this->confirmationBefore = $this->customerRepository->getById($customerId)->getConfirmation();
        } catch (NoSuchEntityException | LocalizedException $e) {
            $this->confirmationBefore = null;
        }
    }

    /**
     * Re-establishes the session and sends the shopper back to their account.
     *
     * @param EditPost $subject
     * @param Redirect|mixed $result
     * @return Redirect|mixed
     */
    public function afterExecute(EditPost $subject, $result)
    {
        if (!$this->applies || $this->customerId === null || !$result instanceof Redirect) {
            return $result;
        }

        // Still signed in means core never reached its logout branch — the save
        // failed and core is already redirecting back to the form with its own
        // error. Nothing to do, and nothing to repair: clearFor() only runs
        // once the password has been accepted, and a rejected password throws
        // before it.
        if ($this->session->isLoggedIn()) {
            return $result;
        }

        if (!$this->restoreSession()) {
            /*
             * THE ONE THING THAT MUST NOT BE LEFT ALONE IS CORE'S REDIRECT.
             *
             * Standing down here would return the shopper to
             * customer/account/login, and on this storefront that is the
             * redirect LOOP described in the header, not a page. So a session
             * that could not be re-established lands on the store's home page
             * instead: signed out, carrying core's own success message, and
             * able to sign in from the header like anyone else.
             *
             * Reached only when this plugin has DECIDED the shopper should stay
             * signed out — a locked account, or a confirmation this change
             * introduced — or when the customer row cannot be loaded at all.
             */
            return $result->setPath('');
        }

        // WHERE they land. The stored value decides it, not the posted one: a
        // save that silently normalised the address should still be treated as
        // the success core reported it to be.
        return $result->setPath('customer/account');
    }

    /**
     * Signs the customer back in, unless they are meant to stay signed out.
     *
     * @return bool whether the session is now usable
     */
    private function restoreSession(): bool
    {
        try {
            $customer = $this->customerRepository->getById($this->customerId);

            // Locked out: core's logout is correct.
            if ($this->authentication->isLocked($this->customerId)) {
                return false;
            }

            // Awaiting confirmation BECAUSE OF THIS CHANGE — the shopper must
            // act on the new address before using the account, and core's
            // message says so. Compared against the captured value, not merely
            // tested for non-null; see $confirmationBefore.
            $confirmationNow = $customer->getConfirmation();

            if ($confirmationNow !== null && $confirmationNow !== $this->confirmationBefore) {
                return false;
            }

            $this->session->setCustomerDataAsLoggedIn($customer);
            // A fresh id, for the same reason core's own login path regenerates
            // one: the pre-logout id must not stay usable.
            $this->session->regenerateId();
        } catch (NoSuchEntityException | LocalizedException $e) {
            // Nothing can be re-established for a customer that cannot be
            // loaded. Nothing is swallowed that would otherwise have been shown.
            return false;
        }

        $this->keepVisitorPastSessionCutoff();

        return true;
    }

    /**
     * Moves THIS browser's visitor record past the session cutoff core wrote.
     *
     * WHY IT IS NEEDED AT ALL: sessionCleaner->clearFor() does not delete other
     * sessions — it cannot, they are other processes' storage. It stamps
     * customer_entity.session_cutoff and lets every session invalidate ITSELF,
     * because Magento_Customer's di.xml adds CutoffValidator to the customer
     * session's CompositeValidator and that runs on every request:
     *
     *     if ($cutoff > $created) { $session->destroy(); throw SessionException }
     *
     * So a session restored in PHP is signed back out the moment the shopper
     * loads anything else, and var/log/system.log fills with "The session has
     * expired, please login again." core never trips over its own watermark
     * because it forces a fresh sign-in, and a fresh sign-in INSERTS a new
     * customer_visitor row that post-dates the cutoff.
     *
     * IT IS NOT A WORKAROUND: clearFor() performs this very write itself, for
     * this very reason, in its own second statement — it spares the requesting
     * browser. This finishes that job for the browser signed back in here.
     *
     * DERIVED FROM THE WATERMARK, NOT FROM A CLOCK. Reading `now` from PHP puts
     * a second clock into the comparison and hopes the two agree: the cutoff is
     * written by PHP, but a visitor record created by Visitor::initByRequest()
     * takes its created_at from MySQL's CURRENT_TIMESTAMP default. Whether those
     * agree is an environment fact. findSessionCutOff() returns exactly what the
     * validator compares, and updateCreatedAt() stores through the same
     * formatDate() that strtotime() reverses — so `cutoff + 1` lands one second
     * past the watermark in whatever frame the column round-trips in, and
     * `cutoff > created_at` is false by construction rather than by luck.
     *
     * IT ALSO LEAVES A FINGERPRINT: a repaired record reads exactly one second
     * after the customer's cutoff, so "did this run" is answerable from the two
     * columns with no logging.
     *
     * WHAT IT DOES NOT DO IS WEAKEN THE CLEAR-OUT. One record moves: this
     * browser's. Every other device keeps its created_at behind the cutoff and
     * is still destroyed on its next request.
     */
    private function keepVisitorPastSessionCutoff(): void
    {
        if ($this->customerId === null) {
            return;
        }

        $cutoff = $this->customerResource->findSessionCutOff($this->customerId);

        if ($cutoff === null) {
            // No watermark standing, so there is nothing to outrun.
            return;
        }

        foreach ($this->visitorIdsToKeep() as $visitorId) {
            $this->visitorResource->updateCreatedAt($visitorId, $cutoff + 1);
        }
    }

    /**
     * The visitor record ids belonging to THIS browser, de-duplicated.
     *
     * Both sources are read because either can legitimately be the only one
     * populated here. The VALIDATOR takes its id from the visitor session, so
     * that copy is authoritative — but core destroyed the session during
     * logout() and it is Visitor::saveByRequest(), an observer on
     * controller_action_postdispatch, that puts it back after this runs. The
     * Visitor model is the same singleton that observer will save, so it
     * answers for that gap.
     *
     * Empty is a valid answer needing no repair: with no visitor record in the
     * session, CutoffValidator returns without comparing anything — its own
     * guard requires both visitor_id and customer_id to be present.
     *
     * @return int[]
     */
    private function visitorIdsToKeep(): array
    {
        $ids = [];
        $visitor = $this->visitorSession->getVisitorData();

        if (is_array($visitor) && isset($visitor['visitor_id']) && (int) $visitor['visitor_id'] > 0) {
            $ids[(int) $visitor['visitor_id']] = (int) $visitor['visitor_id'];
        }

        $modelId = (int) $this->visitor->getId();

        if ($modelId > 0) {
            $ids[$modelId] = $modelId;
        }

        return $ids;
    }
}
