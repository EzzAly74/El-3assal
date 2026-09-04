<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Model\Otp;

use Spartrak\CustomerAuth\Exception\OtpVerificationException;

/**
 * The three flows an OTP can authorize.
 *
 * Purpose is stored on the row and re-checked at redemption, so a code obtained
 * for one flow cannot be spent on another. Without that check, requesting a
 * signup code for a number that already has an account would hand over a
 * password-reset token for someone else's account — a full account takeover from
 * nothing but knowing a phone number.
 *
 * The same reasoning is what makes PHONE_CHANGE a third value rather than a
 * reuse of SIGNUP. Both prove ownership of a number with no account attached,
 * so the codes look interchangeable — but they buy different things. A signup
 * token creates a new account; a phone-change token rewrites the login
 * identifier of an EXISTING one. Sharing a purpose between them would let a
 * code issued to a number during registration be spent to point somebody's
 * live account at it, and the redemption check that stops that is this string.
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

    /**
     * Prove ownership of a number a SIGNED-IN customer wants to move their
     * account to, before `customer_entity.phone_number` is rewritten.
     *
     * Figma 821:17158 / overlay 1078:7263 — "لقد قمنا بارسال رمز التحقق إلى رقم
     * الهاتف الجديد الخاص بك". The code goes to the NEW number, never the old
     * one: the question being asked is "do you hold the number you are moving
     * to", and only a message to that number can answer it.
     */
    public const PHONE_CHANGE = 'phone_change';

    private const ALL = [self::SIGNUP, self::PASSWORD_RESET, self::PHONE_CHANGE];

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
