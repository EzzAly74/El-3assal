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
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Spartrak\CustomerAuth\Controller\AbstractJsonAction;
use Spartrak\CustomerAuth\Exception\OtpVerificationException;
use Spartrak\CustomerAuth\Model\Customer\PhoneChanger;
use Spartrak\CustomerAuth\Model\Phone\Normalizer;

/**
 * POST /phone-auth/account/phonechange
 *
 * Body: phone, verification_token, form_key
 *
 * The third step of the flow Figma 821:17158 draws. The first two are the
 * existing generic endpoints — /phone-auth/otp/send and /phone-auth/otp/verify
 * with `purpose=phone_change` — because "send a code" and "check a code" mean
 * the same thing here as they do at signup, and duplicating them would give the
 * rate limiter and the attempt counter a second implementation to drift from.
 *
 * What is NOT generic is redemption, which is why this action exists: spending
 * the token has to say what it buys. Compare Controller\Password\ResetPost,
 * which is the same shape for the same reason.
 *
 * ===========================================================================
 * THE CUSTOMER ID COMES FROM THE SESSION
 * ===========================================================================
 * Never from the body. `phone_number` is the login identifier, so an endpoint
 * that accepted a customer id would let anyone holding a code for a number they
 * own point somebody else's account at it.
 *
 * ===========================================================================
 * THE SESSION SURVIVES THE CHANGE
 * ===========================================================================
 * Deliberately not regenerated or evicted. Nothing about the credential the
 * shopper authenticated with has changed — the password is untouched and this
 * request already carried a valid session and form key. Password reset DOES
 * evict other sessions (see ResetPost) because there the credential itself
 * changed and old sessions may belong to whoever knew the old one; that
 * reasoning does not transfer.
 *
 * The customer object in the session is refreshed, though, so the card behind
 * the modal re-renders with the new number instead of the old one.
 */
class PhoneChange extends AbstractJsonAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        Normalizer $phoneNormalizer,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        RemoteAddress $remoteAddress,
        private readonly PhoneChanger $phoneChanger,
        private readonly CustomerSession $customerSession
    ) {
        parent::__construct($context, $jsonFactory, $phoneNormalizer, $storeManager, $logger, $remoteAddress);
    }

    /**
     * @inheritDoc
     */
    protected function handle(): array
    {
        if (!$this->customerSession->isLoggedIn()) {
            // 403, via AbstractJsonAction's OtpVerificationException arm. See
            // Controller\Otp\Send::assertPhoneChangeAllowed() for why this is
            // not a FieldValidationException.
            throw new OtpVerificationException(
                __('Please sign in before changing the phone number on your account.')
            );
        }

        $phone = $this->getPostedPhone();
        $token = $this->getPostString('verification_token');

        $customer = $this->phoneChanger->change(
            (int) $this->customerSession->getCustomerId(),
            $phone,
            $token
        );

        // The session caches a customer model; without this the page behind the
        // modal keeps printing the old number until the next request rebuilds it.
        $this->customerSession->setCustomerData($customer);
        $this->customerSession->setCustomerGroupId($customer->getGroupId());

        return [
            // Echoed back so the card can repaint without a reload. Masked is
            // not wanted here — the shopper just typed it and is being shown
            // what was saved.
            'phone' => $phone,
            'phone_national' => $this->phoneNormalizer->toLocalParts($phone)['national'],
            'message' => (string) __('Your phone number has been updated.'),
        ];
    }
}
