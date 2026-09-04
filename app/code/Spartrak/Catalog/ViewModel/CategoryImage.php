<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Catalog\ViewModel;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\DataObject;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Turns a category image ATTRIBUTE VALUE into a URL.
 *
 * Shared by the two category-page components that read category artwork —
 * the hero band (Block\Category\Hero, the `spartrak_hero_image*` attributes)
 * and the "تسوق بالمنتجات" tile rail (Block\Category\Rail, the category's own
 * native `image`). One implementation rather than two, because getting it
 * subtly wrong produces a silently broken picture rather than an error.
 *
 * WHY A VIEWMODEL. Hero extends Magento\Catalog\Block\Category\View and
 * deliberately declares no constructor (see that class for why), so it cannot
 * inject anything. A view model handed in as a layout argument is Magento's
 * own answer to exactly that, and it keeps the URL rule in one place for both
 * consumers instead of duplicating it into a block that can inject and a
 * block that cannot.
 *
 * THREE VALUE SHAPES, ALL REAL. Magento's category-image backend model stores
 * a bare filename, but a value that arrived by import, migration or
 * programmatic set can legitimately be `catalog/category/foo.jpg` or an
 * absolute URL. All three are handled rather than assuming the first.
 */
class CategoryImage implements ArgumentInterface
{
    /**
     * Where Magento's category-image backend model keeps its files, relative
     * to the media root.
     */
    private const MEDIA_SUBPATH = 'catalog/category/';

    /**
     * getimagesize() results, keyed by media-relative path.
     *
     * A banner's own file decides its aspect ratio, so the dimensions have to
     * be read off disk — but the same file is asked about more than once per
     * render (the <img> attributes and the reserved box), and a category page
     * can carry several. One stat per file per request, never per call.
     *
     * @var array<string, array{0: int, 1: int}|null>
     */
    private array $dimensionCache = [];

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * The image's real pixel dimensions, or null when they cannot be read.
     *
     * ===========================================================================
     * WHY THIS READS THE FILE INSTEAD OF ASSUMING A RATIO
     * ===========================================================================
     * The hero band has a Figma frame (1440x318) so its box can be reserved
     * from the design. The promo band below the tile rail has no frame at all
     * — it is a business requirement, not a drawn component — so there is no
     * designed ratio to reserve it at, and inventing one would either letterbox
     * or crop whatever an admin uploads.
     *
     * Reading the real header instead means the <img> carries true width and
     * height attributes, and every modern browser derives the aspect ratio from
     * those and reserves the box before a byte of image data arrives. Zero CLS,
     * no invented number, and the band is exactly the shape of the artwork.
     *
     * COST: one getimagesize() per file per request, on a page served from the
     * full-page cache — so once per category save, not once per visitor. The
     * same trade Spartrak_Homepage's banner section already makes.
     *
     * Returns null for anything unreadable — a missing file, a remote URL, an
     * SVG with no raster header — and the template then falls back to CSS for
     * the reservation rather than emitting a wrong number.
     *
     * @param CategoryInterface|DataObject|null $category
     * @return array{0: int, 1: int}|null
     */
    public function dimensions($category, string $attributeCode = 'image'): ?array
    {
        $path = $this->relativePath($category, $attributeCode);

        if ($path === '') {
            return null;
        }

        if (array_key_exists($path, $this->dimensionCache)) {
            return $this->dimensionCache[$path];
        }

        $this->dimensionCache[$path] = null;

        try {
            $media = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);

            if (!$media->isExist($path) || !$media->isFile($path)) {
                return null;
            }

            $size = getimagesize($media->getAbsolutePath($path));

            if (is_array($size) && (int) $size[0] > 0 && (int) $size[1] > 0) {
                $this->dimensionCache[$path] = [(int) $size[0], (int) $size[1]];
            }
        } catch (\Throwable $e) {
            // A reserved box is an optimisation, never a requirement. An
            // unreadable file must not take the page down with it.
            unset($e);
        }

        return $this->dimensionCache[$path];
    }

    /**
     * The value as a path relative to the MEDIA ROOT, or '' when it is not a
     * local media file at all.
     *
     * Deliberately returns '' for an absolute URL: a remote asset cannot be
     * stat-ed, and pretending otherwise would mean a filesystem read against
     * an attacker-supplied string.
     *
     * @param CategoryInterface|DataObject|null $category
     */
    private function relativePath($category, string $attributeCode): string
    {
        if (!$category instanceof DataObject) {
            return '';
        }

        $value = $category->getData($attributeCode);

        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        if ($value === '' || preg_match('#^(?:https?:)?//#i', $value) === 1) {
            return '';
        }

        $path = ltrim($value, '/');

        if (str_starts_with($path, 'media/')) {
            $path = substr($path, strlen('media/'));
        }

        if (!str_starts_with($path, self::MEDIA_SUBPATH)) {
            $path = self::MEDIA_SUBPATH . $path;
        }

        // No traversal reaches the filesystem read above. The value is admin
        // supplied, so this is belt and braces rather than the only guard.
        return str_contains($path, '..') ? '' : $path;
    }

    /**
     * @param CategoryInterface|DataObject|null $category
     */
    public function resolve($category, string $attributeCode = 'image'): string
    {
        if (!$category instanceof DataObject) {
            return '';
        }

        $value = $category->getData($attributeCode);

        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        if ($value === '') {
            return '';
        }

        /*
         * ASK MAGENTO FIRST. Category::getImageUrl() is core's own resolver
         * for exactly this attribute family and it already knows every shape
         * the value can take on a given install.
         *
         * THIS REPLACED A HAND-BUILT URL, AND THE BUG IS WORTH RECORDING.
         * The previous version prepended 'catalog/category/' to anything that
         * did not start with a scheme. But the backend model legitimately
         * stores a ROOT-RELATIVE path on many installs — '/media/catalog/
         * category/foo.png' — which has no scheme, so it fell through the
         * guard and was concatenated into
         *
         *     https://site/media/catalog/category/media/catalog/category/foo.png
         *
         * a 404 that renders as an empty box of exactly the right size. Every
         * tile looked like a placeholder while the data behind it was fine.
         */
        if (method_exists($category, 'getImageUrl')) {
            try {
                $url = $category->getImageUrl($attributeCode);

                if (is_string($url) && $url !== '') {
                    return $url;
                }
            } catch (\Throwable $e) {
                // Older signatures take no argument, and a malformed value
                // throws rather than returning false. Fall through to the
                // manual path below rather than losing the image.
                unset($e);
            }
        }

        // Already absolute (or protocol-relative) — hand it back untouched.
        if (preg_match('#^(?:https?:)?//#i', $value) === 1) {
            return $value;
        }

        $path = ltrim($value, '/');

        /*
         * Strip a leading 'media/' before deciding whether to add the
         * catalog/category/ prefix: the base media URL already ends in
         * /media/, so leaving it on double-prefixes the path.
         */
        if (str_starts_with($path, 'media/')) {
            $path = substr($path, strlen('media/'));
        }

        if (!str_starts_with($path, self::MEDIA_SUBPATH)) {
            $path = self::MEDIA_SUBPATH . $path;
        }

        return $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) . $path;
    }
}
