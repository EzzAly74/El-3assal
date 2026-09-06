<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\ProductVideo\Model\Video;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\File\Name;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\UrlInterface;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\MediaStorage\Model\File\UploaderFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Receives one uploaded MP4/WebM, validates it, and parks it.
 *
 * ===========================================================================
 * A STANDALONE CLASS, NOT A VIRTUAL TYPE OF Magento\Catalog\Model\ImageUploader
 * ===========================================================================
 * Spartrak\Homepage\Model\Image\Uploader records the finding at length and it
 * applies here word for word: the catalog uploader carries
 * Magento_MediaGalleryCatalogIntegration's `save_category_image` plugin, which
 * force-feeds every saved file to the media-gallery synchroniser. Plugins are
 * inherited by virtual types and by subclasses, so the only way for a video not
 * to be treated as a category image is for its uploader not to be one.
 *
 * It matters more here than it did there. That synchroniser would try to make
 * an image asset out of a video file — read its dimensions, generate
 * derivatives — on every product save.
 *
 * Nothing about the upload is home-grown. Extension validation, the safe
 * filename rewrite and the collision suffix all still come from
 * Magento\MediaStorage\Model\File\Uploader, Magento\Framework\File\Name and
 * Magento\MediaStorage\Helper\File\Storage\Database — core's own three
 * collaborators, including the database-backed media storage a clustered
 * install needs.
 *
 * ===========================================================================
 * WHAT IS VALIDATED, AND WHY EACH CHECK IS THERE
 * ===========================================================================
 *   extension   from etc/adminhtml/di.xml. Stops the obvious.
 *   REAL BYTES  checkMimeType() reads the file's own magic, so a .php renamed
 *               to .mp4 is refused. An extension is a claim, not evidence.
 *   size        a hard ceiling, because a 4 GB upload is a denial of service
 *               against the web server whether or not it was meant as one.
 *
 * There is no transcoding, and there deliberately never will be in a web
 * request: ffmpeg on a product save is how a save times out. If transcoding is
 * ever wanted it belongs in a queue consumer, and the file this class stores is
 * the input it would read.
 */
class Uploader
{
    private WriteInterface $mediaDirectory;

    /**
     * @param string[] $allowedExtensions
     * @param string[] $allowedMimeTypes
     */
    public function __construct(
        Filesystem $filesystem,
        private readonly UploaderFactory $uploaderFactory,
        private readonly Database $coreFileStorageDatabase,
        private readonly StoreManagerInterface $storeManager,
        private readonly Name $fileNameLookup,
        private readonly LoggerInterface $logger,
        private readonly array $allowedExtensions = [],
        private readonly array $allowedMimeTypes = [],
        private readonly int $maxFileSizeBytes = 0
    ) {
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
    }

    /**
     * Validates one uploaded file and parks it in the staging directory.
     *
     * The file is NOT in its final home yet — it is promoted by
     * moveFileFromTmp() when the product is actually saved, so closing the
     * video dialog without saving leaves no orphan file behind.
     *
     * @return array<string, mixed> the descriptor the dialog's uploader expects
     * @throws LocalizedException
     */
    public function saveFileToTmpDir(string $fileId): array
    {
        $this->assertWithinSizeLimit($fileId);

        $uploader = $this->uploaderFactory->create(['fileId' => $fileId]);
        $uploader->setAllowedExtensions($this->allowedExtensions);
        // Core's own setting: a name that is unsafe, or already taken, is
        // rewritten rather than rejected back at the merchant.
        $uploader->setAllowRenameFiles(true);

        // Extension alone is not proof of type — this reads the real bytes.
        if (!$uploader->checkMimeType($this->allowedMimeTypes)) {
            throw new LocalizedException(
                __('That file is not an MP4 or WebM video.')
            );
        }

        $result = $uploader->save(
            $this->mediaDirectory->getAbsolutePath(Storage::BASE_TMP_PATH)
        );

        if (!$result || empty($result['file'])) {
            throw new LocalizedException(__('The file could not be saved to the destination folder.'));
        }

        // An absolute server path has no business travelling to a browser.
        unset($result['path']);

        $relative = $this->join(Storage::BASE_TMP_PATH, (string) $result['file']);

        try {
            // No-op unless the install keeps media in the database, in which
            // case this is what makes the staged file visible to every node.
            $this->coreFileStorageDatabase->saveFile($relative);
        } catch (\Exception $exception) {
            $this->logger->critical($exception);

            throw new LocalizedException(__('Something went wrong while saving the file(s).'), $exception);
        }

        $result['name'] = $result['file'];
        $result['url'] = $this->storeManager->getStore()
                ->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) . $relative;

        // The staged MEDIA-RELATIVE path, which is the value the dialog parks
        // in its hidden field and the product save later promotes out of tmp.
        // Named distinctly from `file` and `name` — both of which core's own
        // uploader already defines with different meanings — so the two
        // conventions cannot be confused at the call site.
        $result['spartrak_tmp_path'] = $relative;

        // Prototype 1.7's isJSON/evalJSON choke on a backslash; Windows puts
        // them in tmp paths. Core normalises the same field for the same reason.
        $result['tmp_name'] = isset($result['tmp_name'])
            ? str_replace('\\', '/', (string) $result['tmp_name'])
            : '';

        return $result;
    }

    /**
     * Promotes a staged file to its final home.
     *
     * Returns the MEDIA-RELATIVE path of the file that now exists — base path
     * included, and carrying the collision suffix if one was needed. That is
     * exactly the value spartrak_product_video.src_path stores, so there is no
     * second shape of return value to pick between.
     *
     * @throws LocalizedException
     */
    public function moveFileFromTmp(string $fileName): string
    {
        $source = $this->join(Storage::BASE_TMP_PATH, $fileName);

        $target = $this->join(
            Storage::BASE_PATH,
            $this->fileNameLookup->getNewFileName(
                $this->mediaDirectory->getAbsolutePath($this->join(Storage::BASE_PATH, $fileName))
            )
        );

        try {
            $this->coreFileStorageDatabase->renameFile($source, $target);
            $this->mediaDirectory->renameFile($source, $target);
        } catch (\Exception $exception) {
            $this->logger->critical($exception);

            throw new LocalizedException(__('Something went wrong while saving the file(s).'), $exception);
        }

        return $target;
    }

    /**
     * Checked BEFORE the uploader is constructed, so an oversized file is
     * rejected without first being copied out of PHP's own tmp dir.
     *
     * @throws LocalizedException
     */
    private function assertWithinSizeLimit(string $fileId): void
    {
        if ($this->maxFileSizeBytes <= 0) {
            return;
        }

        $size = (int) ($_FILES[$fileId]['size'] ?? 0);

        if ($size > $this->maxFileSizeBytes) {
            throw new LocalizedException(
                __(
                    'That video is %1 MB. The limit is %2 MB - host larger files on a CDN and '
                    . 'paste the URL instead.',
                    (string) round($size / 1048576, 1),
                    (string) round($this->maxFileSizeBytes / 1048576)
                )
            );
        }
    }

    private function join(string $base, string $file): string
    {
        return rtrim($base, '/') . '/' . ltrim($file, '/');
    }
}
