<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\MediaWebp\Controller\Adminhtml\Product\Gallery;

use Magento\Catalog\Controller\Adminhtml\Product\Gallery\Upload as CoreUpload;
use Magento\Catalog\Model\Product\Media\Config as ProductMediaConfig;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Image\AdapterFactory;
use Magento\MediaStorage\Model\File\Uploader;

/**
 * Product gallery upload with a configurable allowed-type list.
 *
 * The core controller hard-codes its jpg/jpeg/gif/png map in a PRIVATE property
 * read by a PRIVATE getter, both consumed only by execute(); neither a plugin
 * nor a subclass can reach them, so execute() is the smallest overridable unit.
 * Its body is kept deliberately identical to the 2.4.8 original except that the
 * allowed types now arrive through DI (etc/adminhtml/di.xml) - adding a format
 * is a config change from here on, not a code change.
 *
 * Re-check this class against the core controller on every Magento upgrade.
 */
class Upload extends CoreUpload
{
    /**
     * $resultRawFactory is NOT redeclared here: the core controller declares it
     * protected and its constructor assigns it, so this class inherits both.
     * The three below are private in core, which leaves them invisible to a
     * subclass - hence the local copies.
     *
     * @var AdapterFactory
     */
    private $adapterFactory;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var ProductMediaConfig
     */
    private $productMediaConfig;

    /**
     * @var array
     */
    private $allowedMimeTypes;

    /**
     * @param Context $context
     * @param RawFactory $resultRawFactory
     * @param AdapterFactory $adapterFactory
     * @param Filesystem $filesystem
     * @param ProductMediaConfig $productMediaConfig
     * @param array $allowedMimeTypes Extension => MIME type
     */
    public function __construct(
        Context $context,
        RawFactory $resultRawFactory,
        AdapterFactory $adapterFactory,
        Filesystem $filesystem,
        ProductMediaConfig $productMediaConfig,
        array $allowedMimeTypes = []
    ) {
        parent::__construct($context, $resultRawFactory, $adapterFactory, $filesystem, $productMediaConfig);
        $this->adapterFactory = $adapterFactory;
        $this->filesystem = $filesystem;
        $this->productMediaConfig = $productMediaConfig;
        $this->allowedMimeTypes = $allowedMimeTypes;
    }

    /**
     * Upload image(s) to the product gallery.
     *
     * @return Raw
     */
    public function execute()
    {
        try {
            $uploader = $this->_objectManager->create(Uploader::class, ['fileId' => 'image']);
            $uploader->setAllowedExtensions(array_keys($this->allowedMimeTypes));
            $imageAdapter = $this->adapterFactory->create();
            $uploader->addValidateCallback('catalog_product_image', $imageAdapter, 'validateUploadFile');
            $uploader->setAllowRenameFiles(true);
            $uploader->setFilesDispersion(true);
            $mediaDirectory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            $result = $uploader->save(
                $mediaDirectory->getAbsolutePath($this->productMediaConfig->getBaseTmpMediaPath())
            );
            $this->_eventManager->dispatch(
                'catalog_product_gallery_upload_image_after',
                ['result' => $result, 'action' => $this]
            );

            if (is_array($result)) {
                unset($result['tmp_name']);
                unset($result['path']);

                $result['url'] = $this->productMediaConfig->getTmpMediaUrl($result['file']);
                $result['file'] = $result['file'] . '.tmp';
            } else {
                $result = ['error' => 'Something went wrong while saving the file(s).'];
            }
        } catch (LocalizedException $e) {
            $result = ['error' => $e->getMessage(), 'errorcode' => $e->getCode()];
        } catch (\Throwable $e) {
            $result = ['error' => 'Something went wrong while saving the file(s).', 'errorcode' => 0];
        }

        /** @var Raw $response */
        $response = $this->resultRawFactory->create();
        $response->setHeader('Content-type', 'text/plain');
        $response->setContents(json_encode($result));

        return $response;
    }
}
