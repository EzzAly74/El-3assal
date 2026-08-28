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

/**
 * Flips one banner between enabled and disabled, straight from the grid.
 *
 * The section-level twin of this controller carries the full rationale; the
 * same reasoning applies here, and one extra reason of its own:
 *
 * A banner section renders as a STATIC banner with one enabled image and as a
 * CAROUSEL with two or more (Block\Section\Banner::isCarousel). So enabling and
 * disabling images is not just show/hide - it is how an editor switches the
 * hero between those two modes. Making that a single click on the grid is the
 * difference between "swap today's hero" being one action or six.
 *
 * POST-only with the admin form key, gated on the banner ACL resource.
 */
class SetStatus extends Action implements HttpPostActionInterface
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
            $this->messageManager->addErrorMessage(__('We could not find a banner to update.'));

            return $redirect->setPath('*/*/');
        }

        try {
            $banner = $this->bannerRepository->getById($bannerId);

            $isActive = (int) $this->getRequest()->getParam('is_active');
            $banner->setData('is_active', $isActive === 1 ? 1 : 0);

            $this->bannerRepository->save($banner);

            $this->messageManager->addSuccessMessage(
                $isActive === 1
                    ? __('The banner is now enabled.')
                    : __('The banner is now disabled.')
            );
        } catch (\Exception $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        }

        return $redirect->setPath('*/*/');
    }
}
