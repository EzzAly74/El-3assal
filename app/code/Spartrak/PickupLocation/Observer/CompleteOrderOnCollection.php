<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Spartrak\PickupLocation\Model\DeliveryStatus;

/**
 * REACHING THE LAST STATION COMPLETES THE ORDER COMMERCIALLY TOO.
 *
 * ===========================================================================
 * WHY THE ADMIN COULD NOT COMPLETE AN ORDER
 * ===========================================================================
 * Two separate things blocked it, and both had to be fixed:
 *
 *   1. `spartrak_delivered` was assigned to state `complete` only, so it was
 *      not in the dropdown for an order sitting in `processing` — see
 *      Setup\Patch\Data\ReassignDeliveryStatusStates.
 *
 *   2. Even once it IS selectable, setting it moves Axis B and leaves Axis A
 *      exactly where it was. Magento only enters `complete` when an order is
 *      fully invoiced AND fully shipped, and nothing in this flow ever raised a
 *      shipment — so the order stayed `processing` for ever, the grid kept
 *      saying Processing, the "Ship" button kept sitting there, and no report
 *      ever counted the sale as finished.
 *
 * This closes the second half: when the goods reach the customer, the shipment
 * that says so is created, and Magento's own state machine takes the order to
 * `complete` off the back of it.
 *
 * ===========================================================================
 * WHY THE SHIPMENT IS CREATED AT COLLECTION AND NOT AT DISPATCH
 * ===========================================================================
 * Dispatch was considered, and it is wrong for one of the three channels. On
 * `استلام من الفرع` the parcel at station 2 is `جاهز للاستلام من الفرع` — sitting
 * on a shelf in our own branch, not shipped anywhere. Creating a shipment there
 * would claim the goods had left our possession while they were still in it.
 *
 * Collection is the one event that means the same thing on all three channels:
 * a courier handed it over, or the customer took it off a branch counter, or
 * they met the driver at a station. One rule, three channels, no per-channel
 * exception to keep in step.
 *
 * The cost is that an order in transit carries no Magento shipment. That is
 * acceptable: the STATUS says `شحنتك في الطريق اليك` and the driver card says
 * who has it, which is more than a shipment row would.
 *
 * ===========================================================================
 * `sales_order_save_commit_after`, NOT `_save_after`
 * ===========================================================================
 * Creating a shipment writes — several rows, through a service that takes its
 * own lock on the order. Doing that from `sales_order_save_after` would nest it
 * inside the transaction of the save that triggered it, where a failure rolls
 * back both and a success commits neither on its own.
 * `afterCommitCallback()` fires once the order's own transaction has committed,
 * which is the only safe place for a side effect that saves.
 *
 * ===========================================================================
 * IT CANNOT LOOP
 * ===========================================================================
 * `ShipOrderInterface::execute()` saves the order, which fires this event
 * again — and by then `canShip()` is false, so the second pass returns
 * immediately. The guard is a fact about the order rather than a flag that
 * could be left set.
 *
 * ===========================================================================
 * AND IT DOES NOT FIGHT THE STATUS BACK
 * ===========================================================================
 * Shipping makes Magento's state handler stamp `complete`'s default status over
 * `spartrak_delivered`. Nothing here re-asserts it, because
 * Plugin\Sales\KeepFulfilmentStage already does — it runs inside that same save
 * and restores any of this module's four stations that the handler replaced.
 * Two places doing it would be two places to keep in step.
 */
class CompleteOrderOnCollection implements ObserverInterface
{
    public function __construct(
        private readonly ShipOrderInterface $shipOrder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');

        if (!$order instanceof Order) {
            return;
        }

        if ((string) $order->getStatus() !== DeliveryStatus::DELIVERED) {
            return;
        }

        /**
         * `canShip()` is Magento's own answer to "is there anything left to
         * hand over": false for a virtual order, for a cancelled or held one,
         * and for one already fully shipped. All four are cases to leave alone.
         */
        if (!$order->canShip()) {
            return;
        }

        $orderId = (int) $order->getEntityId();

        if ($orderId === 0) {
            return;
        }

        try {
            // No notification and no comment: §7 is explicit that this
            // storefront sends none, and a shipment email would be the first.
            $this->shipOrder->execute($orderId, [], false, false);
        } catch (\Exception $e) {
            /**
             * Logged and swallowed. The status change the admin made IS saved
             * by this point — the order says `تم الاستلام`, the customer's rail
             * is right, and the driver card is retained as the collection
             * record. What has not happened is the commercial completion, and
             * the admin can still press "Ship" by hand.
             *
             * Throwing here would be worse in both directions: it cannot undo
             * the committed status change, and it would surface as an
             * unexplained error on a screen where the thing the admin asked for
             * had in fact succeeded.
             */
            $this->logger->error(
                'Spartrak Pickup: an order reached the collection stage but could not be shipped, '
                . 'so it has not completed. Create the shipment by hand.',
                [
                    'order_id' => $orderId,
                    'increment_id' => (string) $order->getIncrementId(),
                    'exception' => $e,
                ]
            );
        }
    }
}
