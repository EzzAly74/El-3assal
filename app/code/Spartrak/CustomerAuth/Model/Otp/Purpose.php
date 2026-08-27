<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Model\Otp;

use Spartrak\CustomerAuth\Exception\OtpVerificationException;

/**
 * The two flows an OTP can authorize.
 *
 * Purpose is stored on the row and re-checked at redemption, so a code obtained
 * for one flow cannot be spent on the other. Without that check, requesting a
 * signup code for a number that already has an account would hand over a
 * password-reset token for someone else's account — a full account takeover from
 * nothing but knowing a phone number.
 */
final class Purpose
{
    /**
     * Prove ownership of a number that has no account yet, before creating one.
     */
    public const SIGNUP = 'signup';

    /**
     * Prove ownership of a number that already has an account, before replacing
     * its password.
     */
    public const PASSWORD_RESET = 'password_reset';

    private const ALL = [self::SIGNUP, self::PASSWORD_RESET];

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return self::ALL;
    }

    public static function isValid(string $purpose): bool
    {
        return in_array($purpose, self::ALL, true);
    }

    /**
     * Validate an untrusted purpose value arriving from a request.
     *
     * @throws OtpVerificationException
     */
    public static function assertValid(string $purpose): string
    {
        if (!self::isValid($purpose)) {
            throw new OtpVerificationException(__('This verification request is not valid.'));
        }

        return $purpose;
    }
}
