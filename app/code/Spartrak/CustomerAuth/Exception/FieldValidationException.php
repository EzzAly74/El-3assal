<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * A single form field failed validation, and the response should say which one.
 *
 * The auth modal renders errors against the offending input (red border plus a
 * message directly under that field), not only in the shared per-step region.
 * To do that it needs the field name back, and the one existing precedent for
 * returning it is InvalidPhoneNumberException, which AbstractJsonAction maps to
 * `field: 'phone'`. This generalizes that precedent instead of adding a second,
 * competing convention.
 *
 * Scope is deliberately narrow. This is for values the shopper can see and
 * correct — a blank first name, a mismatched confirmation. It is NOT for
 * credential or OTP failures: naming the field there is exactly the per-field
 * feedback an attacker wants, which is why sign-in keeps one blended message
 * (see OtpVerificationException and LoginPost).
 */
class FieldValidationException extends LocalizedException
{
    private string $field;

    public function __construct(Phrase $phrase, string $field, ?\Exception $cause = null)
    {
        parent::__construct($phrase, $cause);

        $this->field = $field;
    }

    /**
     * The posted field name the message belongs to, e.g. `firstname`.
     */
    public function getField(): string
    {
        return $this->field;
    }
}
