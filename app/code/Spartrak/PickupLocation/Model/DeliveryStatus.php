<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model;

use Magento\Sales\Model\Order;

/**
 * The four fulfilment statuses the storefront's tracking stepper runs on.
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
 * old structural derivation as a FALLBACK so an order moved through Magento's
 * ordinary invoice/ship flow still lands on a sensible station.
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
 * A freshly placed order therefore carries core's `pending`, and OrderProgress
 * maps state `new` to station one regardless of which status it holds. The
 * Spartrak statuses are what the admin MOVES an order to, not what it starts
 * with.
 */
final class DeliveryStatus
{
    /** بانتظار الموافقة */
    public const AWAITING_APPROVAL = 'spartrak_awaiting_approval';

    /** تم التعبئة */
    public const PACKED = 'spartrak_packed';

    /** شحنتك في الطريق اليك — the status whose gate this module enforces. */
    public const OUT_FOR_DELIVERY = 'spartrak_out_for_delivery';

    /** تم التوصيل */
    public const DELIVERED = 'spartrak_delivered';

    /**
     * status code => [label, state it is assigned to].
     *
     * The labels are English source strings; ar_EG.csv carries the wording
     * BUSINESS.md section 12 specifies. They are NOT stored as Arabic here,
     * because a status label lives in sales_order_status_label per store view
     * and the admin's own store view is English.
     *
     * @var array<string, array{label: string, state: string}>
     */
    private const STATUSES = [
        self::AWAITING_APPROVAL => ['label' => 'Awaiting approval', 'state' => Order::STATE_NEW],
        self::PACKED => ['label' => 'Packed', 'state' => Order::STATE_PROCESSING],
        self::OUT_FOR_DELIVERY => ['label' => 'Out for delivery', 'state' => Order::STATE_PROCESSING],
        self::DELIVERED => ['label' => 'Delivered', 'state' => Order::STATE_COMPLETE],
    ];

    /**
     * @return array<string, array{label: string, state: string}>
     */
    public static function all(): array
    {
        return self::STATUSES;
    }

    public static function isSpartrakStatus(?string $status): bool
    {
        return $status !== null && isset(self::STATUSES[$status]);
    }
}
