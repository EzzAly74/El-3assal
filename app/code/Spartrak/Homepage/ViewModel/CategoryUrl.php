<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\ViewModel;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Spartrak\Homepage\Model\Image\Resizer;
use Psr\Log\LoggerInterface;

/**
 * A section's source category, resolved once and asked for what it holds.
 *
 * Split out of the carousel block so every product section resolves its
 * category through one class rather than three copies of the same try/catch.
 * The CATEGORY is memoised, not just its URL, because the promo panel now asks
 * the same category for its artwork - two lookups of one row would otherwise be
 * two repository loads.
 */
class CategoryUrl implements ArgumentInterface
{
    /**
     * Memo of loaded categories. `false` records a lookup that already failed,
     * so a bad id is not retried once per accessor per render.
     *
     * @var array<int, CategoryInterface|false>
     */
    private array $categories = [];

    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly Resizer $resizer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Returns '' for a missing or disabled category, and the template then
     * omits the link rather than rendering one that 404s.
     */
    public function get(int $categoryId): string
    {
        $category = $this->load($categoryId);

        return $category === null ? '' : (string) $category->getUrl();
    }

    /**
     * The category's own image, as set in Catalog > Categories > Content,
     * derived at the sizes the panel actually draws it.
     *
     * ===========================================================================
     * WHY THE PROMO PANEL HAS NO UPLOAD OF ITS OWN
     * ===========================================================================
     * The split section's artwork used to be two more image fields on the
     * section form. That asked an editor to upload, by hand, a picture of the
     * very category they had just chosen from the dropdown directly above - and
     * then to remember to re-upload it whenever that category's own imagery
     * changed. Two places to keep one fact.
     *
     * The category already owns an image, in the place a merchandiser expects
     * to manage it. Reading it here means picking the category IS picking the
     * artwork, and Catalog stays the single source of truth for what a category
     * looks like.
     *
     * `Category::getImageUrl()` is Magento's own accessor for it: it prefixes
     * the store's media base URL and the catalog/category/ path, so this stays
     * correct behind a CDN and across store views.
     *
     * ===========================================================================
     * WHY THE RAW URL IS NOT WHAT GETS SERVED
     * ===========================================================================
     * Magento has no resizer for category images at all — `getImageUrl()`
     * hands back the untouched upload — so the promo panel was serving a
     * 1249x848, 782 KB PNG into a box capped at 604px wide. Measured on the
     * live homepage; the 604-wide WebP derivative is 20,270 bytes.
     *
     * The width/height that come back with it are the second half of the fix.
     * This <img> is `width: 100%; height: auto` with NO CSS aspect-ratio, so
     * until it loaded the browser reserved zero height for it and the panel
     * below jumped — a layout shift the template's own comment claimed CSS was
     * handling and which CSS was not.
     *
     * Falls back to the raw URL with null dimensions whenever a derivative
     * cannot be produced (an SVG upload, a host with no WebP encoder, a
     * read-only pub/media during a deploy). A heavy image is a performance
     * defect; a missing one is a broken page.
     *
     * @param int[] $widths
     * @return array{url: string, srcset: string, width: int|null, height: int|null}
     */
    public function getImage(int $categoryId, array $widths, int $defaultWidth): array
    {
        $empty = ['url' => '', 'srcset' => '', 'width' => null, 'height' => null];
        $category = $this->load($categoryId);

        if (!$category instanceof Category) {
            return $empty;
        }

        try {
            $original = (string) ($category->getImageUrl() ?: '');
        } catch (\Exception $exception) {
            $this->logger->warning(
                'Spartrak_Homepage: no image for category ' . $categoryId . ': ' . $exception->getMessage()
            );

            return $empty;
        }

        if ($original === '' || $defaultWidth < 1) {
            return ['url' => $original, 'srcset' => '', 'width' => null, 'height' => null];
        }

        $path = $this->resizer->categoryImagePath((string) $category->getData('image'));
        $resized = $path === '' ? null : $this->resizer->responsive($path, $widths, $defaultWidth);

        return $resized ?? ['url' => $original, 'srcset' => '', 'width' => null, 'height' => null];
    }

    private function load(int $categoryId): ?CategoryInterface
    {
        if ($categoryId <= 0) {
            return null;
        }

        if (array_key_exists($categoryId, $this->categories)) {
            return $this->categories[$categoryId] ?: null;
        }

        try {
            return $this->categories[$categoryId] = $this->categoryRepository->get($categoryId);
        } catch (\Exception $exception) {
            $this->logger->warning(
                'Spartrak_Homepage: could not load category ' . $categoryId . ': ' . $exception->getMessage()
            );

            $this->categories[$categoryId] = false;

            return null;
        }
    }
}
