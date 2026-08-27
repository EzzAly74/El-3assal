<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Controller\Password;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Spartrak\CustomerAuth\Controller\AbstractJsonAction;
use Spartrak\CustomerAuth\Exception\FieldValidationException;
use Spartrak\CustomerAuth\Model\Customer\PasswordResetter;
use Spartrak\CustomerAuth\Model\Phone\Normalizer;

/**
 * POST /phone-auth/password/resetPost
 *
 * Body: phone, verification_token, password, password_confirmation, form_key
 *
 * Completes the "forgot my password" flow: the shopper proved the number by OTP,
 * and now replaces the password. Authorization is the verification_token alone,
 * redeemed inside PasswordResetter before anything is written.
 *
 * Worth being explicit about why this is safe without a session: the token can
 * only exist if an SMS was delivered to the handset for that number, it is bound
 * to that number and to the password-reset purpose specifically, it expires, and
 * it works once. A token minted during signup cannot be spent here.
 */
class ResetPost extends AbstractJsonAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        Normalizer $phoneNormalizer,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        RemoteAddress $remoteAddress,
        private readonly PasswordResetter $passwordResetter,
        private readonly FormKey $formKey
    ) {
        parent::__construct($context, $jsonFactory, $phoneNormalizer, $storeManager, $logger, $remoteAddress);
    }

    /**
     * @inheritDoc
     */
    protected function handle(): array
    {
        $phone = $this->getPostedPhone();
        $token = $this->getPostString('verification_token');
        $password = $this->getPostString('password');
        $confirmation = $this->getPostString('password_confirmation');

        if ($password === '') {
            throw new FieldValidationException(__('Please enter a new password.'), 'password');
        }

        // Confirmation is REQUIRED. The previous `$confirmation !== '' && ...`
        // guard let a request that omitted the field through untouched, which on
        // a password-RESET endpoint means a typo'd password could be committed
        // with nothing to catch it — and the shopper is then locked out of the
        // account they were trying to recover.
        if ($confirmation === '') {
            throw new FieldValidationException(
                __('Please confirm your new password.'),
                'password_confirmation'
            );
        }

        if ($password !== $confirmation) {
            throw new FieldValidationException(
                __('The passwords do not match.'),
                'password_confirmation'
            );
        }

        // Strength policy and "must differ from the account email" are enforced
        // by core inside resetPassword(); not duplicated here on purpose.
        $customer = $this->passwordResetter->reset($phone, $token, $password);

        return [
            'customer_name' => trim(($customer->getFirstname() ?? '') . ' ' . ($customer->getLastname() ?? '')),
            'form_key' => $this->formKey->getFormKey(),
            // Worth surfacing: the reset evicted every other session for this
            // account, and a shopper who did not expect that should understand
            // why their phone suddenly asks them to sign in again.
            'message' => (string) __('Your password has been changed and you are signed in. Any other devices have been signed out.'),
        ];
    }
}
