<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Controller\Adminhtml\Depot;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Phrase;
use Spartrak\PickupLocation\Controller\Adminhtml\AbstractSave;
use Spartrak\PickupLocation\Model\Depot;
use Spartrak\PickupLocation\Model\DepotFactory;
use Spartrak\PickupLocation\Model\DepotRepository;

class Save extends AbstractSave
{
    public const ADMIN_RESOURCE = 'Spartrak_PickupLocation::depot';

    public function __construct(
        Context $context,
        DataPersistorInterface $dataPersistor,
        private readonly DepotRepository $repository,
        private readonly DepotFactory $factory
    ) {
        parent::__construct($context, $dataPersistor);
    }

    protected function idField(): string
    {
        return 'depot_id';
    }

    protected function persistorKey(): string
    {
        return 'spartrak_pickup_depot';
    }

    protected function loadOrCreate(int $entityId): AbstractModel
    {
        return $entityId > 0
            ? $this->repository->getById($entityId)
            : $this->factory->create();
    }

    protected function persist(AbstractModel $entity): void
    {
        /** @var Depot $entity */
        $this->repository->save($entity);
    }

    /**
     * @param array<string, mixed> $data
     * @throws LocalizedException
     */
    protected function validate(array $data): void
    {
        // See the branch equivalent for why the ARABIC pair is the required one.
        $this->requireField($data, 'name_ar', __('depot name in Arabic'));
        $this->requireField($data, 'address_ar', __('depot address in Arabic'));
    }

    protected function successMessage(): Phrase
    {
        return __('The depot has been saved.');
    }

    protected function failureMessage(): Phrase
    {
        return __('Something went wrong while saving the depot.');
    }
}
