<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAccount\Model;

use Magento\Framework\Phrase;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Spartrak\PickupLocation\Model\DeliveryStatus;
use Spartrak\PickupLocation\Model\FulfilmentChannel;
use Spartrak\PickupLocation\Model\PickupType;

/**
 * The four-step delivery tracker Figma draws on the order page (562:19205 and
 * its status row 562:19211), expressed as data.
 *
 * ===========================================================================
 * THE STATIONS ARE ORDER STATUSES, WITH A STRUCTURAL FALLBACK
 * ===========================================================================
 * BUSINESS.md section 12 (`.claude/elassal-pickup-mawqef-feature.md`), §4, is
 * explicit that the admin CHANGES THE ORDER'S STATUS to
 * `شحنتك في الطريق اليك`, and that this act is the trigger which demands the
 * driver and vehicle details. So the stations are real statuses, created by
 * Spartrak_PickupLocation's AddDeliveryStatuses patch:
 *
 *     spartrak_awaiting_approval   بانتظار الموافقة        state new
 *     spartrak_packed              تم التعبئة              state processing
 *     spartrak_out_for_delivery    شحنتك في الطريق اليك    state processing
 *     spartrak_delivered           تم التوصيل              state complete
 *
 * A status alone is not enough, though. None of the four is a state's DEFAULT
 * status (see DeliveryStatus for why that would be dangerous), so a freshly
 * placed order carries core's `pending`, and an order that a merchant invoices
 * and ships the ordinary Magento way never passes through any of them.
 *
 * Each station therefore has two ways to be reached: the explicit status, or a
 * structural fact that means the same thing.
 *
 *     DELIVERED    status spartrak_delivered        | state complete / closed
 *     IN_TRANSIT   status spartrak_out_for_delivery | a shipment exists
 *     PACKED       status spartrak_packed           | state processing
 *     AWAITING     anything else
 *
 * The structural fallbacks are what stop the tracker sticking at station one
 * for a shop that has not adopted the Arabic status vocabulary yet — and
 * `hasShipments()` in particular is honest, because a shipment is created by
 * the same action that hands the parcel over.
 *
 * ===========================================================================
 * CANCELLED IS NOT A STEP
 * ===========================================================================
 * A cancelled order has not reached station four, nor is it waiting at station
 * one — it has left the rail. Figma draws no cancelled variant, so rather than
 * inventing one the tracker reports `isOffRail()` and the template falls back
 * to the order's own status label. Same for `holded`, which is a pause a
 * shopper can do nothing about.
 */
class OrderProgress
{
    public const STEP_AWAITING_APPROVAL = 0;
    public const STEP_PACKED = 1;
    public const STEP_IN_TRANSIT = 2;
    public const STEP_DELIVERED = 3;

    /**
     * Figma's own wording, in Figma's own order (562:19212-19215).
     *
     * Written left-to-right as step 0..3; the RTL page renders them from the
     * reading start, which the stylesheet handles — the DATA has one order.
     *
     * THIS IS THE DELIVERY CHANNEL'S VOCABULARY. See CHANNEL_LABELS below.
     */
    private const LABELS = [
        self::STEP_AWAITING_APPROVAL => 'Awaiting approval',
        self::STEP_PACKED => 'Packed',
        self::STEP_IN_TRANSIT => 'Your shipment is on its way',
        self::STEP_DELIVERED => 'Delivered',
    ];

    /**
     * ===========================================================================
     * FOUR STATIONS, THREE VOCABULARIES
     * ===========================================================================
     * The rail above described a COURIER DELIVERY, and it was shown unchanged to
     * shoppers who had chosen neither. On the two pickup channels it was simply
     * untrue:
     *
     *   `شحنتك في الطريق اليك` — "your shipment is on its way to you" — told a
     *   branch-collection shopper that something was travelling towards them.
     *   Nothing was. The parcel was sitting on a shelf in a shop, waiting for
     *   them to walk in.
     *
     *   `تم التوصيل` — "delivered" — told a station-collection shopper that
     *   somebody had delivered their order. Nobody had; they collected it
     *   themselves from a transport yard.
     *
     * A tracking rail that misdescribes the thing it tracks is worse than no
     * rail, because it is believed. So stations 2 and 3 take the words of the
     * channel the shopper actually chose:
     *
     *   station          delivery                  branch                 depot
     *   ---------------  ------------------------  ---------------------  ---------------------
     *   0 awaiting       بانتظار الموافقة          بانتظار الموافقة       بانتظار الموافقة
     *   1 packed         تم التعبئة                تم التعبئة             تم التعبئة
     *   2 in transit     شحنتك في الطريق اليك      جاهز للاستلام من الفرع  الشحنة مع السائق
     *   3 done           تم التوصيل                تم الاستلام            تم الاستلام
     *
     * STATIONS 0 AND 1 ARE GENUINELY SHARED, and are deliberately not
     * duplicated: "we have your order" and "it is packed" mean the same thing
     * however it leaves the building.
     *
     * NO NEW ORDER STATUSES. This changes what the four stations are CALLED, not
     * what they are — the same `spartrak_*` statuses drive them, the same
     * structural fallbacks apply, and there is nothing to configure or migrate.
     * Figma draws four stations (562:19205) and there are still four.
     *
     * @var array<string, array<int, string>>
     */
    private const CHANNEL_LABELS = [
        PickupType::BRANCH => [
            self::STEP_IN_TRANSIT => 'Ready for collection at the branch',
            self::STEP_DELIVERED => 'Collected',
        ],
        PickupType::DEPOT => [
            // Not "on its way to you": on this channel the parcel is with a
            // named driver heading for a station the shopper travels to. The
            // driver is the fact that matters, and it is what the card below
            // the rail is about.
            self::STEP_IN_TRANSIT => 'Your order is with the driver',
            self::STEP_DELIVERED => 'Collected',
        ],
    ];

