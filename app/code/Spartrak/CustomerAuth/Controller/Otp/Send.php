<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Controller\Otp;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\State\InputMismatchException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Spartrak\CustomerAuth\Controller\AbstractJsonAction;
use Spartrak\CustomerAuth\Model\Customer\PhoneLocator;
use Spartrak\CustomerAuth\Model\Otp\Purpose;
use Spartrak\CustomerAuth\Model\Otp\Service as OtpService;
use Spartrak\CustomerAuth\Model\Phone\Normalizer;

/**
 * POST /phone-auth/otp/send
 *
 * Body: phone, purpose (signup|password_reset), form_key
 *
 * The two purposes get deliberately asymmetric treatment, and the asymmetry is
 * the interesting part of this class:
 *
 *   SIGNUP tells the caller when a number is already registered. That is an
 *   account-existence disclosure, and it is accepted on purpose — the shopper
 *   genuinely needs "you already have an account, sign in instead", and hiding it
 *   produces a dead end where registration appears to succeed and then cannot.
 *   The rate limiter is what makes this an acceptable trade rather than a free
 *   enumeration API.
 *
 *   PASSWORD_RESET tells the caller nothing. An unregistered number gets the
 *   exact same response as a registered one, with the same shape and the same
 *   timing-relevant work skipped only after the response is decided. Here the
 *   disclosure has no upside for a legitimate shopper (someone resetting a
 *   password believes they have an account) and a clear downside: it would turn
 *   this endpoint into "is this phone number a SpareTrak customer?", answerable
 *   for any number in Egypt.
 */
class Send extends AbstractJsonAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        Normalizer $phoneNormalizer,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        RemoteAddress $remoteAddress,
        private readonly OtpService $otpService,
        private readonly PhoneLocator $phoneLocator
    ) {
        parent::__construct($context, $jsonFactory, $phoneNormalizer, $storeManager, $logger, $remoteAddress);
    }

    /**
     * @inheritDoc
     */
    protected function handle(): array
    {
        $phone = $this->getPostedPhone();
        $purpose = Purpose::assertValid($this->getPostString('purpose'));
        $storeId = $this->getStoreId();
        $isRegistered = $this->phoneLocator->isPhoneRegistered($phone);

        if ($purpose === Purpose::SIGNUP && $isRegistered) {
            throw new InputMismatchException(
                __('An account already exists for this phone number. Please sign in instead.')
            );
        }

        if ($purpose === Purpose::PASSWORD_RESET && !$isRegistered) {
            // Nothing is sent and no row is written, but the response is
            // indistinguishable from the success path so the caller cannot
            // learn whether the number exists.
            //
            // Rate limiting still applies to the real path, so this branch
            // cannot be used to bypass the quota either — it never reaches the
            // gateway.
            $this->logger->info(
                sprintf(
                    'Spartrak_CustomerAuth: password-reset code requested for unregistered number %s. '
                    . 'Responding as success (no disclosure); nothing sent.',
                    $this->phoneNormalizer->mask($phone)
                )
            );

            return $this->decoyResponse($storeId);
        }

        $result = $this->otpService->issue($phone, $purpose, $this->getClientIp(), $storeId);

        return [
            'expires_in' => $result['expires_in'],
            'resend_in' => $result['resend_in'],
            'code_length' => $result['code_length'],
            // Lets a staging storefront say "SMS is not configured, check the
            // log" instead of leaving a tester waiting for a message that will
            // never arrive. Never true in production.
            'real_delivery' => $result['real_delivery'],
            'message' => (string) __('We sent a verification code to your phone.'),
        ];
    }

    /**
     * Success-shaped response for a number with no account.
     *
     * Mirrors the real payload field for field, using the same configured values
     * the genuine path would have returned, so neither the body nor its size
     * distinguishes the two.
     *
     * @return array<string, mixed>
     */
    private function decoyResponse(int $storeId): array
    {
        $reference = $this->otpService->describeIssuePlan($storeId);

        return [
            'expires_in' => $reference['expires_in'],
            'resend_in' => $reference['resend_in'],
            'code_length' => $reference['code_length'],
            'real_delivery' => $reference['real_delivery'],
            'message' => (string) __('We sent a verification code to your phone.'),
        ];
    }
}
