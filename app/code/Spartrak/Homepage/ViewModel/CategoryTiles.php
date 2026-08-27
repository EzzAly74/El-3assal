<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\ViewModel;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Spartrak\Homepage\Model\CategoryItem;
use Spartrak\Homepage\Model\Section;
use Psr\Log\LoggerInterface;

/**
 * Data for the "الفئات الأكثر بحثا" tile section (Figma node 595:15067).
 *
 * ===========================================================================
 * THE OWNERSHIP SPLIT THIS CLASS ENFORCES
 * ===========================================================================
 * The brief draws an unusual line for this one section, and it is drawn here:
 *
 *   DASHBOARD owns  ->  WHICH categories appear, in WHAT ORDER, and the
 *                       section title. (Model\CategoryItem rows.)
 *   MAGENTO owns    ->  each category's NAME, URL and blurb — real catalogue
 *                       data, resolved below in ONE batched EAV query.
 *   THEME owns      ->  every pixel of artwork. The tile image and the large
 *                       reveal visual are STATIC FRONTEND ASSETS and are
 *                       explicitly NOT configurable from the dashboard, so no
 *                       image column exists on the category-pick table and
 *                       the category's own Magento image is deliberately NOT
 *                       read.
 *
 * ===========================================================================
 * HOW A CATEGORY FINDS ITS STATIC ARTWORK — AND WHY BY url_key
 * ===========================================================================
 * With no dashboard field to point at a file, the mapping has to be a
 * convention. It is the category's `url_key`, with its id as a fallback:
 *
 *     view/frontend/web/images/categories/<url_key>-tile.webp
 *     view/frontend/web/images/categories/<url_key>-visual.webp
 *     view/frontend/web/images/categories/category-<id>-tile.webp
 *     view/frontend/web/images/categories/category-<id>-visual.webp
 *
 * url_key rather than POSITION, because position is exactly what an admin
 * reorders from the dashboard — an index-based mapping would silently
 * reassign every photograph the first time someone dragged a row, putting a
 * piston next to "filters". url_key is stable, already unique per category,
 * and readable, so a designer can drop a file in without consulting a table.
 * The id form is there because an Arabic url_key transliterates
 * inconsistently, and the id is printed right in the dashboard's own picker.
 *
 * The files live in the MODULE so it is self-contained; either Spartrak theme
 * can override any of them through the usual view fallback.
 *
 * A category with no matching file renders with its artwork slot empty rather
 * than borrowing another category's photograph: CLAUDE.md section 3 forbids
 * substituting a visual asset, and a wrong photo is a worse failure than a
 * missing one. See this module's README for the current asset inventory and
 * what is still outstanding.
 */
class CategoryTiles implements ArgumentInterface
{
    /** Where the theme keeps this section's artwork. */
    private const ASSET_DIR = 'Spartrak_Homepage::images/categories/';

    /**
     * Tried in order. WebP first because it is materially smaller at the same
     * quality and every browser this storefront supports reads it; the raster
     * fallbacks exist so a designer dropping in a PNG still gets a rendered
     * tile rather than silence.
     */
    private const ASSET_EXTENSIONS = ['webp', 'png', 'jpg'];

    /** @var array<int, array<int, array<string, mixed>>> keyed by section id */
    private array $resolved = [];

    /** @var array<string, string|null> */
    private array $assetUrls = [];

