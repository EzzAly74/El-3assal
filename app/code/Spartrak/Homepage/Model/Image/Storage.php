<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model\Image;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Where banner artwork lives, what it is called, and how big it is.
 *
 * The one place that knows the media sub-path. etc/adminhtml/di.xml points
 * the admin image uploader at the SAME two constants, so the write side and
 * the read side cannot drift apart.
 */
class Storage
{
    /** Final home, relative to pub/media. */
    public const BASE_PATH = 'spartrak/homepage';

    /** Staging area an upload lands in before the row is saved. */
    public const BASE_TMP_PATH = 'spartrak/homepage/tmp';

    /**
     * Intrinsic sizes, memoised per file.
     *
     * @var array<string, array{0: int, 1: int}|null>
     */
    private array $dimensions = [];

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Public URL for a stored banner file.
     *
     * Accepts a value that may already carry the sub-path (older rows, or a
     * value pasted in by hand) and normalises it, so a saved row is never
     * silently rendered as a 404.
     */
    public function getUrl(string $file): string
    {
        $file = $this->normalise($file);

        if ($file === '') {
            return '';
        }

        return $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA)
            . self::BASE_PATH . '/' . $file;
    }

    /**
     * Intrinsic pixel size of a stored banner, or null if it cannot be read.
     *
     * WHY THIS EXISTS: the brief requires no layout shift, which means every
     * banner needs real width/height attributes. The alternative — assuming
     * one aspect ratio for every banner an admin will ever upload — would
     * either letterbox their artwork or shift the page when the true ratio
     * differs.
     *
     * WHY THE COST IS ACCEPTABLE: getimagesize() reads only the file header,
     * and the homepage is served from the full-page cache — so this runs once
     * per cache generation, not once per visitor. The result is memoised
     * within the request as well, because desktop and mobile variants of the
     * same banner are frequently the same file.
     *
     * @return array{0: int, 1: int}|null [width, height]
     */
    public function getDimensions(string $file): ?array
    {
        $file = $this->normalise($file);

        if ($file === '') {
            return null;
        }

        if (array_key_exists($file, $this->dimensions)) {
            return $this->dimensions[$file];
        }

        try {
            $media = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            $relative = self::BASE_PATH . '/' . $file;

            if (!$media->isExist($relative) || !$media->isReadable($relative)) {
                return $this->dimensions[$file] = null;
            }

            $size = @getimagesize($media->getAbsolutePath($relative));

            if (!is_array($size) || (int) $size[0] <= 0 || (int) $size[1] <= 0) {
                // SVG has no raster header, so getimagesize() legitimately
                // fails on it. Not an error worth logging — the caller falls
                // back to a CSS aspect-ratio box, which reserves space just
                // as well.
                return $this->dimensions[$file] = null;
            }

            return $this->dimensions[$file] = [(int) $size[0], (int) $size[1]];
        } catch (\Exception $exception) {
            $this->logger->warning(
                'Spartrak_Homepage: could not read dimensions of banner "' . $file . '": '
                . $exception->getMessage()
            );

            return $this->dimensions[$file] = null;
        }
    }

    /**
     * The media-relative path of a stored file, base path included exactly
     * once.
     *
     * Exposed because the stored column value is NOT a bare filename: the
     * admin save promotes an upload with moveFileFromTmp($name, true), whose
     * `true` returns the path WITH the base path already on it. Callers that
     * built their own "BASE_PATH . '/' . $value" therefore produced
     * `spartrak/homepage/spartrak/homepage/x.png` and silently found nothing.
     * Both admin data providers now ask this instead, so the assumption lives
     * in one place next to the normaliser that resolves it.
     */
    public function getRelativePath(string $file): string
    {
        $file = $this->normalise($file);

        return $file === '' ? '' : self::BASE_PATH . '/' . $file;
    }

    /**
     * Strips any leading slash and any copy of the base path the stored value
     * already carries.
     */
    private function normalise(string $file): string
    {
        $file = trim($file);
        $file = ltrim($file, '/');

        if (str_starts_with($file, self::BASE_PATH . '/')) {
            $file = substr($file, strlen(self::BASE_PATH) + 1);
        }

        return ltrim($file, '/');
    }
}
