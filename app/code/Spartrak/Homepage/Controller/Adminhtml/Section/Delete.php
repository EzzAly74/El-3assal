<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Controller\Adminhtml\Section;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Spartrak\Homepage\Model\SectionRepository;

/**
 * POST-only, like every destructive admin action here — see Save for why.
 */
class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Spartrak_Homepage::section';

    public function __construct(
        Context $context,
        private readonly SectionRepository $sectionRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $sectionId = (int) $this->getRequest()->getParam('section_id');

        if ($sectionId <= 0) {
            $this->messageManager->addErrorMessage(__('We could not find a section to delete.'));

            return $redirect->setPath('*/*/');
        }

        try {
            // Banners and category picks go with it — the foreign keys are
            // ON DELETE CASCADE, so this leaves nothing orphaned behind.
            $this->sectionRepository->deleteById($sectionId);
            $this->messageManager->addSuccessMessage(__('The section has been deleted.'));
        } catch (\Exception $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        }

        return $redirect->setPath('*/*/');
    }
}
