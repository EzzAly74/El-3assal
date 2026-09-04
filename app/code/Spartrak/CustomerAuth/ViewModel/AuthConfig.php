<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Spartrak\CustomerAuth\Model\Config;
use Spartrak\CustomerAuth\Model\Otp\Purpose;

/**
 * Read-only projection of this module's configuration for the storefront auth
 * modal.
 *
 * Exists so the theme's login-modal.phtml can render a config-driven UI without
 * the theme layer taking a dependency on Model\Config, Purpose, or anything else
 * inside this module's domain. 10-THEME-ARCHITECTURE.md assigns business logic to
 * modules and presentation to the theme; a ViewModel is the seam Magento provides
 * for exactly that, and it keeps the template's contract to plain scalars.
 *
 * Deliberately narrow. It exposes only the values the modal has to render or
 * hand to its JS widget — the OTP box count, the resend cooldown, the code
 * lifetime shown to the shopper, and the two purpose constants. It does NOT
 * expose the rate-limit quotas or the proof-token TTL: those are server-side
 * defences, and publishing them into cached page HTML tells an attacker exactly
 * how much room they have before tripping a limit.
 *
 * FPC note: every value here is store-scoped configuration, and full-page cache
 * is already keyed by store, so rendering these into cached HTML is safe. Nothing
 * customer-specific passes through this class — the modal block stays cacheable.
 */
class AuthConfig implements ArgumentInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * How many OTP boxes the verify step should render.
     *
     * The component follows configuration rather than hardcoding the 4 boxes the
     * Figma OTP component draws, because code length is a security setting the
     * client can change in admin (default 6). Hardcoding it would silently break
     * verification the first time that value moves — the shopper would be unable
     * to type the last two digits of a code they can plainly see in the SMS.
     */
    public function getCodeLength(): int
    {
        return $this->config->getCodeLength($this->getStoreId());
    }

    /**
     * Seconds before the Resend button re-enables.
     *
     * Only the initial render value. Every send/resend response carries an
     * authoritative `resend_in`, and the widget prefers that — this is the value
     * used before the first round trip, and after a failure that returned none.
     */
    public function getResendCooldownSeconds(): int
    {
        return $this->config->getResendCooldownSeconds($this->getStoreId());
    }

    /**
     * Code lifetime, for the "expires in N minutes" hint on the verify step.
     */
    public function getCodeTtlSeconds(): int
    {
        return $this->config->getCodeTtlSeconds($this->getStoreId());
    }

    /**
     * Rounded to whole minutes for shopper-facing copy. Ceil, not floor: telling
     * someone a code lasts "4 minutes" when it lasts 4:30 is the harmless
     * direction to be wrong in, but "5 minutes" for a 4:30 code is not.
     */
    public function getCodeTtlMinutes(): int
    {
        return max(1, (int) ceil($this->getCodeTtlSeconds() / 60));
    }

    /**
     * Default calling code, digits only ("20"), for the phone field's prefix.
     *
     * Display context only. The field still accepts a local "01…" number — the
     * server-side Normalizer applies this same country code — so this must never
     * be concatenated with the input before posting.
     */
    public function getDefaultCountryCode(): string
    {
        return $this->config->getDefaultCountryCode($this->getStoreId());
    }

    public function getSignupPurpose(): string
    {
        return Purpose::SIGNUP;
    }

    public function getPasswordResetPurpose(): string
    {
        return Purpose::PASSWORD_RESET;
    }

    /**
     * The purpose the account card's phone dialog verifies against
     * (Figma 1078:7263).
     *
     * Exposed for the same reason the other two are: the value belongs to
     * Purpose, and a template that wrote the string itself would be a second
     * place to keep in step with it. The server validates whatever arrives
     * (Purpose::assertValid), so this is about having one spelling, not about
     * trusting the client.
     */
    public function getPhoneChangePurpose(): string
    {
        return Purpose::PHONE_CHANGE;
    }

    private function getStoreId(): int
    {
        return (int) $this->storeManager->getStore()->getId();
    }
}
