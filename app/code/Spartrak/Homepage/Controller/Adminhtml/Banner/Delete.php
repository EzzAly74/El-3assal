<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Controller\Adminhtml\Banner;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Spartrak\Homepage\Model\BannerRepository;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Spartrak_Homepage::banner';

    public function __construct(
        Context $context,
        private readonly BannerRepository $bannerRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $bannerId = (int) $this->getRequest()->getParam('banner_id');

        if ($bannerId <= 0) {
            $this->messageManager->addErrorMessage(__('We could not find a banner to delete.'));

            return $redirect->setPath('*/*/');
        }

        try {
            $this->bannerRepository->deleteById($bannerId);
            $this->messageManager->addSuccessMessage(__('The banner has been deleted.'));
        } catch (\Exception $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        }

        return $redirect->setPath('*/*/');
    }
}
