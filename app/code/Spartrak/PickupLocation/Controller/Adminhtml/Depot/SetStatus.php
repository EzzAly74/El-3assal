<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Controller\Adminhtml\Depot;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Spartrak\PickupLocation\Model\DepotRepository;

/**
 * Flips one row's enabled state from the grid.
 *
 * The DESIRED state arrives as a parameter rather than being inferred by
 * inverting whatever is in the database. Two admins acting on the same row at
 * once would otherwise toggle each other's change; here the second click is
 * simply idempotent.
 */
class SetStatus extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Spartrak\PickupLocation::depot';

    public function __construct(
        Context $context,
        private readonly DepotRepository $repository
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $entityId = (int) $this->getRequest()->getParam('depot_id');
        $isActive = (int) $this->getRequest()->getParam('is_active') === 1 ? 1 : 0;

        if ($entityId <= 0) {
            $this->messageManager->addErrorMessage(__('We could not find that depot.'));

            return $redirect->setPath('*/*/');
        }

        try {
            $entity = $this->repository->getById($entityId);
            $entity->setData('is_active', $isActive);
            $this->repository->save($entity);

            $this->messageManager->addSuccessMessage(
                $isActive ? __('The depot is now enabled.') : __('The depot is now disabled.')
            );
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (\Exception $exception) {
            $this->messageManager->addExceptionMessage(
                $exception,
                __('Something went wrong while updating the depot.')
            );
        }

        return $redirect->setPath('*/*/');
    }
}
