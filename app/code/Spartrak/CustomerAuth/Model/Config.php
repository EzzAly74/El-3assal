<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed, clamped read layer over this module's store configuration.
 *
 * Nothing else in the module touches ScopeConfigInterface. Two reasons:
 *
 *  - Type safety. Store config always returns strings, and an OTP lifetime of
 *    "300 " or "" behaves very differently from 300 once it reaches date
 *    arithmetic.
 *  - Clamping. Every security-relevant number is bounded here as well as in
 *    system.xml. system.xml validation only guards the admin form; a value set
 *    by `bin/magento config:set`, a config.php deployment or a direct
 *    core_config_data write bypasses it entirely. A misconfigured
 *    max_verify_attempts of 100000 must not be able to turn a 6-digit code into
 *    an open door.
 */
class Config
{
    private const XML_PATH_CODE_LENGTH = 'spartrak_auth/otp/code_length';
    private const XML_PATH_TTL_SECONDS = 'spartrak_auth/otp/ttl_seconds';
    private const XML_PATH_MAX_VERIFY_ATTEMPTS = 'spartrak_auth/otp/max_verify_attempts';
    private const XML_PATH_RESEND_COOLDOWN = 'spartrak_auth/otp/resend_cooldown_seconds';
    private const XML_PATH_MAX_SENDS_PER_PHONE = 'spartrak_auth/otp/max_sends_per_phone';
    private const XML_PATH_MAX_SENDS_PER_IP = 'spartrak_auth/otp/max_sends_per_ip';
    private const XML_PATH_RATE_WINDOW = 'spartrak_auth/otp/rate_window_seconds';
    private const XML_PATH_PROOF_TOKEN_TTL = 'spartrak_auth/otp/proof_token_ttl_seconds';
    private const XML_PATH_PURGE_AFTER_DAYS = 'spartrak_auth/otp/purge_after_days';
    private const XML_PATH_SMS_GATEWAY = 'spartrak_auth/sms/gateway';
    private const XML_PATH_SMS_SENDER_NAME = 'spartrak_auth/sms/sender_name';
    private const XML_PATH_PLACEHOLDER_EMAIL_DOMAIN = 'spartrak_auth/account/placeholder_email_domain';
    private const XML_PATH_DEFAULT_COUNTRY_CODE = 'spartrak_auth/account/default_country_code';

    /**
     * Fallback used when the placeholder domain is blanked out in config. It has
     * to be non-empty and undeliverable, because a synthesized address with an
     * empty domain fails Magento's email validation and takes down registration
     * entirely — a broken config should degrade, not break the funnel.
     */
    private const FALLBACK_PLACEHOLDER_DOMAIN = 'phone.sparetrak.invalid';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getCodeLength(?int $storeId = null): int
    {
        return $this->getBoundedInt(self::XML_PATH_CODE_LENGTH, 4, 8, 6, $storeId);
    }

    public function getCodeTtlSeconds(?int $storeId = null): int
    {
        return $this->getBoundedInt(self::XML_PATH_TTL_SECONDS, 60, 1800, 300, $storeId);
    }

    public function getMaxVerifyAttempts(?int $storeId = null): int
    {
        return $this->getBoundedInt(self::XML_PATH_MAX_VERIFY_ATTEMPTS, 1, 10, 5, $storeId);
    }

    public function getResendCooldownSeconds(?int $storeId = null): int
    {
        return $this->getBoundedInt(self::XML_PATH_RESEND_COOLDOWN, 0, 600, 60, $storeId);
    }

    public function getMaxSendsPerPhone(?int $storeId = null): int
    {
        return $this->getBoundedInt(self::XML_PATH_MAX_SENDS_PER_PHONE, 1, 100, 5, $storeId);
    }

    public function getMaxSendsPerIp(?int $storeId = null): int
    {
        return $this->getBoundedInt(self::XML_PATH_MAX_SENDS_PER_IP, 1, 1000, 20, $storeId);
    }

    public function getRateWindowSeconds(?int $storeId = null): int
    {
        return $this->getBoundedInt(self::XML_PATH_RATE_WINDOW, 60, 86400, 3600, $storeId);
    }

    public function getProofTokenTtlSeconds(?int $storeId = null): int
    {
        return $this->getBoundedInt(self::XML_PATH_PROOF_TOKEN_TTL, 60, 3600, 900, $storeId);
    }

    public function getPurgeAfterDays(): int
    {
        return $this->getBoundedInt(self::XML_PATH_PURGE_AFTER_DAYS, 1, 365, 7, null);
    }

    public function getSmsGatewayCode(?int $storeId = null): string
    {
        $code = trim((string) $this->getValue(self::XML_PATH_SMS_GATEWAY, $storeId));

        return $code !== '' ? $code : 'log';
    }

    public function getSmsSenderName(?int $storeId = null): string
    {
        $name = trim((string) $this->getValue(self::XML_PATH_SMS_SENDER_NAME, $storeId));

        return $name !== '' ? $name : 'SpareTrak';
    }

    public function getPlaceholderEmailDomain(?int $storeId = null): string
    {
        $domain = strtolower(trim((string) $this->getValue(self::XML_PATH_PLACEHOLDER_EMAIL_DOMAIN, $storeId)));
        // Tolerate a leading "@" — an easy thing for an admin to type.
        $domain = ltrim($domain, '@');

        return $domain !== '' ? $domain : self::FALLBACK_PLACEHOLDER_DOMAIN;
    }

    public function getDefaultCountryCode(?int $storeId = null): string
    {
        $code = preg_replace('/\D+/', '', (string) $this->getValue(self::XML_PATH_DEFAULT_COUNTRY_CODE, $storeId)) ?? '';

        return $code !== '' ? $code : '20';
    }

    private function getValue(string $path, ?int $storeId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function getBoundedInt(string $path, int $min, int $max, int $default, ?int $storeId): int
    {
        $raw = $this->getValue($path, $storeId);

        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return $default;
        }

        return max($min, min($max, (int) $raw));
    }
}
