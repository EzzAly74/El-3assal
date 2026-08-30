<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model;

use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Spartrak\PickupLocation\Model\ResourceModel\Operator as OperatorResource;

/**
 * Persistence service for operators.
 *
 * A lean load/save/delete service rather than a SearchCriteria @api repository,
 * matching Spartrak_Homepage's BannerRepository: nothing outside this codebase
 * consumes it, the admin grids read through Magento's own generic
 * DataProvider, and the storefront reads through Model\LocationCatalog, which
 * caches. Adding a service-contract interface now would be an abstraction with
 * exactly one implementation and no second caller - the cost without the
 * benefit.
 */
class OperatorRepository
{
    public function __construct(
        private readonly OperatorResource $resource,
        private readonly OperatorFactory $operatorFactory
    ) {
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $operatorId): Operator
    {
        $operator = $this->operatorFactory->create();
        $this->resource->load($operator, $operatorId);

        if (!$operator->getId()) {
            throw new NoSuchEntityException(
                __('No operator exists with ID "%1".', $operatorId)
            );
        }

        return $operator;
    }

    /**
     * @throws CouldNotSaveException
     */
    public function save(Operator $operator): Operator
    {
        try {
            $this->resource->save($operator);
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(
                __('The operator could not be saved: %1', $exception->getMessage()),
                $exception
            );
        }

        return $operator;
    }

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(Operator $operator): void
    {
        try {
            $this->resource->delete($operator);
        } catch (\Exception $exception) {
            throw new CouldNotDeleteException(
                __('The operator could not be deleted: %1', $exception->getMessage()),
                $exception
            );
        }
    }

    /**
     * @throws NoSuchEntityException
     * @throws CouldNotDeleteException
     */
    public function deleteById(int $operatorId): void
    {
        $this->delete($this->getById($operatorId));
    }
}
