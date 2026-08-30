<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model;

use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Spartrak\PickupLocation\Model\ResourceModel\Depot as DepotResource;

/**
 * Persistence service for depots.
 *
 * A lean load/save/delete service rather than a SearchCriteria @api repository,
 * matching Spartrak_Homepage's BannerRepository: nothing outside this codebase
 * consumes it, the admin grids read through Magento's own generic
 * DataProvider, and the storefront reads through Model\LocationCatalog, which
 * caches. Adding a service-contract interface now would be an abstraction with
 * exactly one implementation and no second caller - the cost without the
 * benefit.
 */
class DepotRepository
{
    public function __construct(
        private readonly DepotResource $resource,
        private readonly DepotFactory $depotFactory
    ) {
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $depotId): Depot
    {
        $depot = $this->depotFactory->create();
        $this->resource->load($depot, $depotId);

        if (!$depot->getId()) {
            throw new NoSuchEntityException(
                __('No depot exists with ID "%1".', $depotId)
            );
        }

        return $depot;
    }

    /**
     * @throws CouldNotSaveException
     */
    public function save(Depot $depot): Depot
    {
        try {
            $this->resource->save($depot);
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(
                __('The depot could not be saved: %1', $exception->getMessage()),
                $exception
            );
        }

        return $depot;
    }

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(Depot $depot): void
    {
        try {
            $this->resource->delete($depot);
        } catch (\Exception $exception) {
            throw new CouldNotDeleteException(
                __('The depot could not be deleted: %1', $exception->getMessage()),
                $exception
            );
        }
    }

    /**
     * @throws NoSuchEntityException
     * @throws CouldNotDeleteException
     */
    public function deleteById(int $depotId): void
    {
        $this->delete($this->getById($depotId));
    }
}
