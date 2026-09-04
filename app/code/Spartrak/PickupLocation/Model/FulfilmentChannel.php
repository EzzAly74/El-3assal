<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model;

use Magento\Framework\Phrase;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * HOW is this order being fulfilled? One answer, one place, three values.
 *
 * ===========================================================================
 * WHY THIS CLASS EXISTS
 * ===========================================================================
 * This storefront fulfils an order in one of three genuinely different ways,
 * and almost every operational question downstream depends on which:
 *
 *   delivery (`الشحن`)          a courier brings it to the customer's address
 *   branch   (`استلام من الفرع`) the customer collects from an ElAssal branch
 *   depot    (`استلام من الموقف`) it travels on an inter-city vehicle with a
 *                                NAMED DRIVER, and the customer collects it at
 *                                a transport station
 *
 * Those are not three labels on one process. They have different actors,
 * different terminal events, different things the customer must be told, and
 * different people to chase when something goes wrong. See the channel matrix
 * in `.claude/elassal-pickup-mawqef-feature.md`.
 *
 * Before this class, each consumer asked the question its own way and they did
 * not agree:
 *
 *   ViewModel\OrderPickup        read the `spartrak_pickup_type` snapshot
 *   Plugin\Checkout\ApplyPickupLocation
 *                                read the carrier code off the checkout payload
 *   CustomerAccount\OrderProgress didn't ask at all — one rail for all three
 *
 * ===========================================================================
 * THE DERIVATION ORDER, AND WHY IT IS THIS WAY ROUND
 * ===========================================================================
 * 1. `sales_order.shipping_method`  — CORE's column. Written on every
 *    non-virtual order, carried across the quote/order boundary by core's own
 *    fieldset, and impossible for this module to fail to populate.
 * 2. `sales_order_address.spartrak_pickup_type` — THIS module's snapshot. One
 *    extra copy, carried by one extra fieldset aspect.
 *
 * It used to be (2) only, and that is a fragile place to hang a whole feature:
 * a depot order whose snapshot did not land reported itself as a home delivery,
 * so the admin's "Station consignment" form — the ONLY way to record the driver
 * the customer needs — did not render at all. The one case where a dispatcher
 * most needs the form is the case that hid it.
 *
 * Reading the carrier first makes the channel a fact about the order rather
 * than a fact about this module's bookkeeping. The snapshot is still what says
 * WHICH branch or depot; when the carrier says pickup and the snapshot is
 * missing, that is a real, nameable data fault (`hasLocationSnapshot()`) which
 * the admin surfaces instead of silently degrading.
 */
class FulfilmentChannel
{
    /** A courier brings it to the customer. */
    public const DELIVERY = 'delivery';

    public function __construct(
        private readonly OrderPickupSnapshot $snapshot
    ) {
    }

    /**
     * @return string self::DELIVERY | PickupType::BRANCH | PickupType::DEPOT
     */
    public function get(?OrderInterface $order): string
    {
        if ($order === null) {
            return self::DELIVERY;
        }

        $fromCarrier = PickupType::fromShippingMethod($order->getShippingMethod());

        if ($fromCarrier !== null) {
            return $fromCarrier;
        }

        /**
         * The snapshot as a second opinion, not as the primary source.
         *
         * Reached only when the carrier is silent, which means either a genuine
         * home delivery or an order whose `shipping_method` is empty — a
         * virtual order, or one built by an integration that never set it. If
         * the snapshot nevertheless says `depot`, that is more information than
         * "delivery" and it is honoured.
         */
        $fromSnapshot = $this->snapshot->getType($order);

        return PickupType::isValid($fromSnapshot) ? $fromSnapshot : self::DELIVERY;
    }

    public function isPickup(?OrderInterface $order): bool
    {
        return $this->get($order) !== self::DELIVERY;
    }

    public function isDepot(?OrderInterface $order): bool
    {
        return $this->get($order) === PickupType::DEPOT;
    }

    public function isBranch(?OrderInterface $order): bool
    {
        return $this->get($order) === PickupType::BRANCH;
    }

    /**
     * Did this module's own location snapshot survive onto the order?
     *
     * FALSE on a pickup order is a DATA FAULT worth showing a human, not a
     * reason to hide the feature: the channel is still known (the carrier says
     * so), the parcel still has to reach a station, and the dispatcher still
     * has to record a driver. What is missing is the customer's chosen
     * destination — which somebody now has to establish and set by hand.
     *
     * Always true for a delivery, which carries no snapshot by design.
     */
    public function hasLocationSnapshot(?OrderInterface $order): bool
    {
        if (!$this->isPickup($order)) {
            return true;
        }

        return $this->snapshot->getName($order) !== null;
    }

    /**
     * The channel, in the words the design and the business use for it.
     *
     * These are the segmented control's own words (Figma 554:13250), so the
     * order page names the channel exactly as the shopper chose it at checkout.
     */
    public function getLabel(?OrderInterface $order): Phrase
    {
        return match ($this->get($order)) {
            PickupType::BRANCH => __('Collect from branch'),
            PickupType::DEPOT => __('Collect from depot'),
            default => __('Shipping'),
        };
    }

    /**
     * The heading for the panel that names WHERE the order is going —
     * `عنوان الشحن` for a delivery, and the collection point otherwise.
     *
     * Three answers rather than the two it used to have. "Collection point" for
     * both pickup kinds read as a euphemism on the depot channel, where the
     * place is a public transport station the shopper has to travel to, not a
     * counter of ours.
     */
    public function getDestinationHeading(?OrderInterface $order): Phrase
    {
        return match ($this->get($order)) {
            PickupType::BRANCH => __('Collection branch'),
            PickupType::DEPOT => __('Collection station'),
            default => __('Shipping address'),
        };
    }
}
