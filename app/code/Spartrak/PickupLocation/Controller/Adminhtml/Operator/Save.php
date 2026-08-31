<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Controller\Adminhtml\Operator;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Phrase;
use Spartrak\PickupLocation\Controller\Adminhtml\AbstractSave;
use Spartrak\PickupLocation\Model\Operator;
use Spartrak\PickupLocation\Model\OperatorFactory;
use Spartrak\PickupLocation\Model\OperatorRepository;

class Save extends AbstractSave
{
    public const ADMIN_RESOURCE = 'Spartrak_PickupLocation::operator';

    public function __construct(
        Context $context,
        DataPersistorInterface $dataPersistor,
        private readonly OperatorRepository $repository,
        private readonly OperatorFactory $factory
    ) {
        parent::__construct($context, $dataPersistor);
    }

    protected function idField(): string
    {
        return 'operator_id';
    }

    protected function persistorKey(): string
    {
        return 'spartrak_pickup_operator';
    }

    protected function loadOrCreate(int $entityId): AbstractModel
    {
        return $entityId > 0
            ? $this->repository->getById($entityId)
            : $this->factory->create();
    }

    protected function persist(AbstractModel $entity): void
    {
        /** @var Operator $entity */
        $this->repository->save($entity);
    }

    /**
     * @param array<string, mixed> $data
     * @throws LocalizedException
     */
    protected function validate(array $data): void
    {
        $this->requireField($data, 'code', __('operator code'));
        $this->requireField($data, 'name_ar', __('operator name in Arabic'));

        // The code is a STABLE IDENTIFIER, not a label: it is unique by schema
        // and the storefront filter round-trips it. Restricting the character
        // set here turns a database integrity error into a sentence an admin
        // can act on, and keeps the value safe to use in a URL or a CSS hook.
        $code = trim((string) ($data['code'] ?? ''));

        if (!preg_match('/^[a-z0-9_]+$/', $code)) {
            throw new LocalizedException(
                __('The operator code may contain only lowercase letters, numbers and underscores.')
            );
        }
    }

    protected function successMessage(): Phrase
    {
        return __('The operator has been saved.');
    }

    protected function failureMessage(): Phrase
    {
        return __('Something went wrong while saving the operator.');
    }
}
