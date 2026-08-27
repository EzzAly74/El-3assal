<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Controller\Adminhtml\Section;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    /**
     * ADMIN_RESOURCE is what Magento's own ACL check reads. Declaring it is
     * the whole authorisation story for a backend controller — never a manual
     * _isAllowed() override (CLAUDE.md section 17).
     */
    public const ADMIN_RESOURCE = 'Spartrak_Homepage::section';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Spartrak_Homepage::section');
        $page->getConfig()->getTitle()->prepend(__('Homepage Sections'));

        return $page;
    }
}
