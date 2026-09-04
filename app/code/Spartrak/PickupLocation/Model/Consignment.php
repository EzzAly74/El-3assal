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

    public function getDestinationId(): ?int
    {
        $value = $this->getData(self::DESTINATION_ID);

        return $value !== null && (int) $value > 0 ? (int) $value : null;
    }

    public function getDestinationName(): ?string
    {
        return $this->nonEmpty(self::DESTINATION_NAME);
    }

    public function getDestinationAddress(): ?string
    {
        return $this->nonEmpty(self::DESTINATION_ADDRESS);
    }

    public function getDestinationReason(): ?string
    {
        return $this->nonEmpty(self::DESTINATION_REASON);
    }

    public function setDestination(
        ?int $depotId,
        ?string $name,
        ?string $address,
        ?string $reason
    ): ConsignmentInterface {
        $name = $name !== null ? trim($name) : null;

        /**
         * An override with no NAME is not an override — see
         * hasDestination() for why the name is the test. So a blank name
         * clears the whole set rather than leaving an id pointing at a
         * destination the card cannot print, which is the half-applied state
         * the single setter exists to make unrepresentable.
         */
        if ($name === null || $name === '') {
            return $this->setData(self::DESTINATION_ID, null)
                ->setData(self::DESTINATION_NAME, null)
                ->setData(self::DESTINATION_ADDRESS, null)
                ->setData(self::DESTINATION_REASON, null);
        }

        $address = $address !== null ? trim($address) : null;
        $reason = $reason !== null ? trim($reason) : null;

        return $this->setData(self::DESTINATION_ID, $depotId !== null && $depotId > 0 ? $depotId : null)
            ->setData(self::DESTINATION_NAME, $name)
            ->setData(self::DESTINATION_ADDRESS, $address === '' ? null : $address)
            ->setData(self::DESTINATION_REASON, $reason === '' ? null : $reason);
    }

    public function hasDestination(): bool
    {
        return $this->getDestinationName() !== null;
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::CREATED_AT);

        return $value !== null ? (string) $value : null;
    }

    private function nonEmpty(string $key): ?string
    {
        $value = trim((string) $this->getData($key));

        return $value !== '' ? $value : null;
    }

    /**
     * All five admin-supplied facts present.
     *
     * The photograph counts. BUSINESS.md section 12, §4 is explicit about why —
     * it is "a photo of the ACTUAL vehicle carrying the shipment, so the
     * customer can identify it in a yard full of near-identical microbuses".
     * A card with a driver's name and no picture does not do the job the card
     * exists for, so it is not "complete".
     *
     * THE DESTINATION IS DELIBERATELY NOT COUNTED. It is the customer's own
     * choice, not one of the admin's five facts, and §6 is explicit that
     * dispatch is not blocked when it is missing: the parcel still has to
     * travel and the driver's number is still the customer's lifeline, so
     * withholding a dispatch over a column this module failed to write would
     * punish the customer for our fault. It is surfaced as a named data fault
     * instead, with somewhere to fix it — see Model\OrderDestination and the
     * admin panel's destination field.
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
