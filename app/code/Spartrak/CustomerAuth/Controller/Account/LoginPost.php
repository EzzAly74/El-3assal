<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Controller\Account;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Exception\InvalidEmailOrPasswordException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Spartrak\CustomerAuth\Controller\AbstractJsonAction;
use Spartrak\CustomerAuth\Model\Customer\Authenticator;
use Spartrak\CustomerAuth\Model\Phone\Normalizer;

/**
 * POST /phone-auth/account/loginPost
 *
 * Body: phone, password, form_key
 *
 * The returning-shopper path in the chosen auth model: phone + password, no SMS.
 * That is the whole reason the model was chosen — an OTP on every sign-in bills
 * the store for a message each time and adds a 30-second wait for the farmers and
 * mechanics this storefront is for.
 */
class LoginPost extends AbstractJsonAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        Normalizer $phoneNormalizer,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        RemoteAddress $remoteAddress,
        private readonly Authenticator $authenticator,
        private readonly CustomerSession $customerSession,
        private readonly FormKey $formKey
    ) {
        parent::__construct($context, $jsonFactory, $phoneNormalizer, $storeManager, $logger, $remoteAddress);
    }

    /**
     * @inheritDoc
     */
    protected function handle(): array
    {
        if ($this->customerSession->isLoggedIn()) {
            // Idempotent rather than an error: a double-submitted modal, or a
            // second tab, should not produce a scary failure on a shopper who is
            // already signed in.
            return [
                'already_signed_in' => true,
                'form_key' => $this->formKey->getFormKey(),
                'message' => (string) __('You are already signed in.'),
            ];
        }

        $phone = $this->normalizePhoneWithoutDisclosure();
        $password = $this->getPostString('password');

        if ($password === '') {
            throw new InvalidEmailOrPasswordException(
                __('The phone number or password is incorrect.')
            );
        }

        $customer = $this->authenticator->login($phone, $password);

        return [
            'customer_name' => trim(($customer->getFirstname() ?? '') . ' ' . ($customer->getLastname() ?? '')),
            // The session id was regenerated during sign-in, so the client's
            // cached form key is stale. Returning the new one keeps a modal-based
            // flow working without a page reload — the alternative is the
            // shopper's next POST failing an "Invalid Form Key" check, which is
            // one of the classic symptoms of AJAX auth done without this.
            'form_key' => $this->formKey->getFormKey(),
            'message' => (string) __('Signed in successfully.'),
        ];
    }

    /**
     * Normalize the posted number without letting the format error distinguish
     * "malformed" from "no such account".
     *
     * A malformed number obviously has no account, so reporting a format error
     * here would be harmless in isolation — but it makes the endpoint's error
     * vocabulary depend on the input in a way an attacker can measure. One
     * message for every failure keeps that surface flat.
     */
    private function normalizePhoneWithoutDisclosure(): string
    {
        $phone = $this->phoneNormalizer->normalizeOrNull($this->getPostString('phone'));

        if ($phone === null) {
            throw new InvalidEmailOrPasswordException(
                __('The phone number or password is incorrect.')
            );
        }

        return $phone;
    }
}
