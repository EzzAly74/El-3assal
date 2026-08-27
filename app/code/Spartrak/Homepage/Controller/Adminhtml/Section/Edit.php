<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Controller\Adminhtml\Section;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;
use Spartrak\Homepage\Model\SectionRepository;

class Edit extends Action
{
    public const ADMIN_RESOURCE = 'Spartrak_Homepage::section';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly SectionRepository $sectionRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $sectionId = (int) $this->getRequest()->getParam('section_id');

        try {
            $section = $this->sectionRepository->getById($sectionId);
        } catch (NoSuchEntityException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());

            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Spartrak_Homepage::section');
        $page->getConfig()->getTitle()->prepend(__('Edit Section "%1"', $section->getCode()));

        return $page;
    }
}
