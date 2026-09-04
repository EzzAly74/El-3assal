<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;
use Spartrak\PickupLocation\Model\FulfilmentChannel;
use Spartrak\PickupLocation\Model\LocationCatalog;
use Spartrak\PickupLocation\Model\PickupType;

/**
 * THE LAST CHANCE TO GET `الي الموقف` ONTO THE ORDER.
 *
 * ===========================================================================
 * THE FAILURE THIS EXISTS FOR
 * ===========================================================================
 * A pickup order's destination reaches the order down a chain of four links:
 *
 *   1. the browser puts `spartrak_pickup_id` in the shipping-information
 *      request        (js/model/payload-extender-mixin.js)
 *   2. Plugin\Checkout\ApplyPickupLocation resolves it and writes four columns
 *      onto the QUOTE address
 *   3. Magento's `sales_convert_quote_address` fieldset copies them to the
 *      ORDER address (etc/fieldset.xml)
 *   4. the order is saved
 *
 * Every link is sound and each is the right mechanism. But the CHANNEL comes
 * from a fifth, independent place — core's own `shipping_method` — and the
 * consequence of that asymmetry is an order that says "collected from a
 * station" while carrying no station. That is exactly the state defect §10.1
 * describes, and it is unfixable after the fact by anything except a human
 * phoning the customer.
 *
 * So this is a CHECK AT THE BOUNDARY. The one moment where both the quote and
 * the order are in hand, before either is written, is
 * `sales_model_service_quote_submit_before` — and at that moment the two
 * questions "which channel?" and "which place?" can be reconciled instead of
 * being allowed to disagree for the life of the order.
 *
 * ===========================================================================
 * IT REPAIRS. IT DOES NOT REFUSE.
 * ===========================================================================
 * Throwing here would abandon a checkout the shopper has already paid attention
 * to — and on the one path that genuinely cannot supply an id, the ADMIN's own
 * order-create screen, it would make a depot order impossible to raise at all.
 * Refusing would also be the wrong risk trade: an order with a driver and no
 * recorded destination is recoverable by a phone call (see the panel's own
 * destination field), while a checkout that dies at the last step is a lost
 * sale and a shopper who does not know why.
 *
 * What it does instead:
 *
 *   snapshot intact          nothing — the overwhelming majority
 *   id present, rest missing re-resolves the location and writes all four
 *                            columns, so a partial copy cannot survive
 *   no id anywhere           logs it as a fault, with the increment id, and
 *                            lets the order through. The admin panel then says
 *                            the destination is missing and offers the field
 *                            that records it.
 *
 * ===========================================================================
 * WHY NOT JUST FIX THE CHECKOUT
 * ===========================================================================
 * The checkout IS fixed — that is what links 1 to 3 are. This is not a patch
 * over a known bug; it is the invariant stated where it can be enforced, so
 * that a future change to any of those four links cannot silently reintroduce
 * the same class of fault. The alternative is trusting four mechanisms to stay
 * correct forever and finding out otherwise from a customer standing in a
 * station yard.
 */
class CompletePickupSnapshotOnPlacement implements ObserverInterface
{
    public function __construct(
        private readonly FulfilmentChannel $channel,
        private readonly LocationCatalog $locations,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');

        if (!$order instanceof OrderInterface) {
            return;
        }

        // The carrier is what says this is a pickup, and core writes it on
        // every order — see Model\FulfilmentChannel for why that is the primary
        // source and this module's own snapshot is not.
        if (!$this->channel->isPickup($order)) {
            return;
        }

        if ($this->channel->hasLocationSnapshot($order)) {
            return;
        }

        $address = $order->getShippingAddress();

        if (!$address instanceof OrderAddressInterface) {
            // A pickup order with no shipping address at all is a broken order,
            // not a missing snapshot, and it is not this observer's to mend.
            $this->fault($order, 'the order has no shipping address');

            return;
        }

        $locationId = $this->resolveLocationId($address, $observer->getEvent()->getData('quote'));

        if ($locationId === null) {
            $this->fault($order, 'no pickup location id survived onto the order or its quote');

            return;
        }

        // The carrier first, for the reason FulfilmentChannel records: core
        // writes it on every order and this module cannot fail to populate it.
        // The half-landed snapshot's own type is the fallback, which is what
        // an order built by an integration with no carrier would carry.
        $type = PickupType::fromShippingMethod($order->getShippingMethod());

        if ($type === null) {
            $fromSnapshot = (string) $address->getData('spartrak_pickup_type');
            $type = PickupType::isValid($fromSnapshot) ? $fromSnapshot : null;
        }

        if ($type === null) {
            $this->fault($order, 'the pickup kind could not be determined from the carrier');

            return;
        }

        $location = $type === PickupType::BRANCH
            ? $this->locations->getBranchById($locationId)
            : $this->locations->getDepotById($locationId);

        if ($location === null) {
            // Disabled or deleted between the shopper choosing it and the order
            // being placed. Nothing to copy, and inventing a name would be
            // worse than admitting the gap.
            $this->fault($order, sprintf('pickup location %d is no longer available', $locationId));

            return;
        }

        /**
         * ALL FOUR, not just the missing one. A snapshot where the name landed
         * and the address did not is the same class of fault as one where
         * nothing landed, and re-resolving the whole set from the live record
         * is what makes a partial copy unrepresentable rather than merely
         * unlikely.
         */
        $address->setData('spartrak_pickup_type', $type);
        $address->setData('spartrak_pickup_id', $locationId);
        $address->setData('spartrak_pickup_name', (string) $location['name']);
        $address->setData('spartrak_pickup_address', (string) ($location['address'] ?? ''));

        $this->logger->warning(
            'Spartrak Pickup: the pickup location snapshot did not reach an order and was rebuilt at placement.',
            [
                'increment_id' => (string) $order->getIncrementId(),
                'shipping_method' => (string) $order->getShippingMethod(),
                'pickup_type' => $type,
                'location_id' => $locationId,
            ]
        );
    }

    /**
     * The chosen location's id, from the order address first and the quote
     * address second.
     *
     * Both are consulted because they fail independently: the order address is
     * what the fieldset should have filled, and the quote address is where the
     * checkout plugin actually wrote it. If the copy is what broke, the second
     * still has the answer.
     */
    private function resolveLocationId(OrderAddressInterface $address, mixed $quote): ?int
    {
        $fromOrder = (int) $address->getData('spartrak_pickup_id');

        if ($fromOrder > 0) {
            return $fromOrder;
        }

        if (!$quote instanceof CartInterface) {
            return null;
        }

        $quoteAddress = $quote->getShippingAddress();
        $fromQuote = $quoteAddress !== null ? (int) $quoteAddress->getData('spartrak_pickup_id') : 0;

        return $fromQuote > 0 ? $fromQuote : null;
    }

    /**
     * Records a destination that could not be established, and lets the order
     * through.
     *
     * WARNING and not error: the order is valid, paid for and fulfillable — a
     * dispatcher can still record the destination by hand. What is lost is the
     * customer's own statement of where they wanted it, which is worth an alert
     * to whoever reads the logs and is not worth a failed checkout.
     *
     * The increment id is in the payload because it is the only thing that
     * makes the entry actionable: the entity id does not exist yet at this
     * point in the save.
     */
    private function fault(OrderInterface $order, string $why): void
    {
        $this->logger->warning(
            'Spartrak Pickup: a pickup order was placed with no destination on it — ' . $why . '. '
            . 'The admin order view will ask a dispatcher to record it.',
            [
                'increment_id' => (string) $order->getIncrementId(),
                'shipping_method' => (string) $order->getShippingMethod(),
            ]
        );
    }
}
