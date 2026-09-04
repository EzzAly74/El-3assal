<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Block\Adminhtml\Order\View;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Block\Adminhtml\Order\AbstractOrder;
use Magento\Sales\Helper\Admin as AdminHelper;
use Spartrak\PickupLocation\Api\ConsignmentRepositoryInterface;
use Spartrak\PickupLocation\Api\Data\ConsignmentInterface;
use Spartrak\PickupLocation\Model\ConsignmentRequirements;
use Spartrak\PickupLocation\Model\DeliveryStatus;
use Spartrak\PickupLocation\Model\LocationCatalog;
use Spartrak\PickupLocation\Model\PaymentApproval;
use Spartrak\PickupLocation\Model\PickupType;
use Spartrak\PickupLocation\Model\VehiclePhotoStorage;
use Spartrak\PickupLocation\ViewModel\OrderPickup;

/**
 * The "Station consignment" section on the admin order view.
 *
 * Where the dispatcher records the driver and vehicle that BUSINESS.md
 * section 12, §4 requires before an order may be marked out for delivery.
 *
 * Extends Magento's AbstractOrder for the same reason this module's existing
 * pickup block does: it already resolves the order being viewed, so nothing
 * here reaches into the registry or re-loads it from the request.
 */
class Consignment extends AbstractOrder
{
    public function __construct(
        Context $context,
        Registry $registry,
        AdminHelper $adminHelper,
        private readonly ConsignmentRepositoryInterface $consignments,
        private readonly OrderPickup $pickup,
        private readonly VehiclePhotoStorage $photoStorage,
        private readonly ConsignmentRequirements $requirements,
        private readonly LocationCatalog $locations,
        private readonly PaymentApproval $paymentApproval,
        array $data = []
    ) {
        parent::__construct($context, $registry, $adminHelper, $data);
    }

    /**
     * Only for `الموقف`. A branch collection has no driver, and a home
     * delivery has a courier this feature says nothing about.
     *
     * ===================================================================
     * IT NO LONGER DEPENDS ON THIS MODULE'S OWN SNAPSHOT
     * ===================================================================
     * `OrderPickup::getType()` now derives the channel from core's
     * `shipping_method` column rather than from `spartrak_pickup_type` — see
     * Model\FulfilmentChannel. That is why this section had gone missing on a
     * real depot order: the snapshot had not landed, every consumer read
     * "delivery", and the only screen that can record the driver the customer
     * needs simply did not render.
     *
     * The panel now appears for ANY order placed against the depot carrier, and
     * a missing snapshot is reported inside it (`hasDestination()`) instead of
     * removing it.
     */
    public function isApplicable(): bool
    {
        $order = $this->getOrder();

        return $order !== null && $this->pickup->getType($order) === PickupType::DEPOT;
    }

    /**
     * Has the money been accepted?
     *
     * §2 puts the consignment one station AFTER payment acceptance, and the
     * form used to be available from the moment the order existed — which let a
     * dispatcher name a driver, and publish his phone number on the customer's
     * order page, for an order nobody had been paid for.
     *
     * The rule itself lives in Model\PaymentApproval, because the CONTROLLER
     * has to enforce it too: a disabled fieldset is a courtesy and anyone can
     * post the form anyway.
     */
    public function isPaymentApproved(): bool
    {
        return $this->paymentApproval->isApproved($this->getOrder());
    }

    /**
     * Is this order dispatchable right now?
     *
     * The same question the gate asks, asked BEFORE the dispatcher acts rather
     * than after — see Model\ConsignmentRequirements for why the two read one
     * list.
     *
     * Payment is part of it: a complete consignment on an unpaid order is not
     * ready to go anywhere, and saying "ready to dispatch" there would be the
     * panel's own headline contradicting the rule its form enforces.
     */
    public function isReady(): bool
    {
        return $this->isPaymentApproved() && $this->requirements->isSatisfied($this->getConsignment());
    }

    /**
     * @return array<int, Phrase> the fields still to be filled, by name
     */
    public function getMissingFields(): array
    {
        return $this->requirements->getMissing($this->getConsignment());
    }

    /**
     * Do we know where this order is going — from the customer's checkout
     * choice, or from a destination somebody recorded here?
     *
     * False is a data fault, not a delivery, and it is surfaced rather than
     * silently tolerated. It used to be UNFIXABLE as well: the question asked
     * was whether the CHECKOUT snapshot had landed, which is an answer that can
     * never change once it is false, so the panel showed "the destination
     * station is missing from this order" for ever on precisely the orders that
     * most needed resolving. The field below is now the resolution, and this
     * asks a question that field can answer (§9 question 5).
     */
    public function hasDestination(): bool
    {
        return $this->pickup->hasDestination($this->getOrder());
    }

    /**
     * Is the destination something recorded HERE rather than the customer's own
     * choice — and if so, was it a redirect or a repair?
     *
     * The panel says which, because they are different facts about the order:
     * one is "we lost their choice and re-established it", the other is "we
     * sent it somewhere else".
     */
    public function isDestinationRedirected(): bool
    {
        return $this->pickup->isDestinationRedirected($this->getOrder());
    }

    /**
     * The station the CUSTOMER chose, whether or not it is still the
     * destination. Null when the checkout snapshot never landed.
     */
    public function getCustomerChoice(): ?string
    {
        return $this->pickup->getOriginalDestinationName($this->getOrder());
    }

    public function getDestinationReason(): ?string
    {
        return $this->pickup->getDestinationReason($this->getOrder());
    }

