<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Api;

use Spartrak\CustomerAuth\Exception\SmsDeliveryException;

/**
 * The module's single boundary to the outside world.
 *
 * Adding a provider (SMSMisr, Victory Link, Vodafone Bulk, Twilio, ...) means
 * writing exactly one class against this interface and adding one line to the
 * `gateways` argument of GatewayResolver in di.xml. Nothing else in the module
 * knows a provider exists — the OTP service depends on this interface only.
 *
 * The interface is intentionally narrow. It does NOT expose delivery receipts,
 * balance queries or inbound messages: those are real provider features but none
 * of them are on the registration or password-reset path, and putting them here
 * would force every future driver to implement things it may not support.
 */
interface SmsGatewayInterface
{
    /**
     * Machine code this gateway is selected by in store configuration.
     *
     * Must match the array key it is registered under in di.xml.
     */
    public function getCode(): string;

    /**
     * Human-readable label for the admin dropdown.
     */
    public function getTitle(): string;

    /**
     * Hand one message to the provider.
     *
     * Returning normally means the provider ACCEPTED the message, not that a
     * handset received it — no SMS gateway can promise the latter synchronously.
     * Callers must treat this as "queued".
     *
     * Implementations MUST NOT retry internally. A retry loop here would sit on
     * the shopper's request thread and turn a slow provider into a page timeout;
     * the shopper's own "Resend" button is the retry mechanism, already rate
     * limited.
     *
     * @param string $phoneNumber Recipient in E.164, e.g. "+201012345678".
     * @param string $message     Body, already localized and rendered.
     * @param string $senderName  Pre-registered alphanumeric sender ID.
     *
     * @throws SmsDeliveryException when the provider rejects, errors or is
     *         unreachable. The caller revokes the pending OTP row on this, so
     *         never swallow a failure and return normally.
     */
    public function send(string $phoneNumber, string $message, string $senderName): void;

    /**
     * Whether this gateway actually delivers to real handsets.
     *
     * False for the log/dev driver. The storefront uses this to decide whether
     * showing the code on screen in a non-production environment is acceptable,
     * and it keeps "why did no SMS arrive?" answerable without reading config.
     */
    public function isRealDelivery(): bool;
}
