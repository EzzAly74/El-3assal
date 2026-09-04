<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model;

use Magento\Sales\Api\Data\OrderInterface;
use Spartrak\PickupLocation\Api\ConsignmentRepositoryInterface;
use Spartrak\PickupLocation\Api\Data\ConsignmentInterface;

/**
 * `الي الموقف` — WHERE IS THIS ORDER ACTUALLY GOING?
 *
 * One question, asked by five screens, answered in one place: the customer's
 * order page, the success page, the driver card, the admin order view and the
 * dispatcher's panel.
 *
 * ===========================================================================
 * TWO SOURCES, ONE ANSWER, AND THE PRECEDENCE IS THE WHOLE POINT
 * ===========================================================================
 *   1. the consignment's DESTINATION OVERRIDE — what the dispatcher recorded
 *   2. the order's own SNAPSHOT — what the customer chose at checkout
 *
 * `override ?? snapshot`, which is the resolution section 9 question 5
 * specifies. Every consumer goes through this class, so the card's title and
 * its rows cannot name two different stations — the exact contradiction the
 * spec records in the built mock (§8: a card titled `موقف السلام` whose
 * `الي الموقف` row read `بنها`).
 *
 * ===========================================================================
 * WHY THE SNAPSHOT IS NEVER REWRITTEN
 * ===========================================================================
 * It would be simpler to write the corrected station straight onto
 * `sales_order_address.spartrak_pickup_name` and delete this class. It is also
 * the one thing that must not happen: an order is a financial record, and
 * overwriting the customer's choice destroys the difference between
 *
 *     "we never recorded where they wanted it"   — our data fault
 *     "we sent it somewhere else"                — our policy decision
 *
 * with no trace of either. Keeping the two apart means `getOriginalName()` can
 * still say what the customer picked, `isOverridden()` can say that we changed
 * it, and `getReason()` can say why — which is what makes a dispute
 * answerable months later.
 *
 * ===========================================================================
 * WHY THIS IS NOT PART OF OrderPickupSnapshot
 * ===========================================================================
 * That class is the RAW snapshot and nothing else — no interpretation, no
 * second source — and FulfilmentChannel consults it to decide the channel.
 * Folding a consignment lookup into it would put a database read behind every
 * "is this a pickup?" on the order grid, and would make the channel depend on
 * a record that only exists after dispatch. The layering stays: columns →
 * snapshot, snapshot + override → destination.
 */
class OrderDestination
{
    /**
     * NO CACHE OF ITS OWN, DELIBERATELY.
     *
     * The customer's order page asks for the destination four times in one
     * render — the panel heading, the panel body, the card's title and the
     * card's row — and a memo here was the obvious answer. ConsignmentRepository
     * already keeps one, keyed by order id, INCLUDING a "asked, and there is
     * none" marker so an order with no consignment is not re-queried either.
     * More importantly it INVALIDATES that cache on save, which a private memo
     * here would not: the admin panel saves a destination and then reads it
     * back to write the order history in the same request, and a second cache
     * in the middle is exactly how that read returns the pre-save value.
     */
    public function __construct(
        private readonly OrderPickupSnapshot $snapshot,
        private readonly ConsignmentRepositoryInterface $consignments
    ) {
    }

    /**
     * The station this order is going to, whatever the source.
     *
     * Null on a pickup order means nobody knows where it is going — which is an
     * operational problem with a human fix, not "no pickup". Callers must
     * handle it; the admin panel says so out loud and offers the field that
     * resolves it.
     */
    public function getName(?OrderInterface $order): ?string
    {
        return $this->override($order)?->getDestinationName()
            ?? $this->snapshot->getName($order);
    }

    /**
     * The street address of that station.
     *
     * Taken from the SAME source as the name rather than resolved
     * independently, so an overridden destination cannot print the new
     * station's name above the old station's street. When the override
     * carries no address — a depot whose admin record has none — the answer is
     * null rather than the snapshot's, for the same reason.
     */
    public function getAddress(?OrderInterface $order): ?string
    {
        $override = $this->override($order);

        return $override !== null
            ? $override->getDestinationAddress()
            : $this->snapshot->getAddress($order);
    }

    /**
     * The depot id behind the effective destination, for callers that want to
     * enrich it from the live record (governorate, transport operator).
     */
    public function getLocationId(?OrderInterface $order): ?int
    {
        $override = $this->override($order);

        return $override !== null
            ? $override->getDestinationId()
            : $this->snapshot->getLocationId($order);
    }

    /**
     * Do we know where this order is going?
     *
     * FALSE on a pickup order is the data fault the dispatcher's panel reports
     * — and the reason it is asked here rather than of the snapshot alone is
     * that recording the destination MUST clear the warning. Before this class
     * existed the panel asked `FulfilmentChannel::hasLocationSnapshot()`, which
     * by construction could never become true again for an order whose
     * checkout snapshot had already failed to land.
     */
    public function isKnown(?OrderInterface $order): bool
    {
        return $this->getName($order) !== null;
    }

    /**
     * Is the effective destination something the admin recorded, rather than
     * the customer's own choice?
     */
    public function isOverridden(?OrderInterface $order): bool
    {
        return $this->override($order) !== null;
    }

    /**
     * Did that override REPLACE a station the customer had chosen, as opposed
     * to filling a blank?
     *
     * The distinction matters to the person reading the order: one is a
     * redirect that needs explaining, the other is a repair.
     */
    public function isRedirected(?OrderInterface $order): bool
    {
        return $this->isOverridden($order) && $this->snapshot->getName($order) !== null;
    }

    /**
     * The station the CUSTOMER chose, whether or not it is still the
     * destination. Null when the checkout snapshot never landed.
     */
    public function getOriginalName(?OrderInterface $order): ?string
    {
        return $this->snapshot->getName($order);
    }

    /**
     * Why the destination was recorded or changed, as the dispatcher wrote it.
     */
    public function getReason(?OrderInterface $order): ?string
    {
        return $this->override($order)?->getDestinationReason();
    }

    /**
     * The consignment for this order IF it carries a destination, else null.
     *
     * Returning null for a consignment that exists but has no destination is
     * what lets every getter above read as `override ?? snapshot` without
     * repeating the "and it actually has one" test five times.
     */
    private function override(?OrderInterface $order): ?ConsignmentInterface
    {
        $consignment = $this->consignment($order);

        return $consignment !== null && $consignment->hasDestination() ? $consignment : null;
    }

    private function consignment(?OrderInterface $order): ?ConsignmentInterface
    {
        $orderId = $order !== null ? (int) $order->getEntityId() : 0;

        return $orderId !== 0 ? $this->consignments->getByOrderId($orderId) : null;
    }
}
