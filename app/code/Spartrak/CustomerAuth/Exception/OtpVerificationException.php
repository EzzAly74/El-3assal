<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * The submitted code was wrong, expired, already used, or out of attempts.
 *
 * Deliberately ONE exception type for all four cases. Distinguishing them in
 * the response would tell an attacker which of "no such request", "expired" and
 * "wrong digits" they are looking at, which is exactly the feedback needed to
 * tune an attack. The `attemptsRemaining` hint is the one detail worth leaking,
 * because the shopper genuinely needs it and it reveals nothing about the code.
 */
class OtpVerificationException extends LocalizedException
{
    private ?int $attemptsRemaining = null;

    public function setAttemptsRemaining(?int $attempts): self
    {
        $this->attemptsRemaining = $attempts === null ? null : max(0, $attempts);

        return $this;
    }

    public function getAttemptsRemaining(): ?int
    {
        return $this->attemptsRemaining;
    }
}
