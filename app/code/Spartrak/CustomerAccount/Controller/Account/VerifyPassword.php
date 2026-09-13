<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAccount\Controller\Account;

use Magento\Customer\Model\AuthenticationInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\InvalidEmailOrPasswordException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\State\UserLockedException;

/**
 * Answers one question for the account card's confirm dialog: is this the
 * signed-in customer's current password?
 *
 * ===========================================================================
 * WHY THIS EXISTS
 * ===========================================================================
 * The card collects the current password in a dialog, because core will not
 * change an email address without it and Figma draws no password field on the
 * card itself (see Magento_Customer::form/edit.phtml).
 *
 * That dialog used to close and submit the whole card the moment its Save was
 * pressed, and it did that WITHOUT KNOWING whether the password was right.
 * Reported from production: "once i click save popup dismissed even if i enter
 * wrong password". What the shopper actually got was a full page POST, core's
 * refusal, a redirect back to a freshly rendered edit page, and their typing
 * gone — for a mistake that could have been caught before anything was sent.
 *
 * So the dialog asks here first. On a wrong password the dialog stays open with
 * an inline error and the card is never submitted; on a right one the card
 * submits exactly as before.
 *
 * ===========================================================================
 * WHAT IT IS NOT
 * ===========================================================================
 * IT IS NOT A SECOND WAY TO AUTHORISE THE CHANGE. It returns a boolean and
 * writes nothing — no session flag, no token, no "verified" state. core's
 * EditPost still authenticates the password itself on the real submit
 * (processChangeEmailRequest), so the save is authorised by exactly the check
 * it has always been authorised by. Removing this endpoint would cost the
 * shopper a good error message and nothing else.
 *
 * That is deliberate. A pre-check that GRANTED anything would be a new
 * credential path to keep in step with core's, and the one thing worse than a
 * dialog with a bad error message is two places that decide whether a password
 * is acceptable.
 *
 * ===========================================================================
 * WHY IT IS NOT A PASSWORD ORACLE
 * ===========================================================================
 * Every guess costs the attacker exactly what a guess at the real form costs,
 * because it is the same call:
 *
 *   AuthenticationInterface::authenticate() applies Magento's own lockout
 *   policy — processAuthenticationFailure() increments the failure counter and
 *   locks the account at `customer/password/lockout_failures`. So a brute force
 *   here trips the same lock, at the same threshold, as one against
 *   customer/account/loginPost.
 *
 *   It only ever answers about the ALREADY SIGNED-IN customer, taken from the
 *   session and never from the request. There is no parameter that selects an
 *   account, so it cannot be pointed at somebody else's.
 *
 *   The form key is required. This implements HttpPostActionInterface and not
 *   CsrfAwareActionInterface, so Magento\Framework\App\Request\CsrfValidator
 *   validates the key on every POST (CLAUDE.md section 17) — a page on another
 *   origin cannot drive it.
 *
 * The messages are core's own wording for the same failures, so nothing is
 * disclosed here that the sign-in form does not already disclose.
 */
class VerifyPassword implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly CustomerSession $session,
        private readonly AuthenticationInterface $authentication
    ) {
    }

    /**
     * @return ResultInterface
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $customerId = (int) $this->session->getCustomerId();

        if (!$this->session->isLoggedIn() || $customerId <= 0) {
            // The dialog is only reachable from an authenticated page, so this
            // means the session went away underneath it. Say so plainly rather
            // than reporting a wrong password for a session problem.
            return $result->setHttpResponseCode(401)->setData([
                'valid' => false,
                'message' => (string) __('Your session has expired. Please sign in and try again.'),
            ]);
        }

        $password = $this->request->getParam('current_password');
        $password = is_string($password) ? $password : '';

        // NOT trimmed. A password's leading or trailing spaces are part of it,
        // and core hashes what was posted — trimming here would accept a value
        // the real submit then rejects.
        if ($password === '') {
            return $result->setData([
                'valid' => false,
                'message' => (string) __('Please enter your current password.'),
            ]);
        }

        try {
            $this->authentication->authenticate($customerId, $password);
        } catch (InvalidEmailOrPasswordException $e) {
            return $result->setData([
                'valid' => false,
                'message' => (string) __('The password is incorrect. Verify the password and try again.'),
            ]);
        } catch (UserLockedException $e) {
            return $result->setData([
                'valid' => false,
                'locked' => true,
                'message' => (string) __(
                    'The account is locked. Please wait and try again or contact customer support.'
                ),
            ]);
        } catch (LocalizedException $e) {
            // A translated, customer-safe message from the platform. Shown as
            // it is rather than replaced with a generic one, so a real
            // configuration problem is not disguised as a typing mistake.
            return $result->setData([
                'valid' => false,
                'message' => $e->getMessage(),
            ]);
        }

        return $result->setData(['valid' => true]);
    }
}
