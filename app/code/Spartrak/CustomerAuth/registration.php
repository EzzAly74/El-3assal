<?php
/**
 * Spartrak_CustomerAuth — phone-number identity and OTP verification.
 *
 * Module 1 in .claude/docs/11-MODULE-ARCHITECTURE.md ("Customer Phone+OTP
 * Authentication"). It exists as a module, not theme work, because it adds
 * customer identity/auth logic, OTP lifecycle management, rate limiting and a
 * third-party SMS gateway boundary — none of which is presentation.
 *
 * It EXTENDS Magento_Customer, it does not fork it: authentication still goes
 * through Magento\Customer\Api\AccountManagementInterface, password strength and
 * failure lockout stay native, and the customer entity stays the customer
 * entity. All this module adds is "the identifier the shopper types is a phone
 * number" plus a verified-ownership proof for the two flows that need one.
 */

declare(strict_types=1);

\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'Spartrak_CustomerAuth',
    __DIR__
);