    /**
     * The depot the destination field should be showing as selected — the
     * override's own, or nothing when the order is running on the customer's
     * choice.
     *
     * Deliberately NOT the customer's own depot id: the empty option means
     * "use the customer's choice", so pre-selecting their station would make
     * the control claim an override that does not exist, and saving the form
     * untouched would then write one.
     */
    public function getSelectedDestinationId(): ?int
    {
        $consignment = $this->getConsignment();

        return $consignment !== null && $consignment->hasDestination()
            ? $consignment->getDestinationId()
            : null;
    }

    /**
     * The stations the dispatcher can choose from — the ACTIVE depot network,
     * in the admin's own order.
     *
     * Read through the same LocationCatalog the checkout reads, so the two
     * cannot offer different networks. A station missing from this list is a
     * station to add under Pickup Locations, which fixes checkout at the same
     * time — see the controller's applyDestination() for why this is a list and
     * not a free-text field.
     *
     * Each row is enriched with its governorate and operator in the label,
     * because `موقف السلام` on its own does not identify a station: several
     * governorates have similarly-named yards, and the operator decides which
     * vehicles run there.
     *
     * @return array<int, array{id: int, label: string}>
     */
    public function getDestinationOptions(): array
    {
        $options = [];

        foreach ($this->locations->getDepots() as $depot) {
            $label = (string) $depot['name'];
            $qualifiers = array_filter([
                $this->nonEmpty($depot['region'] ?? null),
                $this->nonEmpty($depot['operator'] ?? null),
            ]);

            if ($qualifiers !== []) {
                $label .= ' — ' . implode(', ', $qualifiers);
            }

            $options[] = ['id' => (int) $depot['id'], 'label' => $label];
        }

        return $options;
    }

    /**
     * True when a destination is recorded here whose depot is no longer in the
     * list above — disabled or deleted since.
     *
     * Worth saying out loud: the select cannot show it as selected, so without
     * this notice the panel would look as though nothing had been recorded
     * while the customer's card was printing a station.
     */
    public function isRecordedDestinationUnselectable(): bool
    {
        $consignment = $this->getConsignment();

        if ($consignment === null || !$consignment->hasDestination()) {
            return false;
        }

        $id = $consignment->getDestinationId();

        return $id === null || $this->locations->getDepotById($id) === null;
    }

    /**
     * Everything known about the station the customer chose — name, street,
     * governorate and transport operator.
     *
     * ===================================================================
     * WHY THE FULL RECORD AND NOT JUST THE NAME
     * ===================================================================
     * The panel used to print one string, the snapshotted name. "موقف السلام"
     * on its own does not tell a dispatcher which station that is: several
     * governorates have similarly-named yards, the operator decides which
     * vehicles run there, and the street is what a driver is actually told. A
     * dispatcher choosing a driver for a route needs to know the route.
     *
     * THE RECORD IS THE SOURCE OF TRUTH FOR NAME AND STREET — the customer's
     * checkout snapshot, or the destination recorded here in its place (see
     * Model\OrderDestination for the precedence). Either way it is a stored
     * value, because an order is a record and a depot renamed next year must
     * not rewrite it (db_schema.xml). The LIVE record is consulted only for the
     * facts neither snapshot carries — governorate and operator — and only to
     * enrich, never to override. A depot deleted since therefore still shows
     * its name and street, with the extra rows absent.
     *
     * @return array{name: ?string, address: ?string, region: ?string, operator: ?string}
     */
    public function getDestination(): array
    {
        $order = $this->getOrder();
        $detail = [
            'name' => $this->pickup->getName($order),
            'address' => $this->pickup->getAddress($order),
            'region' => null,
            'operator' => null,
        ];

        $locationId = $this->pickup->getLocationId($order);

        if ($locationId === null) {
            return $detail;
        }

        $live = $this->locations->getDepotById($locationId);

        if ($live === null) {
            // Disabled or deleted since the order was placed. The snapshot
            // above still describes what the shopper picked.
            return $detail;
        }

        $detail['region'] = $this->nonEmpty($live['region'] ?? null);
        $detail['operator'] = $this->nonEmpty($live['operator'] ?? null);
        // The snapshot wins where both have a value; the live record fills a
        // gap rather than correcting one.
        $detail['name'] = $detail['name'] ?? $this->nonEmpty($live['name'] ?? null);
        $detail['address'] = $detail['address'] ?? $this->nonEmpty($live['address'] ?? null);

        return $detail;
    }

    public function getConsignment(): ?ConsignmentInterface
    {
        $order = $this->getOrder();
        $orderId = $order !== null ? (int) $order->getEntityId() : 0;

        return $orderId !== 0 ? $this->consignments->getByOrderId($orderId) : null;
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl(
            'spartrak_pickup/consignment/save',
            ['order_id' => $this->getOrder()?->getEntityId()]
        );
    }

    private function nonEmpty(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    public function getVehiclePhotoUrl(): ?string
    {
        $consignment = $this->getConsignment();

        if ($consignment === null || $consignment->getVehiclePhoto() === '') {
            return null;
        }

        $url = $this->photoStorage->getUrl($consignment->getVehiclePhoto());

        return $url !== '' ? $url : null;
    }

    /**
     * `accept` for the file input, from the one place that decides what an
     * acceptable photograph is.
     */
    public function getAcceptAttribute(): string
    {
        return implode(
            ',',
            array_map(
                static fn (string $extension): string => '.' . $extension,
                $this->photoStorage->getAllowedExtensions()
            )
        );
    }

    /**
     * Whether the order is already out for delivery.
     *
     * Used only to phrase the section's help text: before dispatch it explains
     * what is required and why; after it, the details are a record.
     */
    public function isDispatched(): bool
    {
        $order = $this->getOrder();

        return $order instanceof OrderInterface
            && in_array(
                (string) $order->getStatus(),
                [DeliveryStatus::OUT_FOR_DELIVERY, DeliveryStatus::DELIVERED],
                true
            );
    }
}
