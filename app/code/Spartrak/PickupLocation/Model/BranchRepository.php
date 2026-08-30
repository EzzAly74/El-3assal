<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model;

use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Spartrak\PickupLocation\Model\ResourceModel\Branch as BranchResource;

/**
 * Persistence service for branchs.
 *
 * A lean load/save/delete service rather than a SearchCriteria @api repository,
 * matching Spartrak_Homepage's BannerRepository: nothing outside this codebase
 * consumes it, the admin grids read through Magento's own generic
 * DataProvider, and the storefront reads through Model\LocationCatalog, which
 * caches. Adding a service-contract interface now would be an abstraction with
 * exactly one implementation and no second caller - the cost without the
 * benefit.
 */
class BranchRepository
{
    public function __construct(
        private readonly BranchResource $resource,
        private readonly BranchFactory $branchFactory
    ) {
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $branchId): Branch
    {
        $branch = $this->branchFactory->create();
        $this->resource->load($branch, $branchId);

        if (!$branch->getId()) {
            throw new NoSuchEntityException(
                __('No branch exists with ID "%1".', $branchId)
            );
        }

        return $branch;
    }

    /**
     * @throws CouldNotSaveException
     */
    public function save(Branch $branch): Branch
    {
        try {
            $this->resource->save($branch);
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(
                __('The branch could not be saved: %1', $exception->getMessage()),
                $exception
            );
        }

        return $branch;
    }

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(Branch $branch): void
    {
        try {
            $this->resource->delete($branch);
        } catch (\Exception $exception) {
            throw new CouldNotDeleteException(
                __('The branch could not be deleted: %1', $exception->getMessage()),
                $exception
            );
        }
    }

    /**
     * @throws NoSuchEntityException
     * @throws CouldNotDeleteException
     */
    public function deleteById(int $branchId): void
    {
        $this->delete($this->getById($branchId));
    }
}
