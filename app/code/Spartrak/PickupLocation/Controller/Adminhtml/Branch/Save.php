<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Controller\Adminhtml\Branch;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Phrase;
use Spartrak\PickupLocation\Controller\Adminhtml\AbstractSave;
use Spartrak\PickupLocation\Model\Branch;
use Spartrak\PickupLocation\Model\BranchFactory;
use Spartrak\PickupLocation\Model\BranchRepository;

class Save extends AbstractSave
{
    public const ADMIN_RESOURCE = 'Spartrak_PickupLocation::branch';

    public function __construct(
        Context $context,
        DataPersistorInterface $dataPersistor,
        private readonly BranchRepository $repository,
        private readonly BranchFactory $factory
    ) {
        parent::__construct($context, $dataPersistor);
    }

    protected function idField(): string
    {
        return 'branch_id';
    }

    protected function persistorKey(): string
    {
        return 'spartrak_pickup_branch';
    }

    protected function loadOrCreate(int $entityId): AbstractModel
    {
        return $entityId > 0
            ? $this->repository->getById($entityId)
            : $this->factory->create();
    }

    protected function persist(AbstractModel $entity): void
    {
        /** @var Branch $entity */
        $this->repository->save($entity);
    }

    /**
     * @param array<string, mixed> $data
     * @throws LocalizedException
     */
    protected function validate(array $data): void
    {
        // The Arabic name and address are the REQUIRED pair, not the English
        // ones: this is an Arabic-first storefront, and
        // Spartrak\Locale\Model\StoreLanguage falls back to Arabic when an
        // English value is missing but has nothing to fall back to the other
        // way. Making Arabic mandatory is what guarantees every branch renders.
        $this->requireField($data, 'name_ar', __('branch name in Arabic'));
        $this->requireField($data, 'address_ar', __('branch address in Arabic'));
    }

    protected function successMessage(): Phrase
    {
        return __('The branch has been saved.');
    }

    protected function failureMessage(): Phrase
    {
        return __('Something went wrong while saving the branch.');
    }
}
