<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * The SMS gateway refused or failed to accept the message.
 *
 * Gateway failure is on the critical path of both registration and password
 * reset (11-MODULE-ARCHITECTURE.md flags this as the module's headline risk), so
 * it gets its own type: the OTP row must be revoked rather than left pending
 * when this is thrown, or the shopper is stuck holding a cooldown for a code
 * that was never sent.
 */
class SmsDeliveryException extends LocalizedException
{
}
