<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\InstaPay\Api;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Spartrak\InstaPay\Api\Data\TransferInterface;

interface TransferRepositoryInterface
{
    /**
     * @throws CouldNotSaveException
     */
    public function save(TransferInterface $transfer): TransferInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $transferId): TransferInterface;

    /**
     * Every transfer submitted for an order, newest first.
     *
     * Plural because a rejected receipt is followed by another attempt, and
     * both have to remain visible - the rejected one is the reason the second
     * exists.
     *
     * @return TransferInterface[]
     */
    public function getByOrderId(int $orderId): array;
}
