<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\ViewModel;

use Magento\Framework\Phrase;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Spartrak\PickupLocation\Api\ConsignmentRepositoryInterface;
use Spartrak\PickupLocation\Api\Data\ConsignmentInterface;
use Spartrak\PickupLocation\Model\DeliveryStatus;
use Spartrak\PickupLocation\Model\PickupType;
use Spartrak\PickupLocation\Model\VehiclePhotoStorage;

/**
 * The `موقف` card on the order page — Figma 573:21642, rules in
 * BUSINESS.md section 12.
 *
 * ===========================================================================
 * WHEN THE CARD IS VISIBLE — §6, AND IT IS NOT "WHEN THE DATA EXISTS"
 * ===========================================================================
 * §6 gives a table:
 *
 *     بانتظار الموافقة       hidden — the data does not exist yet
 *     تم التعبئة             hidden
 *     شحنتك في الطريق اليك   VISIBLE
 *     تم التوصيل             visible (retained as the collection record)
 *
 * It would be simpler to show the card whenever a consignment row happens to
 * exist, and that is subtly wrong: an admin who fills the form and then does
 * NOT advance the status has not dispatched anything, and a customer seeing a
 * driver's phone number for a parcel still on the shelf will ring it.
 *
 * So visibility is driven by the STATUS, and the consignment is what populates
 * it. In practice the dispatch gate keeps the two in step — the status cannot
 * be reached without the row — but the card must not depend on the gate for
 * its correctness.
 *
 * §8 records that the built mock shows the card next to
 * `حالة التوصيل الحالية: بانتظار الموافقة`, which by this rule is impossible.
 * That is called out in the spec as a sample-data bug to fix, not a rule, and
 * it is deliberately NOT reproduced here.
 *
 * ===========================================================================
 * BRANCH PICKUP IS NOT `موقف`
 * ===========================================================================
 * Collecting from an ElAssal branch has no driver and no vehicle — the customer
 * comes to us. The card is for DEPOT pickup only, which is the `موقف` this
 * document specifies. `سوبر جيت` (§7 question 1) is out of scope and has no
 * carrier of its own yet; nothing here pretends to cover it.
 */
class OrderConsignment implements ArgumentInterface
{
    public function __construct(
        private readonly ConsignmentRepositoryInterface $consignments,
        private readonly OrderPickup $pickup,
        private readonly VehiclePhotoStorage $photoStorage
    ) {
    }

    /**
     * Should the card render at all?
     */
    public function isVisible(?OrderInterface $order): bool
    {
        if ($order === null || $this->pickup->getType($order) !== PickupType::DEPOT) {
            return false;
        }

        if (!$this->isDispatched($order)) {
            return false;
        }

        $consignment = $this->get($order);

        return $consignment !== null && $consignment->isComplete();
    }

    public function get(?OrderInterface $order): ?ConsignmentInterface
    {
        if ($order === null) {
            return null;
        }

        $orderId = (int) $order->getEntityId();

        return $orderId !== 0 ? $this->consignments->getByOrderId($orderId) : null;
    }

    /**
     * The card's heading — "تفاصيل طلب موقف السلام".
     *
     * The station interpolated into it is `الي الموقف`, the depot the customer
     * chose at checkout, taken from the order's own snapshot.
     *
     * §8 flags the mock for titling the card `موقف السلام` while its
     * `الي الموقف` row reads `بنها` — two different destinations on one card.
     * Building the title from the same value the row prints makes that
     * particular contradiction unrepresentable.
     */
    public function getTitle(?OrderInterface $order): Phrase
    {
        $station = $this->getDestinationStation($order);

        return $station !== null
            ? __('Order details for %1', $station)
            : __('Station order details');
    }

    /**
     * `الي الموقف` — the customer's checkout choice, or the destination the
     * dispatcher recorded when that choice never reached the order or the
     * station proved unreachable on the route.
     *
     * §9 question 5 is now decided, and Model\OrderDestination resolves the two
     * into one answer (`override ?? snapshot`). Nothing changes here as a
     * result: this method asks ViewModel\OrderPickup, which asks that resolver,
     * so the card's title and its `الي الموقف` row still cannot disagree —
     * which was the point of routing both through one call in the first place.
     */
    public function getDestinationStation(?OrderInterface $order): ?string
    {
        return $this->pickup->getName($order);
    }

    /**
     * The public URL of the vehicle photograph.
     */
    public function getVehiclePhotoUrl(?OrderInterface $order): ?string
    {
        $consignment = $this->get($order);

        if ($consignment === null || $consignment->getVehiclePhoto() === '') {
            return null;
        }

        $url = $this->photoStorage->getUrl($consignment->getVehiclePhoto());

        return $url !== '' ? $url : null;
    }

    /**
     * The reassurance strip's heading — §5.
     *
     * A station order reads "الأوردر مع السواق الآن" (the order is with the
     * driver now) where a couriered one reads "لقد تم شحن الطلب الخاص بك". Same
     * band, same illustration, same sub-line; different fact, because a station
     * shipment is with a person the customer is about to phone.
     */
    public function getDispatchHeading(?OrderInterface $order): Phrase
    {
        return $this->isVisible($order)
            ? __('Your order is with the driver now')
            : __('Your order has been shipped');
    }

    /**
     * Has the admin moved this order to `شحنتك في الطريق اليك` or beyond?
     *
     * Beyond, too: §6 retains the card at `تم التوصيل` as the record of who
     * handed the goods over — the one row in that table marked as an
     * assumption, kept because losing the driver's details the moment an order
     * completes would destroy the only trace of the handover.
     */
    private function isDispatched(OrderInterface $order): bool
    {
        $status = (string) $order->getStatus();

        if ($status === DeliveryStatus::OUT_FOR_DELIVERY || $status === DeliveryStatus::DELIVERED) {
            return true;
        }

        // An order completed through Magento's ordinary invoice/ship flow never
        // passes through the Spartrak statuses, and its goods have certainly
        // left the building.
        return in_array(
            (string) $order->getState(),
            [\Magento\Sales\Model\Order::STATE_COMPLETE, \Magento\Sales\Model\Order::STATE_CLOSED],
            true
        );
    }
}
