<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Api\Data;

/**
 * The driver and vehicle carrying one order to a station — `موقف` pickup.
 *
 * BUSINESS.md section 12, §4: the six facts the admin must supply when an
 * order goes out for delivery. Five of them live here; the sixth,
 * `الي الموقف`, is normally the customer's own checkout choice, snapshotted
 * onto the order's shipping address as `spartrak_pickup_name` and read from
 * there rather than copied.
 *
 * The destination fields at the bottom of this interface are the EXCEPTION to
 * that, and section 9 question 5 is the decision they implement: the admin may
 * record a destination when the checkout snapshot never landed, and may
 * redirect one when the station the customer chose is unreachable on the
 * route. They are an OVERRIDE, not a copy — the order's own snapshot is never
 * rewritten — and Model\OrderDestination is the single place that resolves
 * `override ?? snapshot` for every consumer.
 *
 * A service contract rather than a bare model, because two things outside this
 * module read it — the storefront order page and the admin order view — and
 * because the gate in Plugin\Sales\RequireConsignmentBeforeDispatch has to be
 * able to ask "is this complete?" without knowing how it is stored.
 */
interface ConsignmentInterface
{
    public const CONSIGNMENT_ID = 'consignment_id';
    public const ORDER_ID = 'order_id';
    public const DRIVER_NAME = 'driver_name';
    public const DRIVER_PHONE = 'driver_phone';
    public const PLATE_NUMBER = 'plate_number';
    public const ORIGIN_STATION = 'origin_station';
    public const VEHICLE_PHOTO = 'vehicle_photo';
    public const DESTINATION_ID = 'destination_id';
    public const DESTINATION_NAME = 'destination_name';
    public const DESTINATION_ADDRESS = 'destination_address';
    public const DESTINATION_REASON = 'destination_reason';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    public function getConsignmentId(): ?int;

    public function getOrderId(): int;

    public function setOrderId(int $orderId): self;

    /**
     * اسم السائق
     */
    public function getDriverName(): string;

    public function setDriverName(string $name): self;

    /**
     * رقم هاتف السائق — the number the customer calls to arrange the handover.
     */
    public function getDriverPhone(): string;

    public function setDriverPhone(string $phone): self;

    /**
     * رقم لوحة العربية. Free text — see db_schema.xml for why.
     */
    public function getPlateNumber(): string;

    public function setPlateNumber(string $plate): self;

    /**
     * من الموقف — written per order by the admin, not derived from a route.
     */
    public function getOriginStation(): string;

    public function setOriginStation(string $station): self;

    /**
     * The vehicle photograph, as a path relative to the pickup media directory.
     */
    public function getVehiclePhoto(): string;

    public function setVehiclePhoto(string $path): self;

    /**
     * `الي الموقف`, recorded by the admin — the depot's id, or null when the
     * order's own snapshot is being used (the normal case).
     */
    public function getDestinationId(): ?int;

    public function getDestinationName(): ?string;

    public function getDestinationAddress(): ?string;

    /**
     * Why the destination was recorded or changed. Required when it REPLACES a
     * station the customer chose; optional when it fills a blank the checkout
     * failed to record, because there is no customer decision being overruled.
     */
    public function getDestinationReason(): ?string;

    /**
     * Sets all four at once, or clears them all with nulls.
     *
     * One setter rather than four, because the four are one fact and a
     * half-applied override — an id with no name, a reason with no station —
     * has no meaning the resolver could act on.
     */
    public function setDestination(
        ?int $depotId,
        ?string $name,
        ?string $address,
        ?string $reason
    ): self;

    /**
     * Has a destination been recorded here at all?
     *
     * The NAME is the test, not the id: the id is what makes the record
     * joinable and can be nulled by a depot being deleted, while the name is
     * what the customer's card actually prints.
     */
    public function hasDestination(): bool;

    public function getCreatedAt(): ?string;

    /**
     * Whether every fact the customer card needs is present.
     *
     * The question the dispatch gate asks. It lives on the DTO rather than in
     * the plugin so the admin form, the gate and the storefront all agree on
     * what "filled in" means — the spec's whole point is that a half-filled
     * consignment must not let the status through.
     */
    public function isComplete(): bool;
}
