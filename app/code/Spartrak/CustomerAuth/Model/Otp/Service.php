<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Model\Otp;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;
use Spartrak\CustomerAuth\Exception\OtpVerificationException;
use Spartrak\CustomerAuth\Exception\SmsDeliveryException;
use Spartrak\CustomerAuth\Model\Config;
use Spartrak\CustomerAuth\Model\OtpRequestFactory;
use Spartrak\CustomerAuth\Model\Phone\Normalizer;
use Spartrak\CustomerAuth\Model\ResourceModel\OtpRequest as OtpRequestResource;
use Spartrak\CustomerAuth\Model\Sms\GatewayResolver;

/**
 * OTP lifecycle: issue -> deliver -> verify -> redeem.
 *
 * The only class the rest of the module (and the controllers) talk to for
 * anything OTP-shaped.
 *
 * Design rules enforced here, each because the obvious alternative is unsafe:
 *
 *  1. The code is never stored, only a salted one-way hash. A database dump, a
 *     leaked backup or a curious admin cannot recover a live code.
 *
 *  2. Issuing a code revokes every older live code for that number+purpose, so
 *     exactly one code is ever valid. Otherwise "resend" would multiply the
 *     number of codes an attacker may guess against.
 *
 *  3. Verification does not return "you are logged in" — it returns a
 *     single-use, short-lived proof token. That keeps the two flows that consume
 *     it (create account, reset password) stateless with respect to session
 *     state, and means a verified OTP cannot be replayed into a different flow
 *     than the one it was requested for.
 *
 *  4. A gateway failure revokes the row it just created. Leaving it pending
 *     would charge the shopper a cooldown for a code that was never sent — the
 *     single most infuriating possible failure mode in this flow.
 */
