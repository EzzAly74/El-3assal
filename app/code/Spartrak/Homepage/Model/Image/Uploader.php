<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model\Image;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\File\Name;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\UrlInterface;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\MediaStorage\Model\File\UploaderFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Moves banner artwork from the admin form into pub/media, and nothing else.
 *
 * ===========================================================================
 * WHY THIS IS ITS OWN CLASS AND NOT A VIRTUAL TYPE OF THE CATALOG UPLOADER
 * ===========================================================================
 * It used to be `<virtualType name="Spartrak\Homepage\BannerImageUpload"
 * type="Magento\Catalog\Model\ImageUploader">`, to reuse core's validation and
 * tmp-to-final move rather than reimplement them. The reuse was the right
 * instinct; the base class was the wrong one, and it made every banner save
 * fail with
 *
 *     Could not import media assets for files: spartrak/homepage/<file>.webp
 *
 * Magento_MediaGalleryCatalogIntegration attaches a plugin named
 * `save_category_image` to `Magento\Catalog\Model\ImageUploader::moveFileFromTmp`.
 * Its job is to index a CATEGORY image into the media gallery. It ran on every
 * banner save, handed the media-gallery synchroniser a path in
 * `spartrak/homepage/` — and that tree is not a media-gallery folder at all:
 * `system/media_storage_configuration/allowed_resources/media_gallery_image_folders`
 * lists only `wysiwyg` and `catalog/category`, so `IsPathExcludedInterface`
 * already answers "excluded" for it. (That is also why the UPLOAD half never
 * failed: the plugin on `Magento\Framework\File\Uploader::save` DOES consult
 * that exclusion and skipped the tmp file. `save_category_image` consults
 * nothing and synchronised regardless.) The synchroniser threw, the throw is a
 * LocalizedException, and a LocalizedException out of an after-plugin does not
 * merely log — it surfaces on the form and aborts the save.
 *
 * ===========================================================================
 * WHY `<plugin name="save_category_image" disabled="true"/>` DID NOT FIX IT
 * ===========================================================================
 * That was the previous attempt, declared on the virtual type. It is inert, and
 * the reason is worth writing down so it is not tried again:
 *
 *   - `Interception\ObjectManager\Config\Developer::getInstanceType()` resolves
 *     a virtual type to its REAL type before appending `\Interceptor`, so the
 *     object built for `Spartrak\Homepage\BannerImageUpload` is a
 *     `Magento\Catalog\Model\ImageUploader\Interceptor`. The compiled config
 *     resolves it identically.
 *   - `Interception\Interceptor::___init()` then sets
 *     `$this->subjectType = get_parent_class($this)`, i.e.
 *     `Magento\Catalog\Model\ImageUploader`.
 *   - Every `___callPlugins()` looks its chain up under THAT name.
 *
 * So plugin configuration recorded against a virtual type is computed by
 * `PluginListGenerator::inheritPlugins()` and then never consulted at runtime.
 * Disabling the plugin on the real type instead would have worked — and would
 * have broken category images for the merchant, which is not a trade this
 * module gets to make.
 *
 * A subclass of the catalog uploader would inherit the same plugin, because
 * `inheritPlugins()` walks `RelationsInterface::getParents()`. The only way for
 * banner artwork to stop being treated as a category image is for its uploader
 * not to be one — hence this class, which stands alone and carries the two
 * methods this module actually calls.
 *
 * Nothing about the upload is home-grown: extension and MIME validation, the
 * safe-filename rewrite and the collision suffix all still come from
 * `Magento\MediaStorage\Model\File\Uploader`, `Magento\Framework\File\Name` and
 * `Magento\MediaStorage\Helper\File\Storage\Database` — the same three
 * collaborators core's own uploader delegates to, including the database-backed
 * media storage a clustered install needs.
 *
 * The two paths are read straight from Model\Image\Storage, the one place this
 * module declares them, so the write side here and the read side that builds
 * storefront URLs cannot drift apart.
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
        private readonly array $allowedMimeTypes = []
    ) {
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
    }

    /**
     * Validates one uploaded file and parks it in the staging directory.
     *
     * The file is NOT in its final home yet — it is promoted by
     * moveFileFromTmp() when the row is actually saved, so abandoning a
     * half-filled form leaves no artwork behind that no banner references.
     *
     * @return array<string, mixed> the descriptor the form's uploader expects
     * @throws LocalizedException
     */
    public function saveFileToTmpDir(string $fileId): array
    {
        $uploader = $this->uploaderFactory->create(['fileId' => $fileId]);
        $uploader->setAllowedExtensions($this->allowedExtensions);
        // Core's own setting: a name that is unsafe, or already taken, is
        // rewritten rather than rejected back at the merchant.
        $uploader->setAllowRenameFiles(true);

        // Extension alone is not proof of type — this reads the real bytes.
        if (!$uploader->checkMimeType($this->allowedMimeTypes)) {
            throw new LocalizedException(__('File validation failed.'));
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

        // The form's uploader reads `name` for the value it posts back, and
        // `url` for the thumbnail it shows while the row is still unsaved.
        $result['name'] = $result['file'];
        $result['url'] = $this->storeManager->getStore()
                ->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) . $relative;

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
     * exactly the value the banner row stores, so there is no second shape of
     * return value and no boolean to pick between them (core's own
     * `$returnRelativePath = false` branch returns the name it was ASKED for
     * rather than the one it wrote, which silently loses a `_1`).
     *
     * @throws LocalizedException
     */
    public function moveFileFromTmp(string $imageName): string
    {
        $source = $this->join(Storage::BASE_TMP_PATH, $imageName);

        $target = $this->join(
            Storage::BASE_PATH,
            $this->fileNameLookup->getNewFileName(
                $this->mediaDirectory->getAbsolutePath($this->join(Storage::BASE_PATH, $imageName))
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
     * Joins a directory and a file name into a media-relative path with exactly
     * one separator between them.
     */
    private function join(string $directory, string $file): string
    {
        return rtrim($directory, '/') . '/' . ltrim($file, '/');
    }
}
