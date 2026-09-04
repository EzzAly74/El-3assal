<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Spartrak\PickupLocation\Model\DeliveryStatus;

/**
 * PAYMENT ACCEPTED MOVES THE ORDER TO `تم التعبئة`.
 *
 * ===========================================================================
 * THE TRANSITION §2 SPECIFIES, AND WHICH NOTHING IMPLEMENTED
 * ===========================================================================
 * The spec's transition table is explicit:
 *
 *   | station 0 -> station 1 | **payment accepted**: an offline invoice is
 *   | raised (InstaPay approval, or an admin invoicing COD)
 *   | human decision, AUTOMATIC TRANSITION |
 *
 * and §10 lists the consequence of it not being wired as a corrected defect:
 *
 *   > **Approving payment did not move the order**, so both the admin and the
 *   > customer's rail showed "pending" on an invoiced order.
 *
 * Only half of that was actually corrected. The customer's rail stopped lying
 * because Model\OrderProgress falls back to `state === processing` for station
 * one — but the ORDER ITSELF never moved: the admin's own status field still
 * read "Processing" beside fully invoiced items, and no `spartrak_*` status was
 * ever set until somebody chose one by hand. The rail was right by accident.
 *
 * ===========================================================================
 * `sales_order_invoice_register`, AND WHY THAT EXACT EVENT
 * ===========================================================================
 * It is dispatched inside `Invoice::register()`, with the order in the payload
 * and BEFORE the order is saved. So the status set here rides the same save the
 * invoice does — one write, one history entry, no second transaction that
 * could fail on its own and leave the two out of step.
 *
 * `sales_order_invoice_pay` was the alternative and is narrower than the rule:
 * an offline invoice on this store is raised and paid in one action, but a
 * capture-later payment method registers without paying, and the goods are
 * packed either way once the invoice exists.
 *
 * Magento's own state handler will move the order from `new` to `processing`
 * during the same save and stamp `processing`'s default status over this one —
 * which is what Plugin\Sales\KeepFulfilmentStage exists to undo.
 *
 * ===========================================================================
 * IT NEVER MOVES AN ORDER BACKWARDS
 * ===========================================================================
 * A second invoice on a partially-invoiced order, or an admin invoicing
 * something that is already out with a driver, must not reset the rail to
 * "packed". So the stamp applies only to an order that has not yet reached a
 * later station — and any status that is NOT one of this module's four is
 * treated as station zero, because that is what it is: an order still carrying
 * core's `pending` has not started moving.
 */
class StampPackedOnPaymentApproval implements ObserverInterface
{
    /**
     * The stations that are already at or beyond `تم التعبئة`.
     */
    private const AT_OR_BEYOND_PACKED = [
        DeliveryStatus::PACKED,
        DeliveryStatus::OUT_FOR_DELIVERY,
        DeliveryStatus::DELIVERED,
    ];

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');

        if (!$order instanceof Order) {
            return;
        }

        /**
         * An order that is being cancelled, held or refunded is not being
         * packed. `canUnhold()` is Magento's own "is it on hold" test.
         */
        if ($order->isCanceled() || $order->canUnhold()) {
            return;
        }

        if (in_array((string) $order->getStatus(), self::AT_OR_BEYOND_PACKED, true)) {
            return;
        }

        if (!DeliveryStatus::isAllowedInState(DeliveryStatus::PACKED, Order::STATE_PROCESSING)) {
            // Defensive: the station's own definition says where it may stand,
            // and if somebody narrows it this observer must not force it.
            return;
        }

        $order->setStatus(DeliveryStatus::PACKED);
    }
}
