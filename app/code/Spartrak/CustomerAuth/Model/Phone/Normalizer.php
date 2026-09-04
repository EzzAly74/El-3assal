<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Model\Phone;

use Spartrak\CustomerAuth\Exception\InvalidPhoneNumberException;
use Spartrak\CustomerAuth\Model\Config;

/**
 * Turns whatever a shopper typed into one canonical E.164 string.
 *
 * This class is the reason phone-as-identity can work at all. The phone number
 * is the account's unique key, so "01012345678", "+20 101 234 5678" and
 * "٠١٠١٢٣٤٥٦٧٨" must all collapse to the exact same stored value — otherwise
 * one person silently ends up with three accounts, and the UNIQUE index on
 * customer_entity.phone_number protects nothing.
 *
 * Arabic-Indic and Persian digit forms are converted, not rejected. Arabic is
 * the primary locale, so a shopper typing on an Arabic keyboard is the expected
 * case rather than an edge case. (The storefront renders numerals in Latin
 * digits per the design system, but that governs OUTPUT — it says nothing about
 * what an Android keyboard will submit.)
 */
class Normalizer
{
    /**
     * U+0660..U+0669 — Arabic-Indic digits.
     */
    private const ARABIC_INDIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    /**
     * U+06F0..U+06F9 — Extended Arabic-Indic (Persian) digits. Visually near
     * identical to the above but a different code point, and they do reach
     * Egyptian storefronts from some Android keyboard layouts.
     */
    private const EXTENDED_ARABIC_INDIC_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const LATIN_DIGITS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    /**
     * Egypt's country calling code.
     */
    private const COUNTRY_CODE_EGYPT = '20';

    /**
     * Egyptian mobile national significant number: 10 digits, always starting
     * with 1, and the second digit identifies the carrier —
     * 0 Vodafone / 1 Etisalat / 2 Orange / 5 WE. A landline (Cairo "2…",
     * Alexandria "3…") deliberately fails this: the number has to be able to
     * receive an SMS or the whole flow is a dead end for that shopper.
     */
    private const EGYPT_MOBILE_NSN_PATTERN = '/^1[0125]\d{8}$/';

    /**
     * E.164 caps a full international number at 15 digits. The lower bound is a
     * sanity floor, not a standard.
     */
    private const MIN_E164_DIGITS = 8;
    private const MAX_E164_DIGITS = 15;

    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * Canonicalize a shopper-entered number to E.164, e.g. "+201012345678".
     *
     * @throws InvalidPhoneNumberException when the input cannot be a real
     *         SMS-reachable number. The message is shopper-facing.
     */
    public function normalize(string $input): string
    {
        $raw = trim($this->convertNonLatinDigits($input));

        if ($raw === '') {
            throw new InvalidPhoneNumberException(__('Please enter your phone number.'));
        }

        // Remember whether the shopper signalled "this is already international"
        // BEFORE stripping punctuation, because that intent is carried by the
        // punctuation itself.
        $isInternational = str_starts_with($raw, '+');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            throw new InvalidPhoneNumberException(__('Please enter a valid phone number.'));
        }

        // "00" is the ITU trunk-to-international prefix and means the same thing
        // as a leading "+".
        if (!$isInternational && str_starts_with($digits, '00')) {
            $isInternational = true;
            $digits = substr($digits, 2);
        }

        $e164 = $isInternational
            ? $digits
            : $this->applyDefaultCountryCode($digits);

        $this->assertPlausibleLength($e164);
        $this->assertReachableByCountry($e164);

