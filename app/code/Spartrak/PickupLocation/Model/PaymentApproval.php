<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;

/**
 * "HAS THE MONEY BEEN ACCEPTED?" — asked in one place, by two consumers.
 *
 * ===========================================================================
 * WHY THIS IS A CLASS AND NOT A LINE IN THE BLOCK
 * ===========================================================================
 * §2's transition table puts the consignment one station AFTER payment
 * acceptance. Enforcing that needs the same answer in two places — the panel
 * that decides whether to enable the form, and the controller that decides
 * whether to accept the post — and a rule written twice is a rule that
 * eventually disagrees with itself. It is the same argument
 * Model\ConsignmentRequirements makes about the missing-field list, which the
 * panel and the dispatch gate both read.
 *
 * A disabled fieldset is a courtesy, not a control: anyone can post the form
 * anyway. So the panel's version of this question must never be the only one.
 *
 * ===========================================================================
 * AN INVOICE IS THE TEST, AND THE OTHER CANDIDATES ARE WORSE
 * ===========================================================================
 * §2: payment acceptance on this store is "an offline invoice is raised
 * (InstaPay approval, or an admin invoicing COD)". So an invoice exists.
 *
 *   `total_paid > 0`      wrong on a legitimately free order, and wrong for an
 *                         invoice raised but not yet captured.
 *   `state === processing` an order can reach `processing` for reasons that
 *                         have nothing to do with money — a payment method
 *                         that sets it on authorisation, an integration.
 *   `hasInvoices()`       exactly the fact §2 names.
 *
 * The second clause is a SAFETY NET, not a second rule: an order already at or
 * beyond `تم التعبئة` has plainly been approved by somebody, and an order that
 * was dispatched before this gate existed must not become uneditable because
 * of it. Without it, deploying this rule would freeze the driver details on
 * every order already in flight.
 */
class PaymentApproval
{
    /**
     * The stations that can only have been reached after payment.
     */
    private const AFTER_APPROVAL = [
        DeliveryStatus::PACKED,
        DeliveryStatus::OUT_FOR_DELIVERY,
        DeliveryStatus::DELIVERED,
    ];

    public function isApproved(?OrderInterface $order): bool
    {
        if ($order === null) {
            return false;
        }

        /**
         * `hasInvoices()` is on the concrete order model rather than the
         * service contract, so the instanceof is real rather than defensive
         * padding — a repository always returns the model, but an order handed
         * in as a plain OrderInterface implementation would not have it, and
         * the status test below still answers for that case.
         */
        if ($order instanceof Order && $order->hasInvoices()) {
            return true;
        }

        return in_array((string) $order->getStatus(), self::AFTER_APPROVAL, true);
    }
}
