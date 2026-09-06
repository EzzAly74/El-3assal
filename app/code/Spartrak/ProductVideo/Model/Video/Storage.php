<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\ProductVideo\Model\Video;

use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Where uploaded product video files live, and what their public URL is.
 *
 * The one place this module states the media sub-path. Model\Video\Uploader —
 * the write side — reads these same two constants, so the directory a file is
 * written to and the URL the storefront builds for it cannot drift apart.
 * Same shape, and the same reasoning, as Spartrak\Homepage\Model\Image\Storage.
 *
 * A directory of its own rather than `catalog/product`: these are not product
 * IMAGES, nothing should ever try to resize them, and keeping them out of the
 * catalog media tree means Magento's media-gallery synchroniser never sees a
 * 40 MB MP4 and tries to make a thumbnail of it.
 */
class Storage
{
    /** Final home, relative to pub/media. */
    public const BASE_PATH = 'spartrak/product-video';

    /** Staging area an upload lands in before the product is saved. */
    public const BASE_TMP_PATH = 'spartrak/product-video/tmp';

    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Public URL for a stored video file.
     *
     * Built through StoreManager rather than a literal `/media/`, so it stays
     * correct behind a CDN or a non-default media directory — the same rule
     * every other Spartrak media URL follows.
     *
     * Accepts a value that already carries the sub-path and normalises it, so
     * a row written by an older save, or pasted in by hand, is never silently
     * rendered as a 404.
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

    /** The media-relative path of a stored file, base path included exactly once. */
    public function getRelativePath(string $file): string
    {
        $file = $this->normalise($file);

        return $file === '' ? '' : self::BASE_PATH . '/' . $file;
    }

    private function normalise(string $file): string
    {
        $file = ltrim(trim($file), '/');

        if (str_starts_with($file, self::BASE_PATH . '/')) {
            $file = substr($file, strlen(self::BASE_PATH) + 1);
        }

        return ltrim($file, '/');
    }
}
