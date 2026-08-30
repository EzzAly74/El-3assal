<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Controller\Adminhtml\Operator;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Spartrak\PickupLocation\Model\OperatorRepository;

/**
 * HttpPostActionInterface, not HttpGet: a destructive action reached by GET is
 * one crawler or one prefetching browser away from deleting a row nobody asked
 * to delete. The grid's action column posts with the admin form key.
 */
class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Spartrak\PickupLocation::operator';

    public function __construct(
        Context $context,
        private readonly OperatorRepository $repository
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $entityId = (int) $this->getRequest()->getParam('operator_id');

        if ($entityId <= 0) {
            $this->messageManager->addErrorMessage(__('We could not find a transport operator to delete.'));

            return $redirect->setPath('*/*/');
        }

        try {
            $this->repository->deleteById($entityId);
            $this->messageManager->addSuccessMessage(__('The transport operator has been deleted.'));
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (\Exception $exception) {
            $this->messageManager->addExceptionMessage(
                $exception,
                __('Something went wrong while deleting the transport operator.')
            );
        }

        return $redirect->setPath('*/*/');
    }
}