        return '+' . $e164;
    }

    /**
     * Same as normalize() but returns null instead of throwing.
     *
     * For read paths that merely need to look a number up (sign-in, "is this
     * phone taken?") and should report "no such account" rather than "your
     * number is malformed" — the two must be indistinguishable to the caller,
     * or the error message becomes an account-existence oracle.
     */
    public function normalizeOrNull(string $input): ?string
    {
        try {
            return $this->normalize($input);
        } catch (InvalidPhoneNumberException) {
            return null;
        }
    }

    /**
     * Redact a number for logs and exception messages: "+2010****5678".
     *
     * Phone numbers are personal data and log files travel further than
     * databases do — they get shipped to aggregators, pasted into tickets and
     * read by people who have no reason to see a customer's number. Enough of
     * the number survives to correlate two entries; not enough to dial it.
     */
    public function mask(string $e164): string
    {
        $digits = preg_replace('/\D+/', '', $e164) ?? '';
        $length = strlen($digits);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return '+' . substr($digits, 0, 4) . str_repeat('*', $length - 8) . substr($digits, -4);
    }

    /**
     * Digits only, no leading "+". Some SMS gateways reject the plus sign.
     */
    public function toDigits(string $e164): string
    {
        return preg_replace('/\D+/', '', $e164) ?? '';
    }

    /**
     * Split a stored number back into the two boxes a form draws it in:
     * a fixed dialling code and an editable national number.
     *
     *     "+201207245632"  ->  ['dial' => '+20', 'national' => '01207245632']
     *
     * ===================================================================
     * WHY THIS LIVES HERE
     * ===================================================================
     * It is the exact inverse of applyDefaultCountryCode() below, and the rule
     * it inverts is not obvious: the leading zero is a TRUNK PREFIX, not part
     * of the subscriber number, so it is absent from E.164 and has to be put
     * back for display. Anything that formats a stored number for a form needs
     * that rule, and a second copy of it somewhere in a view model would be one
     * copy too many the first time the configured country changes.
     *
     * The My Account profile card (Figma 562:16478) is the first consumer: it
     * draws "+20" in its own bordered box with "01207245632" beside it.
     *
     * Degrades honestly. A number that does not carry the configured country
     * code — a legacy row, or one written before the country setting changed —
     * comes back whole in `national` with an empty `dial`, so a form shows the
     * real stored value rather than a silently truncated one.
     *
     * @return array{dial: string, national: string}
     */
    public function toLocalParts(string $e164): array
    {
        $digits = $this->toDigits($e164);
        $countryCode = $this->config->getDefaultCountryCode();

        if ($digits === '') {
            return ['dial' => '', 'national' => ''];
        }

        if ($countryCode === '' || !str_starts_with($digits, $countryCode)) {
            return ['dial' => '', 'national' => $digits];
        }

        $nsn = substr($digits, strlen($countryCode));

        return [
            'dial' => '+' . $countryCode,
            // The trunk prefix, restored. Egyptian mobiles are always written
            // "01…" locally even though E.164 stores them as "+201…".
            'national' => $nsn !== '' ? '0' . $nsn : '',
        ];
    }

    /**
     * Interpret a number the shopper typed without any country code.
     */
    private function applyDefaultCountryCode(string $digits): string
    {
        $countryCode = $this->config->getDefaultCountryCode();

        // A single leading zero is the national trunk prefix ("01012345678").
        // Strip exactly one — stripping all of them would corrupt a number whose
        // significant digits legitimately begin with a zero.
        if (str_starts_with($digits, '0')) {
            return $countryCode . substr($digits, 1);
        }

        // Already carries the country code but no "+" or "00" ("201012345678").
        // Checked after the trunk-prefix case because that one is unambiguous,
        // and safe for Egypt specifically: no Egyptian national number starts
        // with "20", so this cannot swallow a real subscriber number.
        if ($countryCode !== '' && str_starts_with($digits, $countryCode) && strlen($digits) > strlen($countryCode)) {
            return $digits;
        }

        // National significant number with the trunk prefix already dropped
        // ("1012345678"), which is what an <input type="tel"> with a separate
        // country-code selector submits.
        return $countryCode . $digits;
    }

    private function assertPlausibleLength(string $e164): void
    {
        $length = strlen($e164);

        if ($length < self::MIN_E164_DIGITS || $length > self::MAX_E164_DIGITS) {
            throw new InvalidPhoneNumberException(__('Please enter a valid phone number.'));
        }
    }

    /**
     * Egypt-only reachability.
     *
     * TIGHTENED 2026-08-26 on an explicit business decision. This method used to
     * `return` early for any country code other than "20", so "+447911123456"
     * and "+971501234567" both normalized cleanly and could register. Foreign
     * numbers are now rejected outright.
     *
     * Enforced HERE on purpose. Every endpoint already funnels through this one
     * chokepoint (AbstractJsonAction::getPostedPhone), so putting the rule here
     * makes it authoritative — it cannot be skipped by posting directly to the
     * endpoint, and client-side validation is free to mirror it as pure UX.
     *
     * The trade is intentional: a shopper holding a genuinely foreign number
     * cannot register. Accepting one would mint an account whose OTP the gateway
     * never delivers, and because this storefront has no email fallback, that
     * account is unrecoverable the moment its password is forgotten.
     */
    private function assertReachableByCountry(string $e164): void
    {
        // Measured against the CONFIGURED country code, not the Egypt constant
        // directly. Hard-coding Egypt here would turn
        // `spartrak_auth/account/default_country_code` into a trap:
        // applyDefaultCountryCode() prepends whatever that setting says, so any
        // value other than "20" would prepend one country code and then be
        // rejected for not being another — every registration failing with a
        // message that makes no sense. One source of truth for "which country
        // this store serves" keeps the two halves consistent.
        $countryCode = $this->config->getDefaultCountryCode();
        $isEgypt = $countryCode === self::COUNTRY_CODE_EGYPT;

        if (!str_starts_with($e164, $countryCode)) {
            throw new InvalidPhoneNumberException(
                $isEgypt
                    ? __('Please enter an Egyptian mobile number, for example 01012345678.')
                    : __('Please enter a valid local mobile number.')
            );
        }

        // Carrier-prefix validation is Egypt-specific, so it only applies when
        // Egypt is the configured market. For any other country the length check
        // stands alone — inventing an NSN pattern for a market nobody has
        // specified would be a guess.
        if (!$isEgypt) {
            return;
        }

        $nationalNumber = substr($e164, strlen($countryCode));

        if (!preg_match(self::EGYPT_MOBILE_NSN_PATTERN, $nationalNumber)) {
            throw new InvalidPhoneNumberException(
                __('Please enter a valid Egyptian mobile number, for example 01012345678.')
            );
        }
    }

    private function convertNonLatinDigits(string $input): string
    {
        return str_replace(
            array_merge(self::ARABIC_INDIC_DIGITS, self::EXTENDED_ARABIC_INDIC_DIGITS),
            array_merge(self::LATIN_DIGITS, self::LATIN_DIGITS),
            $input
        );
    }
}
