<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Model\Customer;

use Spartrak\CustomerAuth\Model\Config;
use Spartrak\CustomerAuth\Model\Phone\Normalizer;

/**
 * Synthesizes (and recognizes) the email address Magento insists on.
 *
 * Magento requires every customer to have a unique, format-valid email. The
 * designed registration step collects a name and a password, not an email, so
 * one has to be manufactured — and then every part of Magento that assumes an
 * email is a way to reach a human has to be told otherwise.
 *
 * Recognition is by domain rather than by a flag column on purpose: it needs no
 * schema change, it cannot drift out of sync with the address itself, and it
 * keeps working for customers created before any flag existed. The trade-off is
 * that changing the configured domain stops the module recognizing previously
 * created placeholders — which is why the config comment says to treat it as
 * write-once.
 */
class PlaceholderEmail
{
    /**
     * Local-part prefix, so a placeholder is legible at a glance in the admin
     * customer grid instead of looking like a real address someone mistyped.
     */
    private const LOCAL_PART_PREFIX = 'phone-';

    public function __construct(
        private readonly Config $config,
        private readonly Normalizer $normalizer
    ) {
    }

    /**
     * Build the placeholder address for an E.164 number.
     *
     * Deterministic: the same number always yields the same address, so a
     * retried registration collides on the email unique key exactly as it
     * collides on the phone unique key, instead of quietly creating a second
     * account with a different random address.
     */
    public function generate(string $phoneE164, ?int $storeId = null): string
    {
        return self::LOCAL_PART_PREFIX
            . $this->normalizer->toDigits($phoneE164)
            . '@'
            . $this->config->getPlaceholderEmailDomain($storeId);
    }

    /**
     * Whether this address is one of ours and therefore undeliverable.
     *
     * Used to suppress transactional email. Compares only the domain, so it
     * still recognizes an address whose local part was edited in the admin.
     */
    public function isPlaceholder(?string $email, ?int $storeId = null): bool
    {
        if ($email === null || $email === '') {
            return false;
        }

        $domain = $this->config->getPlaceholderEmailDomain($storeId);

        if ($domain === '') {
            return false;
        }

        return str_ends_with(strtolower(trim($email)), '@' . $domain);
    }
}
