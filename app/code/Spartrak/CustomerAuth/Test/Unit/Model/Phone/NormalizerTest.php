<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Test\Unit\Model\Phone;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Spartrak\CustomerAuth\Exception\InvalidPhoneNumberException;
use Spartrak\CustomerAuth\Model\Config;
use Spartrak\CustomerAuth\Model\Phone\Normalizer;

/**
 * The normalizer is the highest-risk pure logic in the module and the cheapest
 * thing to get subtly wrong.
 *
 * Everything downstream assumes one number maps to exactly one canonical string:
 * the UNIQUE index on customer_entity.phone_number, sign-in lookup, the OTP
 * ledger's phone column, and the per-phone rate limit. If two spellings of the
 * same number normalize differently, a shopper gets two accounts, the rate limit
 * is trivially bypassed by adding a space, and "one account per phone" quietly
 * stops being true — none of which surfaces as an error anywhere.
 *
 * @covers \Spartrak\CustomerAuth\Model\Phone\Normalizer
 */
class NormalizerTest extends TestCase
{
    private Normalizer $normalizer;

    /** @var Config&MockObject */
    private Config $config;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getDefaultCountryCode')->willReturn('20');

        $this->normalizer = new Normalizer($this->config);
    }

    /**
     * Every one of these is the same Vodafone handset, written the way a real
     * shopper, an autofill, a pasted WhatsApp contact or an Arabic keyboard
     * would produce it. All must collapse to one string.
     *
     * @dataProvider equivalentEgyptianMobileProvider
     */
    public function testEquivalentSpellingsNormalizeIdentically(string $input, string $why): void
    {
        $this->assertSame(
            '+201012345678',
            $this->normalizer->normalize($input),
            'Failed for ' . $why . ': ' . $input
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function equivalentEgyptianMobileProvider(): array
    {
        return [
            'national with trunk prefix' => ['01012345678', 'the way it is written on a business card'],
            'national without trunk prefix' => ['1012345678', 'a tel input with a separate country selector'],
            'e164 with plus' => ['+201012345678', 'already canonical'],
            'international without plus' => ['201012345678', 'copied from a system that strips the plus'],
            'itu 00 prefix' => ['00201012345678', 'dialled from a landline habit'],
            'spaces' => ['+20 101 234 5678', 'pasted from a contact card'],
            'dashes' => ['010-1234-5678', 'typed with separators'],
            'parentheses and spaces' => ['+20 (101) 234 5678', 'Western formatting convention'],
            'leading and trailing whitespace' => ['  01012345678  ', 'sloppy paste'],
            'non-breaking space' => ["+20\u{00A0}101\u{00A0}2345678", 'pasted from a web page'],
            'arabic-indic digits' => ['٠١٠١٢٣٤٥٦٧٨', 'an Arabic keyboard, the primary locale'],
            'persian digits' => ['۰۱۰۱۲۳۴۵۶۷۸', 'some Android Arabic layouts emit these'],
            'mixed arabic and latin digits' => ['٠١٠1234٥٦٧٨', 'a half-switched keyboard'],
        ];
    }

    /**
     * All four Egyptian mobile carriers must be accepted. Getting this list
     * wrong locks a quarter of the country out of registration, and it would
     * only be noticed as unexplained drop-off.
     *
     * @dataProvider carrierPrefixProvider
     */
    public function testAllEgyptianCarrierPrefixesAreAccepted(string $input, string $expected, string $carrier): void
    {
        $this->assertSame($expected, $this->normalizer->normalize($input), 'Rejected ' . $carrier);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function carrierPrefixProvider(): array
    {
        return [
            'vodafone 010' => ['01012345678', '+201012345678', 'Vodafone'],
            'etisalat 011' => ['01112345678', '+201112345678', 'Etisalat'],
            'orange 012' => ['01212345678', '+201212345678', 'Orange'],
            'we 015' => ['01512345678', '+201512345678', 'WE'],
        ];
    }

    /**
     * @dataProvider rejectedProvider
     */
    public function testUnusableNumbersAreRejected(string $input, string $why): void
    {
        $this->expectException(InvalidPhoneNumberException::class);
        $this->normalizer->normalize($input);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejectedProvider(): array
    {
        return [
            'empty' => ['', 'nothing entered'],
            'whitespace only' => ['   ', 'nothing entered'],
            'letters only' => ['not a phone', 'no digits at all'],
            'too short' => ['0101234', 'incomplete number'],
            'too long' => ['010123456789012', 'extra digits typed'],
            // Landlines cannot receive an SMS, so accepting one produces a
            // shopper who can never complete verification.
            'cairo landline' => ['0223456789', 'landline, cannot receive SMS'],
            'alexandria landline' => ['0334567890', 'landline, cannot receive SMS'],
            'invalid carrier prefix' => ['01312345678', '013 is not an allocated mobile prefix'],
            'nine digit mobile' => ['0101234567', 'one digit short'],
            'twelve digit mobile' => ['010123456789', 'one digit long'],
        ];
    }

    /**
     * Foreign numbers are REJECTED. Business rule, tightened 2026-08-26.
     *
     * Both of these previously normalized cleanly and could register an account,
     * because the country gate `return`ed early for any code other than "20".
     * They are asserted individually rather than folded into the malformed-number
     * data provider because the regression guarded here is different in kind:
     * not "these digits are not a valid Egyptian mobile" but "the country check
     * was skipped entirely".
     *
     * @dataProvider foreignNumberProvider
     */
    public function testForeignNumbersAreRejected(string $input, string $why): void
    {
        $this->expectException(InvalidPhoneNumberException::class);

        $this->normalizer->normalize($input);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function foreignNumberProvider(): array
    {
        return [
            'uk mobile, plus prefix' => ['+44 7911 123456', 'United Kingdom, country code 44'],
            'uae mobile, 00 prefix' => ['00971501234567', 'UAE via the ITU trunk prefix'],
            'us number' => ['+12025550123', 'country code 1'],
            'saudi mobile' => ['+966501234567', 'neighbouring market, still out of scope'],
        ];
    }

    public function testNormalizeOrNullReturnsNullInsteadOfThrowing(): void
    {
        $this->assertNull($this->normalizer->normalizeOrNull('not a phone'));
        $this->assertSame('+201012345678', $this->normalizer->normalizeOrNull('01012345678'));
    }

    /**
     * The mask is what makes it safe to reference a number in a log line. It has
     * to keep enough to correlate two entries and lose enough that the number
     * cannot be dialled.
     */
    public function testMaskHidesTheSubscriberDigits(): void
    {
        $masked = $this->normalizer->mask('+201012345678');

        $this->assertSame('+2010****5678', $masked);
        $this->assertStringNotContainsString('1234', $masked, 'middle digits must not survive');
    }

    /**
     * At or below 8 digits there is nothing left to reveal safely — keeping the
     * first four and last four would be the whole number — so the mask degrades
     * to full redaction rather than leaking everything.
     */
    public function testMaskFullyRedactsAShortValue(): void
    {
        $this->assertSame('*******', $this->normalizer->mask('+2010101'), '7 digits');
        $this->assertSame('********', $this->normalizer->mask('+20101010'), '8 digits');
    }

    public function testToDigitsStripsThePlusForGatewaysThatRejectIt(): void
    {
        $this->assertSame('201012345678', $this->normalizer->toDigits('+201012345678'));
    }

    /**
     * Normalizing twice must be a no-op. Values round-trip through the database
     * and back into the limiter, so a normalizer that shifts its own output
     * would break the per-phone quota on the second pass.
     */
    public function testNormalizationIsIdempotent(): void
    {
        $once = $this->normalizer->normalize('01012345678');
        $twice = $this->normalizer->normalize($once);

        $this->assertSame($once, $twice);
    }

    /**
     * The default country code is configuration, not a constant. If it stops
     * being read, every non-prefixed number silently becomes Egyptian.
     */
    public function testDefaultCountryCodeComesFromConfiguration(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getDefaultCountryCode')->willReturn('971');
        $normalizer = new Normalizer($config);

        $this->assertSame('+971501234567', $normalizer->normalize('0501234567'));
    }
}
