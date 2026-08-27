<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Controller\Adminhtml\Banner;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Model\ImageUploader;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Receives one banner image from the form's uploader and stages it.
 *
 * The file lands in the TMP path and is only moved to its final home when the
 * row is actually saved (see Save::moveImages). That is Magento's own
 * category-image flow, and it is why abandoning a half-filled form does not
 * litter the media directory with files no row references.
 *
 * All validation — extension, MIME, filename safety — happens inside
 * ImageUploader, configured in etc/adminhtml/di.xml. None of it is re-done
 * (or, worse, relaxed) here.
 */
class Upload extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Spartrak_Homepage::banner';

    public function __construct(
        Context $context,
        private readonly ImageUploader $imageUploader,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        // The uploader posts under the field's own name, so one controller
        // serves all four image fields on the form.
        $fieldId = (string) $this->getRequest()->getParam('param_name', 'image_desktop_en');

        try {
            $result = $this->imageUploader->saveFileToTmpDir($fieldId);
            $result['cookie'] = [
                'name' => $this->_getSession()->getName(),
                'value' => $this->_getSession()->getSessionId(),
                'lifetime' => $this->_getSession()->getCookieLifetime(),
                'path' => $this->_getSession()->getCookiePath(),
                'domain' => $this->_getSession()->getCookieDomain(),
            ];
        } catch (\Exception $exception) {
            $result = ['error' => $exception->getMessage(), 'errorcode' => $exception->getCode()];
        }

        return $this->jsonFactory->create()->setData($result);
    }
}
