<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model;

use Magento\Sales\Model\Order;

/**
 * The four fulfilment stations the storefront's tracking stepper runs on, and
 * the Magento states each one is allowed to stand in.
 *
 * ===========================================================================
 * WHY THESE ARE ORDER STATUSES AND NOT DERIVED STATE
 * ===========================================================================
 * The tracker was first built to derive its four stations from Magento's own
 * facts — state `processing`, "does a shipment exist", state `complete`. That
 * reads well and needs no configuration, and it is wrong for this business.
 *
 * BUSINESS.md section 12 (`.claude/elassal-pickup-mawqef-feature.md`) states
 * the rule the other way round: the admin CHANGES THE ORDER'S STATUS to
 * `شحنتك في الطريق اليك`, and that act is the trigger which demands the driver
 * and vehicle details. A status change is a deliberate human decision with a
 * moment attached to it; "a shipment row exists" is not, and cannot be gated.
 *
 * So the four stations are real statuses, created by
 * Setup\Patch\Data\AddDeliveryStatuses and assigned to the states they belong
 * to. Spartrak\CustomerAccount\Model\OrderProgress reads them, and keeps the
 * structural derivation as a FALLBACK so an order moved through Magento's
 * ordinary invoice/ship flow still lands on a sensible station.
 *
 * ===========================================================================
 * WHY EACH STATUS NOW LISTS SEVERAL STATES — AND THE BUG THAT FORCED IT
 * ===========================================================================
 * `spartrak_delivered` used to be assigned to `complete` ALONE. That looks
 * right — "delivered" is a finished order — and it made the last station of
 * the rail unreachable:
 *
 *   Magento's admin builds the status dropdown from
 *   `getStateStatuses($order->getState())`, and
 *   Controller\Adminhtml\Order\AddComment SILENTLY DISCARDS a posted status
 *   that is not assigned to the current state (`getOrderStatus()` returns the
 *   order's existing status instead). An order sitting in `processing` — which
 *   is where every invoiced, unshipped order sits — was therefore offered only
 *   Processing, Suspected Fraud, Packed and Out for delivery. There was no way
 *   to mark it collected, and no error to explain why.
 *
 * A state in Magento is a COMMERCIAL fact (is it paid, is it shipped) and these
 * statuses are a FULFILMENT fact (where are the goods). §2 of the spec is
 * explicit that the two axes are deliberately separate, so a fulfilment station
 * has to be settable in every commercial state it can legitimately coexist
 * with:
 *
 *   awaiting approval   pending_payment, new   before the money is accepted
 *   packed              processing             invoiced, still ours
 *   out for delivery    processing, complete   `complete` because an admin may
 *                                              have created the shipment at
 *                                              dispatch, which flips the state
 *                                              while the goods are still moving
 *   delivered           processing, complete   `processing` because an order
 *                                              can be collected before anybody
 *                                              raised a shipment — and
 *                                              Observer\CompleteOrderOnCollection
 *                                              then raises one, so the order
 *                                              completes commercially too
 *
 * Nothing is assigned to `canceled`, `closed` or `holded`: those are not
 * fulfilment stations, and §6 handles each of them separately.
 *
 * ===========================================================================
 * NONE OF THEM IS MADE THE DEFAULT FOR ITS STATE
 * ===========================================================================
 * Deliberate. Magento assigns a state's default status at order placement and
 * at every automatic transition, so making `spartrak_awaiting_approval` the
 * default for `new` would change what every order — including ones that never
 * go near a موقف — is stamped with, and any integration matching on `pending`
 * would quietly stop matching.
 *
 * The corollary is that Magento's own state handler stamps a state's DEFAULT
 * status whenever it moves an order between states, which would wipe a
 * fulfilment station the moment an invoice or a shipment was raised. That is
 * what Plugin\Sales\KeepFulfilmentStage exists to prevent.
 */
final class DeliveryStatus
{
    /** بانتظار الموافقة */
    public const AWAITING_APPROVAL = 'spartrak_awaiting_approval';

    /** تم التعبئة */
    public const PACKED = 'spartrak_packed';

    /** شحنتك في الطريق اليك — the status whose gate this module enforces. */
    public const OUT_FOR_DELIVERY = 'spartrak_out_for_delivery';

    /** تم التوصيل / تم الاستلام */
    public const DELIVERED = 'spartrak_delivered';

    /**
     * status code => [label, states it may be assigned to].
     *
     * The labels are English source strings; ar_EG.csv carries the wording
     * BUSINESS.md section 12 specifies. They are NOT stored as Arabic here,
     * because a status label lives in sales_order_status_label per store view
     * and the admin's own store view is English.
     *
     * The FIRST state in each list is the one the station most naturally sits
     * in; the rest are the states it must remain settable in. See the class
     * header for why each extra one is there.
     *
     * @var array<string, array{label: string, states: string[]}>
     */
    private const STATUSES = [
        self::AWAITING_APPROVAL => [
            'label' => 'Awaiting approval',
            'states' => [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT],
        ],
        self::PACKED => [
            'label' => 'Packed',
            'states' => [Order::STATE_PROCESSING],
        ],
        self::OUT_FOR_DELIVERY => [
            'label' => 'Out for delivery',
            'states' => [Order::STATE_PROCESSING, Order::STATE_COMPLETE],
        ],
        self::DELIVERED => [
            'label' => 'Delivered',
            'states' => [Order::STATE_COMPLETE, Order::STATE_PROCESSING],
        ],
    ];

    /**
     * @return array<string, array{label: string, states: string[]}>
     */
    public static function all(): array
    {
        return self::STATUSES;
    }

    public static function isSpartrakStatus(?string $status): bool
    {
        return $status !== null && isset(self::STATUSES[$status]);
    }

    /**
     * The states this station may stand in.
     *
     * @return string[]
     */
    public static function statesFor(?string $status): array
    {
        return self::STATUSES[$status]['states'] ?? [];
    }

    /**
     * May this station stand in this state?
     *
     * Read from the SAME table the setup patch assigns from, so the code's idea
     * of a legal pairing and the database's cannot drift — which is the whole
     * reason this list is here rather than in the patch.
     */
    public static function isAllowedInState(?string $status, ?string $state): bool
    {
        return $state !== null && in_array($state, self::statesFor($status), true);
    }
}
