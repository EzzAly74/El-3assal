<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\ViewModel;

use Magento\Framework\Phrase;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Spartrak\PickupLocation\Model\FulfilmentChannel;
use Spartrak\PickupLocation\Model\OrderPickupSnapshot;
use Spartrak\PickupLocation\Model\PickupType;

/**
 * "Where is this order being collected from?" - asked by the success page, the
 * customer's order page and the admin order view.
 *
 * A thin view-model face over two models that do the actual thinking:
 *
 *   Model\FulfilmentChannel      WHICH CHANNEL - delivery, branch or depot -
 *                                derived from core's own `shipping_method`
 *   Model\OrderPickupSnapshot    WHICH PLACE - the four `spartrak_pickup_*`
 *                                columns snapshotted onto the order
 *
 * ===========================================================================
 * WHAT CHANGED, AND WHY IT MATTERED
 * ===========================================================================
 * This class used to answer "is this a pickup?" from the SNAPSHOT alone, and
 * every getter short-circuited to null when `spartrak_pickup_type` was empty.
 * A depot order whose snapshot had not landed therefore reported itself as a
 * home delivery, and the consequence was severe rather than cosmetic: the admin
 * "Station consignment" form is gated on this answer, so the ONE screen where a
 * dispatcher records the driver the customer needs did not render at all. The
 * feature disappeared in exactly the case that needed it.
 *
 * The channel now comes from the carrier, which core writes on every order and
 * this module cannot fail to populate. A missing snapshot is reported as such
 * (`hasLocationSnapshot()`) rather than being allowed to change the answer.
 *
 * A view model rather than a block because it carries no markup and every
 * consumer already has its own block hierarchy (CLAUDE.md section 8: consume
 * view models, do not duplicate logic).
 */
class OrderPickup implements ArgumentInterface
{
    public function __construct(
        private readonly FulfilmentChannel $channel,
        private readonly OrderPickupSnapshot $snapshot
    ) {
    }

    public function isPickup(?OrderInterface $order): bool
    {
        return $this->channel->isPickup($order);
    }

    /**
     * 'branch' or 'depot', or null for a home delivery.
     *
     * Null still means "not a pickup", as it always did — but it now means it
     * because the CARRIER is a delivery carrier, not because a column this
     * module owns happened to be empty.
     */
    public function getType(?OrderInterface $order): ?string
    {
        $channel = $this->channel->get($order);

        return $channel === FulfilmentChannel::DELIVERY ? null : $channel;
    }

    public function isBranch(?OrderInterface $order): bool
    {
        return $this->channel->isBranch($order);
    }

    public function isDepot(?OrderInterface $order): bool
    {
        return $this->channel->isDepot($order);
    }

    /**
     * The chosen location's name, or null when the snapshot did not land.
     *
     * Callers must handle null on a pickup order. It is not "no pickup" — it is
     * "we know they are collecting and we have lost track of where", which is
     * an operational problem with a human fix, and the admin says so out loud.
     */
    public function getName(?OrderInterface $order): ?string
    {
        return $this->snapshot->getName($order);
    }

    public function getAddress(?OrderInterface $order): ?string
    {
        return $this->snapshot->getAddress($order);
    }

    public function getLocationId(?OrderInterface $order): ?int
    {
        return $this->snapshot->getLocationId($order);
    }

    /**
     * True when the channel says pickup and the location snapshot is intact.
     * False is the fault state described on getName().
     */
    public function hasLocationSnapshot(?OrderInterface $order): bool
    {
        return $this->channel->hasLocationSnapshot($order);
    }

    /**
     * The heading a shopper should read above the location.
     *
     * Three labels, not two. "Collect from branch" and "collect from a
     * transport station" are genuinely different promises — one is a counter of
     * ours, the other a public station the shopper travels to and meets a
     * stranger at — and the success page is where a shopper finds out whether
     * they understood what they bought.
     */
    public function getLabel(?OrderInterface $order): ?string
    {
        return match ($this->getType($order)) {
            PickupType::BRANCH, PickupType::DEPOT => (string) $this->channel->getLabel($order),
            default => null,
        };
    }

    /**
     * The heading for the panel naming WHERE the order goes — three answers,
     * one per channel. Exposed here so consumers need one dependency and not
     * two; the wording itself belongs to FulfilmentChannel.
     */
    public function getDestinationHeading(?OrderInterface $order): Phrase
    {
        return $this->channel->getDestinationHeading($order);
    }
}
