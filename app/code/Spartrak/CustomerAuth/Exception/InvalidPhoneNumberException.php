<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * The submitted phone number cannot be a real SMS-reachable number.
 *
 * Message is shopper-facing and safe to render: it describes the format only
 * and never reveals whether an account exists.
 */
class InvalidPhoneNumberException extends LocalizedException
{
}
