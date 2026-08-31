<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Controller\Adminhtml\Branch;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;
use Spartrak\PickupLocation\Model\BranchRepository;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Spartrak_PickupLocation::branch';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly BranchRepository $repository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $entityId = (int) $this->getRequest()->getParam('branch_id');

        try {
            $this->repository->getById($entityId);
        } catch (NoSuchEntityException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());

            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Spartrak_PickupLocation::branch');
        $page->getConfig()->getTitle()->prepend(__('Edit Branch'));

        return $page;
    }
}
