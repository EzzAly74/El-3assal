<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\ProductVideo\Controller\Adminhtml\Video;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Spartrak\ProductVideo\Model\Config;
use Spartrak\ProductVideo\Model\Video\Uploader;

/**
 * Receives one video file from the product form's video dialog and stages it.
 *
 * The file lands in `spartrak/product-video/tmp/` and only moves to its
 * permanent home when the PRODUCT is saved — see
 * Plugin\Catalog\Gallery\SaveVideoSettings::resolveUploadedPath(). That is
 * Magento's own category-image flow, and it is why closing the dialog without
 * saving does not leave a 40 MB file nothing references.
 *
 * ===========================================================================
 * ACL
 * ===========================================================================
 * `Magento_Catalog::products`, not a resource of this module's own. This
 * endpoint exists to attach a video to a product from inside the product form;
 * anyone who can edit a product can already replace its images, and inventing
 * a separate permission would mean a role that can edit products but silently
 * cannot use half of one fieldset. The module's own ACL resource
 * (Spartrak_ProductVideo::config) guards the store configuration, which is a
 * different decision by a different person.
 *
 * CSRF is Magento's: HttpPostActionInterface plus the admin form key, which
 * the dialog's own form already carries and the uploader posts along.
 *
 * All validation — extension, real-bytes MIME, size — happens inside
 * Model\Video\Uploader, configured in etc/adminhtml/di.xml. None of it is
 * re-done, or relaxed, here.
 */
class Upload extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Catalog::products';

    public function __construct(
        Context $context,
        private readonly Uploader $uploader,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        if (!$this->config->isEnabled()) {
            return $this->jsonFactory->create()->setData([
                'error' => (string) __('Product video is turned off for this store.'),
                'errorcode' => 0,
            ]);
        }

        try {
            $result = $this->uploader->saveFileToTmpDir('spartrak_video_upload');
        } catch (\Exception $exception) {
            // The message is the merchant's only feedback — the dialog prints
            // it beside the field — so it is passed through rather than
            // swallowed into a generic failure.
            return $this->jsonFactory->create()->setData([
                'error' => $exception->getMessage(),
                'errorcode' => $exception->getCode(),
            ]);
        }

        return $this->jsonFactory->create()->setData($result);
    }
}