class Service
{
    public function __construct(
        private readonly Config $config,
        private readonly CodeGenerator $codeGenerator,
        private readonly RateLimiter $rateLimiter,
        private readonly OtpRequestFactory $otpRequestFactory,
        private readonly OtpRequestResource $otpRequestResource,
        private readonly GatewayResolver $gatewayResolver,
        private readonly EncryptorInterface $encryptor,
        private readonly DateTime $dateTime,
        private readonly Normalizer $normalizer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Issue a code for $phone and hand it to the SMS gateway.
     *
     * @param string      $phone     E.164, already normalized by the caller.
     * @param string      $purpose   Purpose::* constant.
     * @param string|null $ipAddress Requesting IP for the per-IP quota.
     *
     * @return array{expires_in:int, resend_in:int, code_length:int, real_delivery:bool}
     *         Everything the storefront needs to render the OTP step. Note there
     *         is no code in here — deliberately, so no future refactor can
     *         accidentally serialize it into a response body.
     *
     * @throws \Spartrak\CustomerAuth\Exception\RateLimitExceededException
     * @throws SmsDeliveryException
     */
    public function issue(string $phone, string $purpose, ?string $ipAddress, int $storeId = 0): array
    {
        Purpose::assertValid($purpose);
        $this->rateLimiter->assertCanSend($phone, $purpose, $ipAddress, $storeId);

        $code = $this->codeGenerator->generateCode($storeId);
        $ttl = $this->config->getCodeTtlSeconds($storeId);

        // Rule 2: exactly one live code per number+purpose.
        $this->otpRequestResource->revokeOpenRequests($phone, $purpose);

        $request = $this->otpRequestFactory->create();
        $request->addData([
            'phone' => $phone,
            'purpose' => $purpose,
            // Rule 1: salted one-way hash, never the code.
            'code_hash' => $this->encryptor->getHash($code, true),
            'attempts' => 0,
            'status' => Status::PENDING,
            'ip_address' => $ipAddress,
            'store_id' => $storeId,
            'expires_at' => $this->dateTime->gmtDate('Y-m-d H:i:s', $this->now() + $ttl),
        ]);
        $this->otpRequestResource->save($request);

        $gateway = $this->gatewayResolver->resolve($storeId);

        try {
            $gateway->send($phone, $this->renderMessage($code, $purpose, $ttl), $this->config->getSmsSenderName($storeId));
        } catch (\Throwable $e) {
            // Rule 4. Marked UNDELIVERED rather than REVOKED, and by id rather
            // than by phone+purpose: the row must stop counting toward the
            // resend cooldown (nothing arrived, so there is nothing to wait for)
            // while still counting toward the send quotas (or a permanently
            // failing gateway could be hammered without limit). Targeting the id
            // also means a concurrent request for the same number is not
            // collaterally killed by this one's failure.
            $this->otpRequestResource->markUndelivered((int) $request->getId());

            $this->logger->error(
                sprintf(
                    'Spartrak_CustomerAuth: SMS send failed for %s via gateway "%s": %s',
                    $this->normalizer->mask($phone),
                    $gateway->getCode(),
                    $e->getMessage()
                ),
                ['exception' => $e]
            );

            throw new SmsDeliveryException(
                __('We could not send your verification code right now. Please try again in a moment.'),
                $e
            );
        }

        return [
            'expires_in' => $ttl,
            'resend_in' => $this->config->getResendCooldownSeconds($storeId),
            'code_length' => $this->config->getCodeLength($storeId),
            'real_delivery' => $gateway->isRealDelivery(),
        ];
    }

    /**
     * The metadata issue() *would* return, without issuing anything.
     *
     * Exists so the send endpoint can answer a password-reset request for an
     * unregistered number with a byte-for-byte plausible success payload. The
     * values come from the same config reads the real path uses, so the decoy
     * cannot drift away from the genuine response when settings change — which
     * is exactly how this kind of anti-enumeration measure usually rots.
     *
     * @return array{expires_in:int, resend_in:int, code_length:int, real_delivery:bool}
     */
    public function describeIssuePlan(int $storeId = 0): array
    {
        return [
            'expires_in' => $this->config->getCodeTtlSeconds($storeId),
            'resend_in' => $this->config->getResendCooldownSeconds($storeId),
            'code_length' => $this->config->getCodeLength($storeId),
            'real_delivery' => $this->gatewayResolver->resolve($storeId)->isRealDelivery(),
        ];
    }

    /**
     * Check a submitted code and, on success, issue a single-use proof token.
     *
     * @return string The proof token. Give it to the client; it is the only
     *                thing that authorizes the follow-up step.
     *
     * @throws OtpVerificationException on any failure. One exception type for
     *         every cause, on purpose — see that class for why.
     */
    public function verify(string $phone, string $purpose, string $submittedCode, int $storeId = 0): string
    {
        Purpose::assertValid($purpose);

        $row = $this->otpRequestResource->loadNewestPending($phone, $purpose);

        if ($row === null) {
            throw new OtpVerificationException(
                __('That code is not valid. Please request a new one.')
            );
        }

        if ($this->isExpired((string) ($row['expires_at'] ?? ''))) {
            $this->otpRequestResource->revokeOpenRequests($phone, $purpose);

            throw new OtpVerificationException(
                __('That code has expired. Please request a new one.')
            );
        }

        $submittedCode = $this->sanitizeSubmittedCode($submittedCode);

        if (!$this->encryptor->validateHash($submittedCode, (string) $row['code_hash'])) {
            $maxAttempts = $this->config->getMaxVerifyAttempts($storeId);
            $attempts = $this->otpRequestResource->registerFailedAttempt((int) $row['request_id'], $maxAttempts);
            $remaining = max(0, $maxAttempts - $attempts);

            $message = $remaining > 0
                ? __('That code is incorrect. %1 attempts remaining.', $remaining)
                : __('Too many incorrect attempts. Please request a new code.');

            throw (new OtpVerificationException($message))->setAttemptsRemaining($remaining);
        }

        return $this->issueProofToken((int) $row['request_id'], $storeId);
    }

    /**
     * Redeem a proof token, asserting it was issued for $phone and $purpose.
     *
     * Single-use: the row moves to CONSUMED before this returns, so the same
     * token cannot create two accounts or reset a password twice. The phone and
     * purpose are re-checked here rather than trusted from the request, which is
     * what stops a token earned on a signup flow being spent on a password
     * reset for a different number.
     *
     * @throws OtpVerificationException
     */
    public function redeemProofToken(string $token, string $phone, string $purpose): void
    {
        Purpose::assertValid($purpose);

        $token = trim($token);

        if ($token === '') {
            throw new OtpVerificationException(__('Please verify your phone number first.'));
        }

        $row = $this->otpRequestResource->loadByTokenHash($this->codeGenerator->hashProofToken($token));

        if ($row === null) {
            throw new OtpVerificationException(
                __('Your verification has expired. Please verify your phone number again.')
            );
        }

        // hash_equals on the phone comparison as well: both values are secrets
        // in the sense that a timing difference would let a caller probe which
        // number a token belongs to.
        $matchesPhone = hash_equals((string) $row['phone'], $phone);
        $matchesPurpose = hash_equals((string) $row['purpose'], $purpose);

        if (!$matchesPhone || !$matchesPurpose) {
            $this->logger->warning(
                sprintf(
                    'Spartrak_CustomerAuth: proof token presented for the wrong %s (token row #%d).',
                    $matchesPhone ? 'purpose' : 'phone number',
                    (int) $row['request_id']
                )
            );

            throw new OtpVerificationException(
                __('Your verification has expired. Please verify your phone number again.')
            );
        }

        if ($this->isExpired((string) ($row['token_expires_at'] ?? ''))) {
            $this->markConsumed((int) $row['request_id']);

            throw new OtpVerificationException(
                __('Your verification has expired. Please verify your phone number again.')
            );
        }

        $this->markConsumed((int) $row['request_id']);
    }

    /**
     * Seconds until another code may be requested. Display data; never throws.
     */
    public function getResendDelay(string $phone, string $purpose, int $storeId = 0): int
    {
        return $this->rateLimiter->getSecondsUntilNextSend($phone, $purpose, $storeId);
    }

    private function issueProofToken(int $requestId, int $storeId): string
    {
        $token = $this->codeGenerator->generateProofToken();
        $ttl = $this->config->getProofTokenTtlSeconds($storeId);

        $request = $this->otpRequestFactory->create();
        $this->otpRequestResource->load($request, $requestId);
        $request->addData([
            'status' => Status::VERIFIED,
            'token_hash' => $this->codeGenerator->hashProofToken($token),
            'token_expires_at' => $this->dateTime->gmtDate('Y-m-d H:i:s', $this->now() + $ttl),
        ]);
        $this->otpRequestResource->save($request);

        return $token;
    }

    private function markConsumed(int $requestId): void
    {
        $request = $this->otpRequestFactory->create();
        $this->otpRequestResource->load($request, $requestId);
        $request->setData('status', Status::CONSUMED);
        // Clear the digest so the row cannot be matched again even if the status
        // check were ever weakened by a later change.
        $request->setData('token_hash', null);

        try {
            $this->otpRequestResource->save($request);
        } catch (LocalizedException $e) {
            // A token that cannot be marked spent is a replay window, so this is
            // loud rather than swallowed.
            $this->logger->critical(
                sprintf('Spartrak_CustomerAuth: failed to consume OTP row #%d: %s', $requestId, $e->getMessage()),
                ['exception' => $e]
            );
        }
    }

    /**
     * Strip anything the shopper's keyboard or a paste added.
     *
     * Auto-filled SMS codes on Android arrive with surrounding whitespace, and
     * Arabic keyboards submit Arabic-Indic digits — both are the shopper doing
     * nothing wrong, and both would fail a raw hash comparison.
     */
    private function sanitizeSubmittedCode(string $submittedCode): string
    {
        $latin = $this->normalizer->toDigits($this->convertNonLatinDigits($submittedCode));

        return $latin;
    }

    private function convertNonLatinDigits(string $value): string
    {
        return str_replace(
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $value
        );
    }

    private function isExpired(string $utcDateTime): bool
    {
        if ($utcDateTime === '') {
            return true;
        }

        return strtotime($utcDateTime . ' UTC') <= $this->now();
    }

    private function now(): int
    {
        return (int) $this->dateTime->gmtTimestamp();
    }

    /**
     * The SMS body.
     *
     * Includes the store's sender name and the expiry in minutes, and no link.
     * A link in an OTP message is a phishing trainer: it teaches the shopper
     * that tapping a URL in an SMS about their account is normal.
     */
    private function renderMessage(string $code, string $purpose, int $ttlSeconds): string
    {
        $minutes = (int) max(1, round($ttlSeconds / 60));

        if ($purpose === Purpose::PASSWORD_RESET) {
            return (string) __(
                'SpareTrak: %1 is your password reset code. It expires in %2 minutes. If you did not request this, ignore this message.',
                $code,
                $minutes
            );
        }

        return (string) __(
            'SpareTrak: %1 is your verification code. It expires in %2 minutes. Never share it with anyone.',
            $code,
            $minutes
        );
    }
}
