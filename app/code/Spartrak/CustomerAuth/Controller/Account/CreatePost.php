<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Controller\Account;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Spartrak\CustomerAuth\Controller\AbstractJsonAction;
use Spartrak\CustomerAuth\Exception\FieldValidationException;
use Spartrak\CustomerAuth\Model\Customer\Authenticator;
use Spartrak\CustomerAuth\Model\Customer\Registrar;
use Spartrak\CustomerAuth\Model\Phone\Normalizer;

/**
 * POST /phone-auth/account/createPost
 *
 * Body: phone, verification_token, firstname, lastname, password,
 *       password_confirmation, form_key
 *
 * The final step of the signup flow. Authorization comes entirely from the
 * verification_token — there is no session to check and nothing else in the body
 * is trusted. The token is redeemed inside Registrar::register() before any write
 * happens, so an unverified or replayed request never reaches account creation.
 *
 * FIELD SET is settled by the business requirement now, not inferred from the
 * design. Three changes from the earlier contract, all deliberate:
 *
 *   - `name` became `firstname` + `lastname`. The single field forced Registrar
 *     to GUESS the split on the last space, which copied a one-word name into
 *     both columns. The form collects them separately, so the guess is gone.
 *   - `email` is no longer read at all. Registration must never ask for one, and
 *     the address is synthesized from the phone number inside Registrar. Not
 *     reading the field beats ignoring it: a posted `email` can no longer reach
 *     account identity even by accident.
 *   - `password_confirmation` is REQUIRED. It used to be validated only when
 *     non-empty, so a request that omitted it passed silently.
 */
class CreatePost extends AbstractJsonAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        Normalizer $phoneNormalizer,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        RemoteAddress $remoteAddress,
        private readonly Registrar $registrar,
        private readonly Authenticator $authenticator,
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
        $firstName = $this->getPostString('firstname');
        $lastName = $this->getPostString('lastname');
        $password = $this->getPostString('password');
        $confirmation = $this->getPostString('password_confirmation');

        if ($firstName === '') {
            throw new FieldValidationException(__('Please enter your first name.'), 'firstname');
        }

        if ($lastName === '') {
            throw new FieldValidationException(__('Please enter your last name.'), 'lastname');
        }

        if ($password === '') {
            throw new FieldValidationException(__('Please enter a password.'), 'password');
        }

        // Confirmation is REQUIRED, not "validated when present". The previous
        // `$confirmation !== '' && ...` guard meant a request that simply omitted
        // the field passed silently, so anything posting directly to this
        // endpoint skipped the check the form appears to enforce.
        if ($confirmation === '') {
            throw new FieldValidationException(
                __('Please confirm your password.'),
                'password_confirmation'
            );
        }

        // Not hash_equals: both values arrived in the same request body, so there
        // is no secret to leak by timing, and !== gives a far clearer error path.
        if ($password !== $confirmation) {
            throw new FieldValidationException(
                __('The passwords do not match.'),
                'password_confirmation'
            );
        }

        // Length/strength/complexity is NOT validated here. AccountManagement
        // enforces the store's configured password policy during createAccount()
        // and throws InputException with the right message. A second check here
        // would be a second source of truth that silently disagrees with admin
        // configuration the day someone changes the minimum length.
        $customer = $this->registrar->register($phone, $token, $firstName, $lastName, $password);

        // The shopper just proved the number and chose the password; making them
        // sign in again would be pure friction.
        $this->authenticator->startSession($customer);

        return [
            'customer_name' => trim(($customer->getFirstname() ?? '') . ' ' . ($customer->getLastname() ?? '')),
            // Session id was regenerated by startSession(); see LoginPost.
            'form_key' => $this->formKey->getFormKey(),
            'message' => (string) __('Your account is ready.'),
        ];
    }
}
