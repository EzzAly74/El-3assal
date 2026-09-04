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
 * `الي الموقف`, is already on the order's shipping address as
 * `spartrak_pickup_name` and is read from there rather than copied.
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
