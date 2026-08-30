<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model\Branch;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Spartrak\PickupLocation\Model\ResourceModel\Branch\CollectionFactory;

/**
 * Feeds the branch edit form.
 *
 * The DataPersistor branch is what makes a failed save survivable: the Save
 * controller stashes the rejected input under the same key, and this reads it
 * back so the admin returns to a filled form with an error message rather than
 * an empty one. Cleared immediately after, so it cannot leak into the NEXT
 * form the admin opens.
 */
class DataProvider extends AbstractDataProvider
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $loadedData = null;

    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly DataPersistorInterface $dataPersistor,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();

        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getData(): array
    {
        if ($this->loadedData !== null) {
            return $this->loadedData;
        }

        $this->loadedData = [];

        foreach ($this->collection->getItems() as $entity) {
            $this->loadedData[$entity->getId()] = $entity->getData();
        }

        $stashed = $this->dataPersistor->get('spartrak_pickup_branch');

        if (!empty($stashed)) {
            $entityId = (int) ($stashed['branch_id'] ?? 0);
            $this->loadedData[$entityId] = $stashed;
            $this->dataPersistor->clear('spartrak_pickup_branch');
        }

        return $this->loadedData;
    }
}
