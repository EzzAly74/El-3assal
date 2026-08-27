<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Controller\Adminhtml\Section;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Model\ImageUploader;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Receives the promo panel's artwork.
 *
 * A near-twin of the banner uploader, and deliberately NOT the same route:
 * the two screens carry different ACL resources (Spartrak_Homepage::section
 * vs ::banner), so pointing the section form at the banner endpoint would let
 * anyone who can edit a section upload through the banner permission. Same
 * ImageUploader virtual type, same media path — only the authorisation
 * differs, which is the whole point.
 */
class Upload extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Spartrak_Homepage::section';

    public function __construct(
        Context $context,
        private readonly ImageUploader $imageUploader,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $fieldId = (string) $this->getRequest()->getParam('param_name', 'promo_image_ar');

        try {
            $result = $this->imageUploader->saveFileToTmpDir($fieldId);
        } catch (\Exception $exception) {
            $result = ['error' => $exception->getMessage(), 'errorcode' => $exception->getCode()];
        }

        return $this->jsonFactory->create()->setData($result);
    }
}
