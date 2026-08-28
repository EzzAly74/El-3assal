<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Helper\Stock as StockHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * The products behind every category-driven homepage section.
 *
 * ONE implementation serves "الأكثر مبيعا", "عروض مميزه" and "شاهد المنتج،
 * وأحكم بنفسك" — the brief's requirement that the product-section
 * architecture not be duplicated per section. The sections differ only in
 * which category id and limit the dashboard hands over, and in the template
 * that renders the result.
 *
 * ===========================================================================
 * WHY A CATEGORY COLLECTION AND NOT THE LAYER / A CATEGORY BLOCK
 * ===========================================================================
 * Magento\Catalog\Model\Layer is stateful: it resolves and MUTATES a shared
 * current-category context. Running it three times on one page to fetch three
 * unrelated rails would leave the last one's category as the page's context.
 * A plain product collection with addCategoryFilter() gives the same rows
 * with none of that.
 *
 * ===========================================================================
 * THE CATALOGUE IS LARGE — WHAT THAT CHANGES
 * ===========================================================================
 *  - setPageSize() is applied BEFORE the collection is walked, so MySQL
 *    LIMITs the row set rather than PHP slicing a full category load.
 *  - addAttributeToSelect() lists the attributes the card actually paints
 *    and nothing else. `*` on a large EAV catalogue is a join per attribute.
 *  - No getSize() call anywhere: nothing in these sections shows a total, and
 *    a COUNT over a large category is pure waste.
 *
 * ===========================================================================
 * WHY addCategoryFilter() AND NOT addCategoriesFilter()
 * ===========================================================================
 * This shipped as addCategoriesFilter(['in' => [$categoryId]]) with a comment
 * claiming it covered anchor categories. It does not, and that silently blanked
 * a whole homepage section.
 *
 * addCategoriesFilter() builds `entity_id IN (SELECT product_id FROM
 * catalog_category_product WHERE category_id IN (...))`. That table holds
 * DIRECT assignments only. A top-level anchor category — the kind a merchant
 * naturally picks in the dashboard — usually has no products assigned to it at
 * all; its page is populated entirely from its subtree. So the query returned
 * zero rows, hasContent() was false, and the section rendered nothing while
 * that same category's own page showed twelve products.
 *
 * addCategoryFilter() is Magento's own category-listing path. It filters
 * through catalog_category_product_index, which the indexer builds with the
 * subtree already flattened for anchor categories and scoped per store. The
 * rail therefore shows exactly what the chosen category's page shows, which is
 * the only behaviour a merchant can predict.
 *
 * It also makes `position` sortable: with a category filter set, the
 * collection resolves addAttributeToSort('position') to cat_index.position —
 * the merchandising order an admin already dragged into place inside the
 * category. Without the filter that sort silently did nothing.
 */
class CategoryProductProvider
{
    /**
     * Attributes the shared Card - Product template reads. `small_image` and
     * `thumbnail` are both here because Magento's image helper falls back
     * between them when a role is unset on a product.
     */
    private const CARD_ATTRIBUTES = ['name', 'small_image', 'thumbnail', 'image', 'special_price'];

    private const XML_PATH_SHOW_OUT_OF_STOCK = 'cataloginventory/options/show_out_of_stock';