    public function __construct(
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly AssetRepository $assetRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Every tile for one section, in dashboard order.
     *
     * ONE EAV query for the whole section regardless of how many categories
     * the dashboard holds — the pick rows are already loaded by
     * Model\SectionList, and this resolves all of their categories together
     * rather than loading them one at a time.
     *
     * @return array<int, array{
     *     id: int,
     *     name: string,
     *     url: string,
     *     description: string,
     *     tile_image: string|null,
     *     visual_image: string|null
     * }>
     */
    public function getTiles(Section $section): array
    {
        $sectionId = (int) $section->getId();

        if (isset($this->resolved[$sectionId])) {
            return $this->resolved[$sectionId];
        }

        /** @var CategoryItem[] $items */
        $items = $section->getData('category_items') ?: [];

        if (!$items) {
            return $this->resolved[$sectionId] = [];
        }

        // Dashboard order, preserved: the EAV collection comes back in
        // whatever order the database chooses, so the picks' order is
        // captured first and used to re-sort at the end.
        $orderedIds = [];
        foreach ($items as $item) {
            $orderedIds[] = $item->getCategoryId();
        }

        $categories = $this->loadCategories($orderedIds);
        $tiles = [];

        foreach ($orderedIds as $categoryId) {
            if (!isset($categories[$categoryId])) {
                // Category deleted or disabled since it was picked. Skipped
                // silently — the dashboard row still exists so an admin can
                // see and fix it, and a dead tile must not reach a shopper.
                continue;
            }

            $category = $categories[$categoryId];
            $urlKey = (string) $category->getUrlKey();

            $tiles[] = [
                'id' => $categoryId,
                'name' => (string) $category->getName(),
                'url' => (string) $category->getUrl(),
                'description' => $this->getBlurb($category),
                'tile_image' => $this->findAsset($urlKey, $categoryId, 'tile'),
                'visual_image' => $this->findAsset($urlKey, $categoryId, 'visual'),
            ];
        }

        return $this->resolved[$sectionId] = $tiles;
    }

    /**
     * @param int[] $categoryIds
     * @return array<int, Category>
     */
    private function loadCategories(array $categoryIds): array
    {
        $collection = $this->categoryCollectionFactory->create();

        // Only what a tile paints. `description` is included because it is
        // the tile's blurb; nothing else on a category is read.
        $collection->addAttributeToSelect(['name', 'url_key', 'url_path', 'description']);
        $collection->addFieldToFilter('entity_id', ['in' => $categoryIds]);
        $collection->addAttributeToFilter('is_active', 1);

        // No setStoreId() call: Catalog's EAV collection resolves the current
        // store lazily on first use (AbstractCollection::getStoreId), so the
        // names and URLs below already come back in the store view's own
        // language. Setting it explicitly here would be a no-op that reads
        // like a safeguard.

        $byId = [];
        /** @var Category $category */
        foreach ($collection as $category) {
            $byId[(int) $category->getId()] = $category;
        }

        return $byId;
    }

    /**
     * The tile's one-line blurb (Figma 595:15096).
     *
     * Uses the category's own description — real, admin-managed catalogue
     * content — flattened to text. Returns '' when there is none, and the
     * template then omits the line rather than printing filler.
     */
    private function getBlurb(Category $category): string
    {
        $description = (string) $category->getDescription();

        if (trim($description) === '') {
            return '';
        }

        // Category descriptions are WYSIWYG HTML. The tile is a single
        // typographic line, so markup is stripped rather than escaped-and-
        // rendered: this line must never become an injection point or a place
        // where a stray <div> breaks the card's layout.
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($description)) ?? '');

        return mb_strlen($text) > 120 ? mb_substr($text, 0, 119) . '…' : $text;
    }

    /**
     * Static theme asset URL for one category slot, or null when none is
     * shipped.
     *
     * TWO NAMING KEYS ARE ACCEPTED, url_key first:
     *
     *     <url_key>-tile.webp        preferred — readable, and survives a
     *     <url_key>-visual.webp      category being re-parented or renamed
     *
     *     category-<id>-tile.webp    fallback — for a category whose url_key
     *     category-<id>-visual.webp  is unstable or awkward to type (Arabic
     *                                url keys transliterate inconsistently)
     *
     * The id form exists because the id is what an admin can read straight
     * off the dashboard's category picker, which makes "which file do I name
     * this?" answerable without a database lookup.
     *
     * getSourceFile() throws when a file is absent anywhere in the theme
     * fallback chain, which makes it a reliable existence test that also
     * honours a theme overriding the module's artwork.
     */
    private function findAsset(string $urlKey, int $categoryId, string $slot): ?string
    {
        $candidates = [];

        if ($urlKey !== '') {
            $candidates[] = $urlKey . '-' . $slot;
        }

        $candidates[] = 'category-' . $categoryId . '-' . $slot;

        $memoKey = implode('|', $candidates);

        if (array_key_exists($memoKey, $this->assetUrls)) {
            return $this->assetUrls[$memoKey];
        }

        foreach ($candidates as $baseName) {
            foreach (self::ASSET_EXTENSIONS as $extension) {
                try {
                    $asset = $this->assetRepository->createAsset(
                        self::ASSET_DIR . $baseName . '.' . $extension
                    );
                    $asset->getSourceFile();

                    return $this->assetUrls[$memoKey] = $asset->getUrl();
                } catch (\Exception $exception) {
                    continue;
                }
            }
        }

        // Deliberately a debug line, not a warning: a category without
        // artwork is a known, documented state while the asset set is being
        // completed, not a fault. See view/frontend/web/images/categories/
        // README.md for the naming contract.
        $this->logger->debug(
            'Spartrak_Homepage: no static "' . $slot . '" artwork for category ' . $categoryId
            . ' (looked for ' . $memoKey . ').'
        );

        return $this->assetUrls[$memoKey] = null;
    }
}
