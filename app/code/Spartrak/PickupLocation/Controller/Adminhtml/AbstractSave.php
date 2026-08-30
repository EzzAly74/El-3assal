<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Controller\Adminhtml;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Phrase;

/**
 * The save flow all three pickup entities share.
 *
 * Branch, depot and operator differ ONLY in which fields are required and what
 * the messages say. The rest - read the post, load or create, validate,
 * persist, redirect, and stash the input again if it failed so the admin does
 * not retype a form - is identical, and identical code that is copied three
 * times is three places for a bug to survive a fix (CLAUDE.md section 9).
 *
 * Subclasses supply the differences through the abstract hooks below.
 *
 * The DataPersistor round-trip is what makes a validation failure survivable:
 * without it, a missing required field throws the admin back to an empty form
 * with their work gone.
 */
abstract class AbstractSave extends Action implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly DataPersistorInterface $dataPersistor
    ) {
        parent::__construct($context);
    }

    /** The primary key field name, as posted by the form. */
    abstract protected function idField(): string;

    /** The DataPersistor key, shared with this entity's DataProvider. */
    abstract protected function persistorKey(): string;

    /**
     * An existing row for a positive id, or a new empty model for 0.
     */
    abstract protected function loadOrCreate(int $entityId): AbstractModel;

    abstract protected function persist(AbstractModel $entity): void;

    /**
     * @param array<string, mixed> $data
     * @throws LocalizedException
     */
    abstract protected function validate(array $data): void;

    abstract protected function successMessage(): Phrase;

    abstract protected function failureMessage(): Phrase;

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $data = $this->getRequest()->getPostValue();

        if (!$data) {
            return $redirect->setPath('*/*/');
        }

        $idField = $this->idField();
        $entityId = (int) ($data[$idField] ?? 0);

        try {
            $entity = $this->loadOrCreate($entityId);

            if ($entityId <= 0) {
                // A blank string id on a new row would be written as 0 and
                // collide with the identity column.
                unset($data[$idField]);
            }

            $this->validate($data);

            $entity->addData($this->normalise($data));
            $this->persist($entity);

            $this->messageManager->addSuccessMessage($this->successMessage());
            $this->dataPersistor->clear($this->persistorKey());

            if ($this->getRequest()->getParam('back')) {
                return $redirect->setPath('*/*/edit', [$idField => $entity->getId()]);
            }

            return $redirect->setPath('*/*/');
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (\Exception $exception) {
            $this->messageManager->addExceptionMessage($exception, $this->failureMessage());
        }

        $this->dataPersistor->set($this->persistorKey(), $data);

        return $entityId > 0
            ? $redirect->setPath('*/*/edit', [$idField => $entityId])
            : $redirect->setPath('*/*/new');
    }

    /**
     * Trims every posted string and turns an empty optional field into null.
     *
     * Storing '' where the schema says nullable makes two states that mean the
     * same thing, and then a LEFT JOIN on region_id matches a row that should
     * have been skipped. Doing it once here keeps every subclass's validate()
     * free of trim() noise.
     *
     * `form_key` is stripped: it is a request artefact, and addData() would
     * happily try to persist a column of that name.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function normalise(array $data): array
    {
        unset($data['form_key'], $data['back']);

        foreach ($data as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);
            $data[$key] = $value === '' ? null : $value;
        }

        return $data;
    }

    /**
     * Shared guard for "this field must have a value".
     *
     * @param array<string, mixed> $data
     * @throws LocalizedException
     */
    protected function requireField(array $data, string $field, Phrase $label): void
    {
        if (trim((string) ($data[$field] ?? '')) === '') {
            throw new LocalizedException(__('Please enter the %1.', $label));
        }
    }
}