    /**
     * Memo keyed by category+limit. Two sections pointing at the same
     * category — a legitimate dashboard setup while content is being set up —
     * must not run the query twice.
     *
     * @var array<string, ProductInterface[]>
     */
    private array $memo = [];

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly Visibility $visibility,
        private readonly ProductStatus $productStatus,
        private readonly StockHelper $stockHelper,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param bool $withMediaGallery Load each product's media gallery too —
     *        ONLY the video section needs it, and it costs an extra query, so
     *        the two plain carousels leave it off. See loadMediaGallery().
     * @return ProductInterface[]
     */
    public function getProducts(int $categoryId, int $limit, bool $withMediaGallery = false): array
    {
        if ($categoryId <= 0 || $limit <= 0) {
            return [];
        }

        $key = $categoryId . ':' . $limit . ':' . ($withMediaGallery ? 'media' : 'plain');

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $category = $this->loadCategory($categoryId);

        if ($category === null) {
            // The dashboard points at a category that no longer exists, or one
            // outside this store. An empty rail is the honest outcome; the
            // section then skips itself entirely.
            $this->logger->warning(
                'Spartrak_Homepage: section category ' . $categoryId . ' was not found for this store.'
            );

            return $this->memo[$key] = [];
        }

        try {
            $collection = $this->collectionFactory->create();

            $collection->addAttributeToSelect(self::CARD_ATTRIBUTES);
            $collection->addStoreFilter();

            // Anchor-aware and index-backed — see the class note. A top-level
            // category shows its whole subtree here exactly as it does on its
            // own page.
            $collection->addCategoryFilter($category);

            $collection->addAttributeToFilter(
                'status',
                ['in' => $this->productStatus->getVisibleStatusIds()]
            );

            // getVisibleInCatalogIds(), not getVisibleInSearchIds(): these
            // rails are a catalogue surface, so a search-only product must not
            // appear on them.
            $collection->setVisibility($this->visibility->getVisibleInCatalogIds());

            // Respects the store's own "Display Out of Stock Products"
            // setting rather than imposing a rule of our own — if the
            // storefront shows out-of-stock products everywhere else, the
            // homepage showing them too is correct, and if it hides them the
            // homepage must hide them as well.
            if (!$this->isShowOutOfStock()) {
                $this->stockHelper->addInStockFilterToCollection($collection);
            }

            // Price data for the card. addMinimalPrice() covers the composite
            // product types whose card price is a "from" figure; the other two
            // are what Magento's own price renderer expects to find loaded.
            $collection->addMinimalPrice();
            $collection->addFinalPrice();
            $collection->addTaxPercents();

            $collection->setPageSize($limit);
            $collection->setCurPage(1);

            // Deterministic order. `position` is the merchandising order an
            // admin already sets inside the category, so the dashboard's
            // choice of category carries that ordering with it for free;
            // entity_id breaks ties so the rail cannot reshuffle between two
            // full-page-cache generations.
            $collection->addAttributeToSort('position', 'ASC');
            $collection->addAttributeToSort('entity_id', 'DESC');

            if ($withMediaGallery) {
                $this->loadMediaGallery($collection);
            }

            return $this->memo[$key] = array_values($collection->getItems());
        } catch (\Exception $exception) {
            // A misconfigured category id must degrade to an empty rail that
            // the template then skips — never to a 500 on the homepage. It is
            // logged rather than swallowed (CLAUDE.md section 9).
            $this->logger->error(
                'Spartrak_Homepage: could not load products for category ' . $categoryId . ': '
                . $exception->getMessage(),
                ['exception' => $exception]
            );

            return $this->memo[$key] = [];
        }
    }

    /**
     * Media gallery for the whole rail in ONE query, not one per product.
     *
     * addMediaGalleryData() is Magento's own batched loader: it issues a
     * single createBatchBaseSelect() over every id in the collection. Calling
     * $product->getMediaGalleryImages() on each card instead would be a query
     * per card — the exact N+1 the brief rules out.
     *
     * Failure here is non-fatal: the video section falls back to the product
     * image, which is what it does for a product with no video anyway.
     *
     * @param \Magento\Catalog\Model\ResourceModel\Product\Collection $collection
     */
    private function loadMediaGallery($collection): void
    {
        try {
            $collection->addMediaGalleryData();
        } catch (\Exception $exception) {
            $this->logger->warning(
                'Spartrak_Homepage: media gallery could not be loaded for the video section: '
                . $exception->getMessage()
            );
        }
    }

    /**
     * The chosen category, loaded as cheaply as it can be loaded.
     *
     * addCategoryFilter() reads exactly two things off the model: the id, and
     * whether it is an anchor. So this is a one-row collection selecting the
     * single `is_anchor` attribute rather than a repository get(), which would
     * hydrate every category attribute to answer one boolean.
     *
     * addIdFilter + setPageSize(1) keeps it to a primary-key lookup.
     */
    private function loadCategory(int $categoryId): ?Category
    {
        try {
            $collection = $this->categoryCollectionFactory->create();
            $collection->addAttributeToSelect('is_anchor');
            $collection->addIdFilter([$categoryId]);
            $collection->setPageSize(1);

            /** @var Category $category */
            $category = $collection->getFirstItem();

            return $category->getId() ? $category : null;
        } catch (\Exception $exception) {
            $this->logger->error(
                'Spartrak_Homepage: could not load category ' . $categoryId . ': '
                . $exception->getMessage(),
                ['exception' => $exception]
            );

            return null;
        }
    }

    private function isShowOutOfStock(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHOW_OUT_OF_STOCK,
            ScopeInterface::SCOPE_STORE
        );
    }
}
