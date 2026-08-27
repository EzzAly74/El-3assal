<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Spartrak\CustomerAuth\Model\Customer\PlaceholderEmail;

/**
 * Tells a template whether the email in front of it is real or synthesized.
 *
 * Registration collects a phone number, not an email, so every phone-registered
 * customer holds a placeholder address in the email COLUMN — Magento 2.4.8 has
 * no supported path to a genuinely null one (see the architecture note in
 * PlaceholderEmail). That value is an internal implementation detail: it must
 * never render where a shopper can see it, on the storefront or in the admin
 * customer form/grid.
 *
 * This view model is the single place templates ask "is this address ours?" —
 * delegating straight to PlaceholderEmail::isPlaceholder() rather than
 * reimplementing the domain check, so the two can never drift apart.
 */
class CustomerEmail implements ArgumentInterface
{
    public function __construct(
        private readonly PlaceholderEmail $placeholderEmail
    ) {
    }

    /**
     * True when $email is a synthesized phone-derived address the shopper never
     * chose and must never be shown.
     */
    public function isPlaceholder(?string $email): bool
    {
        return $this->placeholderEmail->isPlaceholder($email);
    }
}
