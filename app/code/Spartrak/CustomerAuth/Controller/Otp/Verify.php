<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Controller\Otp;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Spartrak\CustomerAuth\Controller\AbstractJsonAction;
use Spartrak\CustomerAuth\Model\Otp\Purpose;
use Spartrak\CustomerAuth\Model\Otp\Service as OtpService;
use Spartrak\CustomerAuth\Model\Phone\Normalizer;

/**
 * POST /phone-auth/otp/verify
 *
 * Body: phone, purpose, code, form_key
 *
 * Returns a single-use proof token, NOT a session. Verifying a code proves the
 * shopper controls the handset; it does not by itself say which account they are
 * entitled to, or that an account exists at all. Handing back a session here
 * would make "receive an SMS" sufficient to be logged in, which is the whole
 * reason the password step exists in the chosen auth model.
 *
 * The token goes to the client and comes back on the next request. That is a
 * bearer token, so it is short-lived (config, default 15 min), single-use, bound
 * to this phone AND this purpose, and stored server-side only as a digest.
 */
class Verify extends AbstractJsonAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        Normalizer $phoneNormalizer,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        RemoteAddress $remoteAddress,
        private readonly OtpService $otpService
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
        $code = $this->getPostString('code');

        $proofToken = $this->otpService->verify($phone, $purpose, $code, $this->getStoreId());

        return [
            'verification_token' => $proofToken,
            'next_step' => $purpose === Purpose::SIGNUP ? 'registration' : 'set_password',
            'message' => (string) __('Your phone number is verified.'),
        ];
    }
}
