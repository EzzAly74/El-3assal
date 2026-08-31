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
 * HttpPostActionInterface, not HttpGet: a destructive action reached by GET is
 * one crawler or one prefetching browser away from deleting a row nobody asked
 * to delete. The grid's action column posts with the admin form key.
 */
class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Spartrak_PickupLocation::depot';

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

        if ($entityId <= 0) {
            $this->messageManager->addErrorMessage(__('We could not find a depot to delete.'));

            return $redirect->setPath('*/*/');
        }

        try {
            $this->repository->deleteById($entityId);
            $this->messageManager->addSuccessMessage(__('The depot has been deleted.'));
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (\Exception $exception) {
            $this->messageManager->addExceptionMessage(
                $exception,
                __('Something went wrong while deleting the depot.')
            );
        }

        return $redirect->setPath('*/*/');
    }
}
