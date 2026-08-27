<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Controller\Adminhtml\Banner;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;
use Spartrak\Homepage\Model\BannerRepository;

class Edit extends Action
{
    public const ADMIN_RESOURCE = 'Spartrak_Homepage::banner';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly BannerRepository $bannerRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $bannerId = (int) $this->getRequest()->getParam('banner_id');

        try {
            $banner = $this->bannerRepository->getById($bannerId);
        } catch (NoSuchEntityException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());

            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Spartrak_Homepage::banner');
        $page->getConfig()->getTitle()->prepend(__('Edit Banner'));

        return $page;
    }
}
