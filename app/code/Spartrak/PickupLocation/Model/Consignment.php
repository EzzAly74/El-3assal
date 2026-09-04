<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model;

use Magento\Framework\Model\AbstractModel;
use Spartrak\PickupLocation\Api\Data\ConsignmentInterface;
use Spartrak\PickupLocation\Model\ResourceModel\Consignment as ConsignmentResource;

/**
 * The driver/vehicle record for one order.
 *
 * An AbstractModel rather than a plain DTO, for the same reason this module's
 * Branch, Depot and Operator are: the admin form is a Magento UI component,
 * which expects to be fed and saved through the model/resource pair, and the
 * repository is the seam that keeps that detail out of the callers.
 */
class Consignment extends AbstractModel implements ConsignmentInterface
{
    protected function _construct(): void
    {
        $this->_init(ConsignmentResource::class);
    }

    public function getConsignmentId(): ?int
    {
        $id = $this->getData(self::CONSIGNMENT_ID);

        return $id === null ? null : (int) $id;
    }

    public function getOrderId(): int
    {
        return (int) $this->getData(self::ORDER_ID);
    }

    public function setOrderId(int $orderId): ConsignmentInterface
    {
        return $this->setData(self::ORDER_ID, $orderId);
    }

    public function getDriverName(): string
    {
        return (string) $this->getData(self::DRIVER_NAME);
    }

    public function setDriverName(string $name): ConsignmentInterface
    {
        return $this->setData(self::DRIVER_NAME, $name);
    }

    public function getDriverPhone(): string
    {
        return (string) $this->getData(self::DRIVER_PHONE);
    }

    public function setDriverPhone(string $phone): ConsignmentInterface
    {
        return $this->setData(self::DRIVER_PHONE, $phone);
    }

    public function getPlateNumber(): string
    {
        return (string) $this->getData(self::PLATE_NUMBER);
    }

    public function setPlateNumber(string $plate): ConsignmentInterface
    {
        return $this->setData(self::PLATE_NUMBER, $plate);
    }

    public function getOriginStation(): string
    {
        return (string) $this->getData(self::ORIGIN_STATION);
    }

    public function setOriginStation(string $station): ConsignmentInterface
    {
        return $this->setData(self::ORIGIN_STATION, $station);
    }

    public function getVehiclePhoto(): string
    {
        return (string) $this->getData(self::VEHICLE_PHOTO);
    }

    public function setVehiclePhoto(string $path): ConsignmentInterface
    {
        return $this->setData(self::VEHICLE_PHOTO, $path);
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::CREATED_AT);

        return $value !== null ? (string) $value : null;
    }

    /**
     * All five admin-supplied facts present.
     *
     * The photograph counts. BUSINESS.md section 12, §4 is explicit about why —
     * it is "a photo of the ACTUAL vehicle carrying the shipment, so the
     * customer can identify it in a yard full of near-identical microbuses".
     * A card with a driver's name and no picture does not do the job the card
     * exists for, so it is not "complete".
     */
    public function isComplete(): bool
    {
        foreach ([
            $this->getDriverName(),
            $this->getDriverPhone(),
            $this->getPlateNumber(),
            $this->getOriginStation(),
            $this->getVehiclePhoto(),
        ] as $value) {
            if (trim($value) === '') {
                return false;
            }
        }

        return true;
    }
}
