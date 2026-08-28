<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\ViewModel;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
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
 * missing one.
 *
 * ===========================================================================
 * THE ARTWORK IS THE CATEGORY'S OWN IMAGE (changed 2026-08-28)
 * ===========================================================================
 * This used to read static, theme-owned files named after each category, and
 * ignore Catalog's category image entirely. In practice the design file draws
 * artwork for a handful of subjects, so every OTHER category an admin picked
 * rendered an empty grey box - which is what the section was actually doing on
 * the live site for all four of its categories.
 *
 * It now reads the category's own image, set in Catalog > Categories >
 * Content. That is where a merchandiser already manages what a category looks
 * like, it needs no deploy to change, and it makes picking the category the
 * single act that chooses both the destination and the picture.
 */
class CategoryTiles implements ArgumentInterface
{
    /** @var array<int, array<int, array<string, mixed>>> keyed by section id */
    private array $resolved = [];

    public function __construct(
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
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

            // One image serves both slots. The tile crops it to 308x206 and
            // the reveal visual to 788x535, both with object-fit, so a single
            // upload covers the pair rather than asking for two.
            $image = $this->categoryImageUrl($category);

            $tiles[] = [
                'id' => $categoryId,
                'name' => (string) $category->getName(),
                'url' => (string) $category->getUrl(),
                'description' => $this->getBlurb($category),
                'tile_image' => $image,
                'visual_image' => $image,
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

        // Only what a tile paints. `description` is the tile's blurb and
        // `image` is its artwork; nothing else on a category is read.
        $collection->addAttributeToSelect(['name', 'url_key', 'url_path', 'description', 'image']);
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
     * The category's own image URL, or '' when it has none.
     *
     * getImageUrl() is Magento's own accessor: it prefixes the store's media
     * base URL and the catalog/category/ path, so this stays correct behind a
     * CDN and across store views without this class knowing about either.
     *
     * A category with no image returns '' and the template omits the <img>
     * rather than rendering a broken one. That is a documented, recoverable
     * state - an admin fixes it by setting the image on the category - so it
     * is logged at debug, not as a warning.
     */
    private function categoryImageUrl(Category $category): string
    {
        try {
            $url = (string) ($category->getImageUrl() ?: '');
        } catch (\Exception $exception) {
            $this->logger->warning(
                'Spartrak_Homepage: category ' . $category->getId()
                . ' has an unreadable image: ' . $exception->getMessage()
            );

            return '';
        }

        if ($url === '') {
            $this->logger->debug(
                'Spartrak_Homepage: category ' . $category->getId()
                . ' has no image set, so its tile renders without artwork.'
            );
        }

        return $url;
    }
}
