<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * A send or verify limit was hit.
 *
 * Carries retryAfter so the storefront can show a real countdown instead of a
 * dead end. Controllers translate this into HTTP 429.
 */
class RateLimitExceededException extends LocalizedException
{
    private int $retryAfterSeconds = 0;

    public function setRetryAfterSeconds(int $seconds): self
    {
        $this->retryAfterSeconds = max(0, $seconds);

        return $this;
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
