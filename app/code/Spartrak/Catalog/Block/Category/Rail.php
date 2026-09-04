<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Catalog\Block\Category;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Spartrak\Catalog\ViewModel\CategoryImage;

/**
 * "تسوق بالمنتجات" — the category page's tile rail (Figma 507:5234).
 *
 * A 28px heading on the inline-start edge and, beneath it, a scrolling row of
 * 134.779px square category tiles with the category's name under each
 * (Figma 507:5241 / 507:5242 / 507:5243 / 507:5244).
 *
 * ===========================================================================
 * THE SOURCE IS THE CATEGORY TREE. NOTHING NEW WAS MODELLED FOR IT.
 * ===========================================================================
 * The tiles are the CURRENT CATEGORY'S OWN CHILDREN, in the position order an
 * admin already drags them into under Catalog > Categories, showing each
 * child's own name and its own `image` attribute.
 *
 * That is deliberate, and it is why this component needs no table, no admin
 * grid, no ACL entry and no data patch:
 *   - "which tiles" is already a decision the category tree records;
 *   - "what order" is already `position`, already draggable;
 *   - "which picture" is already the category image field, already per store
 *     view, already uploaded through Magento's own media browser;
 *   - "what is it called" is already the category name, already translated
 *     per store view.
 * Adding a second registry beside that would give an admin two places to
 * express one intent and let them disagree.
 *
 * Spartrak_Homepage's `category_tiles` section is NOT reused here. That is a
 * different component: it is a navy reveal stage whose artwork is a static,
 * THEME-owned visual keyed by url_key, and its tile list is an explicit
 * admin-curated pick that has nothing to do with where the shopper is
 * standing. This rail is a plain white band showing where you can go from
 * HERE. Same design system, different component, different data.
 *
 * ===========================================================================
 * A LEAF CATEGORY RENDERS NOTHING
 * ===========================================================================
 * Not siblings, not the top level, not a placeholder — nothing. A rail
 * captioned "shop by product" that showed categories you are not in would be
 * inventing navigation the design does not specify, and an empty band that
 * still reserved its heading height would push the grid down for no content
 * (CLAUDE.md section 4, CLS).
 *
 * ===========================================================================
 * COST
 * ===========================================================================
 * ONE collection query for the tiles, selecting only the four columns the
 * template reads. URLs go through Category::getUrl(), which is a single
 * indexed url_rewrite lookup per tile; on a rail of a dozen children that is
 * a dozen point lookups, and the page is full-page-cached with this block's
 * identities on it, so they run once per category save rather than per
 * request. Preloading request_path onto the collection instead would trade
 * those for one join — worth doing if a category ever grows enough children
 * for it to show up in a profile, not before.
 */
class Rail extends Template implements IdentityInterface
{
    /**
     * Figma 507:5243 — the tile image is a 134.779px square. Rounded to the
     * whole pixel the browser would land on anyway; the fractional value is
     * an artefact of the Figma frame's own scaling, not a designed number.
     */
    public const TILE_SIZE = 135;

    /**
     * A ceiling, not a design number. Figma draws nine tiles; a category with
     * two hundred children would otherwise put two hundred images and two
     * hundred anchors into every response (CLAUDE.md section 4, DOM size).
     * Beyond this the rail shows the first N in admin order.
     */
    private const MAX_TILES = 24;

    /**
     * @var array<int, array{name: string, url: string, image: string}>|null
     */
    private ?array $tiles = null;

    public function __construct(
        Context $context,
        private readonly LayerResolver $layerResolver,
        private readonly CollectionFactory $categoryCollectionFactory,
        private readonly CategoryImage $imageResolver,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<int, array{name: string, url: string, image: string}>
     */
    public function getTiles(): array
    {
        if ($this->tiles !== null) {
            return $this->tiles;
        }

        $this->tiles = [];

        try {
            $this->tiles = $this->loadTiles();
        } catch (\Throwable $exception) {
            // A navigation aid must never take the category page down. Log
            // and render nothing rather than half a rail.
            $this->_logger->warning(
                'Spartrak category rail skipped: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
        }

        return $this->tiles;
    }

    public function getTileSize(): int
    {
        return self::TILE_SIZE;
    }

    /**
     * The rail's heading. Figma 507:5240 reads "تسوق بالمنتجات"; it is a
     * translatable UI string rather than category data, because it labels
     * what the rail IS, not what is in it.
     */
    public function getHeading(): string
    {
        return (string) __('Shop by product');
    }

    public function getHeadingId(): string
    {
        return 'spartrak-cat-rail-title';
    }

    /**
     * More than one tile is what makes it a carousel. With a single child
     * there is nothing to scroll, so the carousel widget is not requested at
     * all and the page never downloads it.
     */
    public function isCarousel(): bool
    {
        return count($this->getTiles()) > 1;
    }

    /**
     * @return string[]
     */
    public function getIdentities(): array
    {
        // The rail changes when the parent's child list changes and when any
        // child's own name or image changes, so every category that
        // contributed to this render is tagged. Saving any one of them drops
        // exactly the cached pages that showed it.
        $identities = [Category::CACHE_TAG];

        $category = $this->getCurrentCategory();

        if ($category !== null) {
            $identities[] = Category::CACHE_TAG . '_' . (int) $category->getId();
        }

        return $identities;
    }

    /**
     * @return array<int, array{name: string, url: string, image: string}>
     */
    private function loadTiles(): array
    {
        $category = $this->getCurrentCategory();

        if ($category === null || !$category->getId()) {
            return [];
        }

        $storeId = (int) $this->_storeManager->getStore()->getId();

        /** @var Collection $collection */
        $collection = $this->categoryCollectionFactory->create();

        /*
         * setStoreId() ALONE IS NOT ENOUGH ON A CATEGORY COLLECTION.
         *
         * On a product collection it switches attribute scope. On a category
         * collection it only records the id — the store-scoped VALUE join is
         * what setStore()/addAttributeToSelect resolve against, and without
         * it a store-scoped attribute like `image` comes back empty even
         * though the row exists in catalog_category_entity_varchar. The
         * symptom is not an error: every tile renders its plate at the right
         * size with no <img> inside, i.e. it looks exactly like a category
         * whose image was never uploaded.
         *
         * Both are set, in this order, so the collection is unambiguously
         * scoped before any attribute is selected.
         */
        $collection->setStore($this->_storeManager->getStore());
        $collection->setStoreId($storeId);

        $collection
            // Only what the template reads. `image` and `name` are the tile;
            // url_key/url_path are what Category::getUrl() resolves from.
            ->addAttributeToSelect(['name', 'image', 'url_key', 'url_path'])
            ->addAttributeToFilter('parent_id', (int) $category->getId())
            ->addAttributeToFilter('is_active', 1)
            ->setOrder('position', Collection::SORT_ORDER_ASC)
            ->setPageSize(self::MAX_TILES);

        $tiles = [];

        /** @var Category $child */
        foreach ($collection as $child) {
            $name = trim((string) $child->getName());

            if ($name === '') {
                continue;
            }

            $tiles[] = [
                'name' => $name,
                'url' => (string) $child->getUrl(),
                // '' when the admin has not uploaded one. The template then
                // renders the tile's neutral plate with no <img> — an honest
                // empty tile, never a stand-in picture (CLAUDE.md section 3).
                'image' => $this->imageResolver->resolve($child),
            ];
        }

        return $tiles;
    }

    private function getCurrentCategory(): ?Category
    {
        $category = $this->layerResolver->get()->getCurrentCategory();

        return $category instanceof Category ? $category : null;
    }
}
