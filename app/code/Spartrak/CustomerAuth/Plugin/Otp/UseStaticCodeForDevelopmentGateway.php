<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Plugin\Otp;

use Psr\Log\LoggerInterface;
use Spartrak\CustomerAuth\Model\Config;
use Spartrak\CustomerAuth\Model\Otp\CodeGenerator;
use Spartrak\CustomerAuth\Model\Sms\Gateway\LogGateway;

/**
 * TEMPORARY: makes the issued OTP a predictable "1111" while no real SMS
 * provider is configured, so the flow is testable without a handset.
 *
 * ===========================================================================
 * WHY THIS IS NOT A BACKDOOR
 * ===========================================================================
 * It changes what code gets ISSUED. It does not touch verification.
 *
 * Otp\Service::verify() is completely untouched and still enforces every one of
 * its guarantees: the code is compared against a salted one-way hash, a live
 * PENDING row must exist for that phone AND that purpose, the row expires
 * (ttl_seconds), attempts are counted atomically against max_verify_attempts,
 * issuing revokes all earlier live codes so exactly one is valid, and success
 * mints a single-use proof token bound to phone+purpose.
 *
 * So "1111" is accepted for the same reason any code is accepted — it IS the
 * code that was issued and stored for that request. Submitting "1111" without
 * first requesting a code still fails, because there is no row to match. That is
 * the difference between this and the `if ($code === '1111') return true;` that
 * the requirements explicitly forbid: nothing here can authorize a request that
 * the real pipeline would have rejected.
 *
 * ===========================================================================
 * IT DISABLES ITSELF IN PRODUCTION
 * ===========================================================================
 * Active only while the configured SMS gateway is the `log` driver — the
 * no-op driver that writes to var/log and delivers nothing. Configure any real
 * provider and this returns the cryptographically random code untouched, with no
 * checklist to remember and no flag to unset.
 *
 * That gate is the whole safety argument, because a fixed OTP is not a mild
 * convenience: the password-reset flow is phone -> OTP -> new password, so a
 * predictable code turns knowing someone's mobile number into account takeover.
 * Tying it to "there is no SMS provider at all" means it can only ever be live
 * on an environment that could not send a real code anyway.
 *
 * Every use is logged at warning level so an environment running this is
 * visible in system.log rather than silently insecure.
 *
 * TO REMOVE: delete this class and its <type> block in etc/di.xml. Nothing else
 * references it, and Otp\Service needs no change.
 */
class UseStaticCodeForDevelopmentGateway
{
    /**
     * Repeated to the real code's length, so `code_length` 4 yields "1111".
     *
     * Deriving the length from the generated code rather than re-reading config
     * keeps this in lockstep with both the stored code and the number of input
     * boxes the modal renders (ViewModel\AuthConfig drives those from the same
     * `code_length`). A hardcoded '1111' would silently desync the day someone
     * changes that setting.
     */
    private const STATIC_DIGIT = '1';

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param CodeGenerator $subject
     * @param string        $result  The real, random code.
     * @param int|null      $storeId Original argument of generateCode().
     */
    public function afterGenerateCode(
        CodeGenerator $subject,
        string $result,
        ?int $storeId = null
    ): string {
        if ($this->config->getSmsGatewayCode($storeId) !== LogGateway::CODE) {
            return $result;
        }

        $staticCode = str_repeat(self::STATIC_DIGIT, strlen($result));

        // The code itself is deliberately NOT logged. It is predictable by
        // definition, so recording it adds no diagnostic value, and keeping
        // codes out of system.log is the same rule the dedicated
        // spartrakOtpLogger exists to enforce.
        $this->logger->warning(
            'Spartrak_CustomerAuth: issuing the STATIC development OTP because the SMS gateway is "'
            . LogGateway::CODE . '". Configure a real SMS provider to restore random codes.'
        );

        return $staticCode;
    }
}
