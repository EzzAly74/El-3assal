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
 * Flips one section between enabled and disabled, straight from the grid.
 *
 * ===========================================================================
 * WHY THIS EXISTS WHEN THE EDIT FORM ALREADY HAS AN "Enabled" TOGGLE
 * ===========================================================================
 * Taking a section off the homepage was a four-step trip: open the row, find
 * the toggle, save, come back. During a campaign that is the single most
 * frequent thing an editor does, so it belongs on the grid row itself.
 *
 * ===========================================================================
 * POST, NOT GET
 * ===========================================================================
 * This changes state, so it is HttpPostActionInterface exactly like Delete.
 * The grid's action column sends it as a POST with the admin form key (the
 * `post` flag in Ui/Component/Listing/Column/SectionActions), which is
 * Magento's own CSRF protection for admin writes - a plain <a href> toggle
 * would be a state change over GET, which CLAUDE.md section 17 rules out.
 *
 * ACL is the section resource, the same one the grid and the form are gated
 * on, so nobody can flip a section they are not allowed to edit.
 */
class SetStatus extends Action implements HttpPostActionInterface
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
            $this->messageManager->addErrorMessage(__('We could not find a section to update.'));

            return $redirect->setPath('*/*/');
        }

        try {
            $section = $this->sectionRepository->getById($sectionId);

            // The NEW state is read from the request rather than inverted from
            // the stored one. Two editors on the same grid would otherwise
            // toggle each other's change back; sending the intended state means
            // the last click wins and both editors get what they clicked.
            $isActive = (int) $this->getRequest()->getParam('is_active');
            $section->setData('is_active', $isActive === 1 ? 1 : 0);

            $this->sectionRepository->save($section);

            $this->messageManager->addSuccessMessage(
                $isActive === 1
                    ? __('The section is now enabled.')
                    : __('The section is now disabled.')
            );
        } catch (\Exception $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        }

        return $redirect->setPath('*/*/');
    }
}