    public function __construct(
        private readonly FulfilmentChannel $channel
    ) {
    }

    /**
     * Which station the order is standing at, or null when it is off the rail.
     */
    public function getStep(OrderInterface $order): ?int
    {
        if ($this->isOffRail($order)) {
            return null;
        }

        $status = (string) $order->getStatus();
        $state = (string) $order->getState();

        if (
            $status === DeliveryStatus::DELIVERED
            || $state === Order::STATE_COMPLETE
            || $state === Order::STATE_CLOSED
        ) {
            return self::STEP_DELIVERED;
        }

        // Asked before the packed test: an order can carry a shipment while its
        // state is still `processing`, and the parcel being in a vehicle is the
        // more specific fact.
        if ($status === DeliveryStatus::OUT_FOR_DELIVERY || ($order instanceof Order && $order->hasShipments())) {
            return self::STEP_IN_TRANSIT;
        }

        if ($status === DeliveryStatus::PACKED || $state === Order::STATE_PROCESSING) {
            return self::STEP_PACKED;
        }

        return self::STEP_AWAITING_APPROVAL;
    }

    /**
     * Cancelled and on-hold orders, which the rail cannot describe.
     */
    public function isOffRail(OrderInterface $order): bool
    {
        return in_array(
            (string) $order->getState(),
            [Order::STATE_CANCELED, Order::STATE_HOLDED],
            true
        );
    }

    /**
     * The four station labels, in the vocabulary of THIS order's channel.
     *
     * Takes the order, where it used to take nothing. A label list that does not
     * know which order it is labelling can only describe one channel, and it
     * described the wrong one two times out of three — see CHANNEL_LABELS.
     *
     * @return array<int, Phrase>
     */
    public function getLabels(?OrderInterface $order = null): array
    {
        $overrides = self::CHANNEL_LABELS[$this->channel->get($order)] ?? [];

        $labels = [];

        foreach (self::LABELS as $step => $label) {
            $labels[$step] = __($overrides[$step] ?? $label);
        }

        return $labels;
    }

    /**
     * The station the shopper is at, phrased for the "حالة التوصيل الحالية"
     * line in the order-details panel (562:19520).
     *
     * The STATION's label, not the order's status label. The two agree when the
     * admin uses the Arabic vocabulary and diverge when they do not — and the
     * panel sits directly beside the rail, so it has to say the same thing the
     * rail is showing or the page contradicts itself. (§8 of the spec records
     * exactly that contradiction as a bug in the built mock.)
     *
     * Off the rail, the order's own status label is the only true thing to say.
     */
    public function getCurrentLabel(OrderInterface $order): Phrase
    {
        $step = $this->getStep($order);

        if ($step === null) {
            return __((string) ($order instanceof Order ? $order->getStatusLabel() : $order->getStatus()));
        }

        // Through getLabels() rather than off LABELS directly, so this line and
        // the rail beside it cannot describe the channel differently.
        return $this->getLabels($order)[$step];
    }

    /**
     * How far along the rail the filled portion runs, 0..1.
     *
     * Returned as a ratio rather than a percentage string so the stylesheet
     * owns the unit — and so the four-station geometry lives in CSS, where the
     * rail's padding and dot size already are.
     */
    public function getCompletion(OrderInterface $order): float
    {
        $step = $this->getStep($order);

        if ($step === null) {
            return 0.0;
        }

        return $step / (count(self::LABELS) - 1);
    }

    /**
     * Has the shipment left the building?
     *
     * Asked by the order page to decide whether the dispatch band and the
     * `موقف` card belong on screen — §6's visibility table in one method, so
     * the template does not compare step numbers itself.
     */
    public function isDispatched(OrderInterface $order): bool
    {
        $step = $this->getStep($order);

        return $step === self::STEP_IN_TRANSIT || $step === self::STEP_DELIVERED;
    }
}
