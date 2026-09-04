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
     * Is this order dispatchable right now?
     *
     * The same question the gate asks, asked BEFORE the dispatcher acts rather
     * than after — see Model\ConsignmentRequirements for why the two read one
     * list.
     */
    public function isReady(): bool
    {
        return $this->requirements->isSatisfied($this->getConsignment());
    }

    /**
     * @return array<int, Phrase> the fields still to be filled, by name
     */
    public function getMissingFields(): array
    {
        return $this->requirements->getMissing($this->getConsignment());
    }

    /**
     * Did the customer's chosen station survive onto the order?
     *
     * False is a data fault, not a delivery. It is surfaced rather than
     * silently tolerated because it is the one required fact on the customer's
     * card that the dispatcher CANNOT type in — it has to be established by
     * asking, and somebody has to notice.
     */
    public function hasDestination(): bool
    {
        return $this->pickup->hasLocationSnapshot($this->getOrder());
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
     * THE SNAPSHOT IS THE SOURCE OF TRUTH FOR NAME AND STREET, because an order
     * is a record and a depot renamed next year must not rewrite what the
     * shopper chose (db_schema.xml). The LIVE record is consulted only for the
     * facts the snapshot does not carry — governorate and operator — and only
     * to enrich, never to override. A depot deleted since the order was placed
     * therefore still shows its name and street, with the extra rows absent.
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

    /**
     * `الي الموقف` — the depot the customer chose. Shown, never collected: it
     * is already on the order and retyping it is how the two drift apart.
     */
    public function getDestinationStation(): ?string
    {
        return $this->pickup->getName($this->getOrder());
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
