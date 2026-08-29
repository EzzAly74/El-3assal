<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Catalog\Controller\Adminhtml\Iframe;

use Magento\Backend\App\Action\Context;
use Magento\Catalog\Model\Product\Media\Config;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Image\AdapterFactory;
use Magento\MediaStorage\Model\File\UploaderFactory;
use Magento\Swatches\Controller\Adminhtml\Iframe\Show as CoreShow;
use Magento\Swatches\Helper\Media as SwatchMedia;
use Spartrak\Catalog\Model\Swatch\SvgSanitizer;

/**
 * Adds SVG to the attribute swatch uploader.
 *
 * ===========================================================================
 * WHY THE CONTROLLER AND NOT A PLUGIN
 * ===========================================================================
 * Core's execute() builds its uploader as a LOCAL variable and calls two
 * methods on it that a plugin cannot reach:
 *
 *     $uploader->setAllowedExtensions(['jpg', 'jpeg', 'gif', 'png']);
 *     $uploader->addValidateCallback('catalog_product_image', $imageAdapter, 'validateUploadFile');
 *
 * The first is the extension list. The second is fatal for SVG on its own:
 * validateUploadFile() opens the file through the raster image adapter, which
 * is getimagesize() underneath, and getimagesize() cannot read an SVG. Neither
 * can be changed from outside the method, and a plugin on the uploader factory
 * would apply to every upload in the admin, which is exactly the blast radius
 * this must not have.
 *
 * ===========================================================================
 * RASTER UPLOADS STILL RUN CORE'S OWN CODE, UNCHANGED
 * ===========================================================================
 * This class overrides execute() but the FIRST thing it does is hand a
 * non-SVG upload straight back to parent::execute(). So jpg, jpeg, gif and png
 * keep core's exact validation, exact error handling and exact response, and
 * will keep whatever a Magento upgrade changes about them. Only the SVG branch
 * is ours, and it is confined below.
 *
 * ===========================================================================
 * SECURITY
 * ===========================================================================
 * SVG is an executable XML document, which is why core does not accept it. It
 * is accepted here because the brief requires it, and it is made safe rather
 * than merely allowed:
 *
 *   - Model\Swatch\SvgSanitizer runs as an uploader validate callback, which
 *     the uploader invokes against PHP's own upload temp BEFORE the file is
 *     copied anywhere. A file that will not parse as a plain SVG is refused
 *     there and never reaches the media directory at all.
 *   - The same callback REWRITES that temp file from the cleaned DOM, so every
 *     later step copies the sanitised bytes. What ends up on disk is what the
 *     sanitiser produced: no <script>, no event handlers, no <foreignObject>,
 *     no external references, no doctype. Nothing is written twice, and nothing
 *     is written outside Magento's own upload path.
 *   - Where these logos are rendered they are `<img src>`, and an SVG in an
 *     <img> is inert in every browser — no script, no external loads. The
 *     sanitiser covers the remaining case, which is someone opening the file's
 *     URL directly.
 *
 * See CLAUDE.md section 17. This is a deliberate, requested exception to the
 * "no SVG uploads" position taken in Spartrak/Homepage/etc/adminhtml/di.xml,
 * and it is narrowed to this one attribute-swatch route.
 */
class Show extends CoreShow
{
    /**
     * The form field core's uploader reads. Same name, because this is the same
     * form.
     */
    private const FILE_ID = 'datafile';

    public function __construct(
        Context $context,
        SwatchMedia $swatchHelper,
        AdapterFactory $adapterFactory,
        Config $config,
        Filesystem $filesystem,
        UploaderFactory $uploaderFactory,
        private readonly SvgSanitizer $svgSanitizer
    ) {
        parent::__construct($context, $swatchHelper, $adapterFactory, $config, $filesystem, $uploaderFactory);
    }

    /**
     * @return void
     */
    public function execute()
    {
        if (!$this->isSvgUpload()) {
            parent::execute();

            return;
        }

        try {
            $uploader = $this->uploaderFactory->create(['fileId' => self::FILE_ID]);
            $uploader->setAllowedExtensions(['svg']);

            // Replaces core's raster adapter check, which cannot read an SVG.
            //
            // This callback does not merely inspect the file, it REWRITES it.
            // Uploader::_validateFile() runs the callbacks against
            // $this->_file['tmp_name'] — PHP's own upload temp — and only then
            // copies the file to its destination (read it in
            // vendor/magento/framework/File/Uploader.php:433-452, then
            // _moveFile). So sanitising here means every later step, including
            // the move out of the media tmp directory, carries the cleaned
            // bytes, and the file is never written twice.
            $uploader->addValidateCallback('spartrak_svg', $this->svgSanitizer, 'sanitizeFile');

            $uploader->setAllowRenameFiles(true);
            $uploader->setFilesDispersion(true);

            $mediaDirectory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            $result = $uploader->save($mediaDirectory->getAbsolutePath($this->config->getBaseTmpMediaPath()));
            unset($result['path']);

            // Kept so anything listening to the raster upload sees this one too.
            $this->_eventManager->dispatch(
                'swatch_gallery_upload_image_after',
                ['result' => $result, 'action' => $this]
            );

            unset($result['tmp_name']);

            $result['file'] = $result['file'] . '.tmp';

            $newFile = $this->swatchHelper->moveImageFromTmp($result['file']);

            // A no-op for SVG — see Plugin\Swatches\SvgVariations. Called anyway
            // so this branch and core's stay the same shape.
            $this->swatchHelper->generateSwatchVariations($newFile);

            $this->getResponse()->setBody((string) json_encode([
                'swatch_path' => $this->swatchHelper->getSwatchMediaUrl(),
                'file_path' => $newFile,
            ]));
        } catch (\Exception $e) {
            $this->getResponse()->setBody((string) json_encode([
                'error' => $e->getMessage(),
                'errorcode' => $e->getCode(),
            ]));
        }
    }

    /**
     * Is the file on THIS request an SVG?
     *
     * Read from the posted filename rather than from its contents, because the
     * only thing this decides is which validation chain to run — and both
     * chains then verify the file properly. The SVG chain in particular parses
     * it as XML, so a .svg extension on something that is not SVG is refused a
     * moment later.
     */
    private function isSvgUpload(): bool
    {
        $file = $this->getRequest()->getFiles(self::FILE_ID);

        if (!is_array($file) || !isset($file['name'])) {
            return false;
        }

        return strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION)) === 'svg';
    }
}
