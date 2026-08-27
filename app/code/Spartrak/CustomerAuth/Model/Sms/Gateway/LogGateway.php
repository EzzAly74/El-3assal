<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Model\Sms\Gateway;

use Psr\Log\LoggerInterface;
use Spartrak\CustomerAuth\Api\SmsGatewayInterface;

/**
 * Writes the message to var/log/spartrak_otp.log and delivers nothing.
 *
 * This is the shipped default, and it is a deliberate choice over a silent
 * no-op: a no-op gateway makes staging look healthy while every shopper is
 * locked out of registration. With this driver the entire flow — throttling,
 * expiry, attempt counting, proof tokens, account creation — is exercisable
 * end-to-end before any provider contract is signed, and `isRealDelivery()`
 * returns false so nothing downstream can mistake it for production.
 *
 * It logs the full code and the full number on purpose. That is only acceptable
 * because it is a development driver; the dedicated log file exists so this
 * output never lands in system.log and never leaves a developer machine.
 */
class LogGateway implements SmsGatewayInterface
{
    public const CODE = 'log';

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getTitle(): string
    {
        return (string) __('Log only — writes to var/log, sends no SMS (staging)');
    }

    public function send(string $phoneNumber, string $message, string $senderName): void
    {
        $this->logger->info(
            sprintf('[SpareTrak OTP] to=%s from=%s body=%s', $phoneNumber, $senderName, $message)
        );
    }

    public function isRealDelivery(): bool
    {
        return false;
    }
}
