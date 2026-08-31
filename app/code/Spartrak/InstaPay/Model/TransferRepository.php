<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\InstaPay\Model;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Spartrak\InstaPay\Api\Data\TransferInterface;
use Spartrak\InstaPay\Api\TransferRepositoryInterface;
use Spartrak\InstaPay\Model\ResourceModel\Transfer as TransferResource;
use Spartrak\InstaPay\Model\ResourceModel\Transfer\CollectionFactory;

class TransferRepository implements TransferRepositoryInterface
{
    public function __construct(
        private readonly TransferResource $resource,
        private readonly TransferFactory $transferFactory,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function save(TransferInterface $transfer): TransferInterface
    {
        try {
            /** @var Transfer $transfer */
            $this->resource->save($transfer);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('The transfer could not be saved.'), $e);
        }

        return $transfer;
    }

    public function getById(int $transferId): TransferInterface
    {
        $transfer = $this->transferFactory->create();
        $this->resource->load($transfer, $transferId);

        if (!$transfer->getId()) {
            throw new NoSuchEntityException(__('No transfer with ID "%1" exists.', $transferId));
        }

        return $transfer;
    }

    /**
     * @return TransferInterface[]
     */
    public function getByOrderId(int $orderId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(TransferInterface::ORDER_ID, $orderId);
        // Newest first: the most recent attempt is the one a reviewer acts on.
        $collection->setOrder(TransferInterface::TRANSFER_ID, 'DESC');

        return array_values($collection->getItems());
    }
}
