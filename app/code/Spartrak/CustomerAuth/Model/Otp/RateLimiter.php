<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Model\Otp;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Spartrak\CustomerAuth\Exception\RateLimitExceededException;
use Spartrak\CustomerAuth\Model\Config;
use Spartrak\CustomerAuth\Model\ResourceModel\OtpRequest as OtpRequestResource;

/**
 * Throttles OTP sends across three independent axes.
 *
 * This class is the module's cost control and its main abuse defence, and it is
 * worth being explicit about what each limit is actually for, because they are
 * not interchangeable:
 *
 *  - COOLDOWN (per phone, seconds). Stops double-taps and the impatient-shopper
 *    loop. Also the value the "Resend in Ns" countdown is derived from, so the
 *    UI and the server agree by construction rather than by a duplicated
 *    constant in JavaScript.
 *
 *  - PER-PHONE CAP (per window). Stops someone burning SMS budget on one target
 *    number, which is both a cost attack on the store and an SMS-bombing
 *    harassment vector against whoever owns that number.
 *
 *  - PER-IP CAP (per window). Stops one host enumerating many numbers. This is
 *    the only limit that catches "send one code to each of 10,000 numbers",
 *    which the per-phone cap is blind to by definition.
 *
 * All three count rows in the ledger rather than incrementing a cache counter.
 * That is a deliberate trade of a little query cost for correctness: cache
 * counters are per-node unless a shared backend is configured, evaporate on a
 * flush, and are exactly what an attacker gets to reset for free.
 */
class RateLimiter
{
    public function __construct(
        private readonly Config $config,
        private readonly OtpRequestResource $otpRequestResource,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Assert a new code may be sent to $phone for $purpose right now.
     *
     * @param string      $phone     E.164.
     * @param string      $purpose   Purpose::* constant.
     * @param string|null $ipAddress Requesting IP, or null when unavailable.
     *
     * @throws RateLimitExceededException
     */
    public function assertCanSend(string $phone, string $purpose, ?string $ipAddress, ?int $storeId = null): void
    {
        $this->assertCooldownElapsed($phone, $purpose, $storeId);
        $this->assertPhoneQuota($phone, $storeId);
        $this->assertIpQuota($ipAddress, $storeId);
    }

    /**
     * Seconds until the next send is allowed, for the UI countdown.
     *
     * Never throws — it is display data. Returns 0 when a send is allowed now.
     */
    public function getSecondsUntilNextSend(string $phone, string $purpose, ?int $storeId = null): int
    {
        $cooldown = $this->config->getResendCooldownSeconds($storeId);

        if ($cooldown <= 0) {
            return 0;
        }

        $lastSend = $this->otpRequestResource->getLastSendTime($phone, $purpose);

        if ($lastSend === null) {
            return 0;
        }

        $elapsed = $this->now() - strtotime($lastSend . ' UTC');

        return (int) max(0, $cooldown - $elapsed);
    }

    private function assertCooldownElapsed(string $phone, string $purpose, ?int $storeId): void
    {
        $remaining = $this->getSecondsUntilNextSend($phone, $purpose, $storeId);

        if ($remaining <= 0) {
            return;
        }

        throw (new RateLimitExceededException(
            __('Please wait %1 seconds before requesting another code.', $remaining)
        ))->setRetryAfterSeconds($remaining);
    }

    private function assertPhoneQuota(string $phone, ?int $storeId): void
    {
        $window = $this->config->getRateWindowSeconds($storeId);
        $limit = $this->config->getMaxSendsPerPhone($storeId);
        $sent = $this->otpRequestResource->countSendsForPhoneSince($phone, $this->windowStart($window));

        if ($sent < $limit) {
            return;
        }

        // The message states the limit but not the count, and deliberately does
        // not say whether this number has an account.
        throw (new RateLimitExceededException(
            __('You have requested too many codes for this number. Please try again later or contact support.')
        ))->setRetryAfterSeconds($window);
    }

    private function assertIpQuota(?string $ipAddress, ?int $storeId): void
    {
        if ($ipAddress === null || $ipAddress === '') {
            // No address to attribute the request to. The per-phone caps above
            // still apply, so this is a degraded limit rather than no limit.
            return;
        }

        $window = $this->config->getRateWindowSeconds($storeId);
        $limit = $this->config->getMaxSendsPerIp($storeId);
        $sent = $this->otpRequestResource->countSendsForIpSince($ipAddress, $this->windowStart($window));

        if ($sent < $limit) {
            return;
        }

        throw (new RateLimitExceededException(
            __('Too many verification requests from your connection. Please try again later.')
        ))->setRetryAfterSeconds($window);
    }

    /**
     * Start of the rolling window as a UTC datetime string.
     */
    private function windowStart(int $windowSeconds): string
    {
        return $this->dateTime->gmtDate('Y-m-d H:i:s', $this->now() - $windowSeconds);
    }

    /**
     * Current UTC timestamp.
     *
     * Goes through Magento's DateTime rather than PHP's time() so it honours the
     * framework's clock, which integration tests and the staging module both
     * manipulate.
     */
    private function now(): int
    {
        return (int) $this->dateTime->gmtTimestamp();
    }
}
