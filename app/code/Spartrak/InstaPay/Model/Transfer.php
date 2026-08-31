<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\InstaPay\Model;

use Magento\Framework\Model\AbstractModel;
use Spartrak\InstaPay\Api\Data\TransferInterface;
use Spartrak\InstaPay\Model\ResourceModel\Transfer as TransferResource;

/**
 * One proof-of-transfer record.
 *
 * Typed accessors over AbstractModel's magic getters. `getData('order_id')`
 * returns a string on one path and an int on another depending on whether the
 * row came from the database or from a setter, and a comparison against an
 * order id then quietly does the wrong thing. Casting once, here, means every
 * caller gets the type the interface promises.
 */
class Transfer extends AbstractModel implements TransferInterface
{
    protected function _construct(): void
    {
        $this->_init(TransferResource::class);
    }

    public function getTransferId(): ?int
    {
        $id = $this->getData(self::TRANSFER_ID);

        return $id === null ? null : (int) $id;
    }

    public function getOrderId(): int
    {
        return (int) $this->getData(self::ORDER_ID);
    }

    public function setOrderId(int $orderId): TransferInterface
    {
        return $this->setData(self::ORDER_ID, $orderId);
    }

    public function getQuoteId(): ?int
    {
        $id = $this->getData(self::QUOTE_ID);

        return $id === null ? null : (int) $id;
    }

    public function setQuoteId(?int $quoteId): TransferInterface
    {
        return $this->setData(self::QUOTE_ID, $quoteId);
    }

    public function getCustomerPhone(): string
    {
        return (string) $this->getData(self::CUSTOMER_PHONE);
    }

    public function setCustomerPhone(string $phone): TransferInterface
    {
        return $this->setData(self::CUSTOMER_PHONE, $phone);
    }

    public function getProofPath(): string
    {
        return (string) $this->getData(self::PROOF_PATH);
    }

    public function setProofPath(string $path): TransferInterface
    {
        return $this->setData(self::PROOF_PATH, $path);
    }

    public function getOriginalName(): ?string
    {
        $name = $this->getData(self::ORIGINAL_NAME);

        return $name === null ? null : (string) $name;
    }

    public function setOriginalName(?string $name): TransferInterface
    {
        return $this->setData(self::ORIGINAL_NAME, $name);
    }

    public function getFileSize(): ?int
    {
        $size = $this->getData(self::FILE_SIZE);

        return $size === null ? null : (int) $size;
    }

    public function setFileSize(?int $bytes): TransferInterface
    {
        return $this->setData(self::FILE_SIZE, $bytes);
    }

    public function getStatus(): string
    {
        return (string) ($this->getData(self::STATUS) ?: self::STATUS_PENDING);
    }

    public function setStatus(string $status): TransferInterface
    {
        return $this->setData(self::STATUS, $status);
    }

    public function getReviewedBy(): ?string
    {
        $by = $this->getData(self::REVIEWED_BY);

        return $by === null ? null : (string) $by;
    }

    public function setReviewedBy(?string $username): TransferInterface
    {
        return $this->setData(self::REVIEWED_BY, $username);
    }

    public function getReviewedAt(): ?string
    {
        $at = $this->getData(self::REVIEWED_AT);

        return $at === null ? null : (string) $at;
    }

    public function setReviewedAt(?string $at): TransferInterface
    {
        return $this->setData(self::REVIEWED_AT, $at);
    }

    public function getCreatedAt(): ?string
    {
        $at = $this->getData(self::CREATED_AT);

        return $at === null ? null : (string) $at;
    }
}
