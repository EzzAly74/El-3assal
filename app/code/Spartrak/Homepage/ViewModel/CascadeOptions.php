<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\ViewModel;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Spartrak\Catalog\ViewModel\BrandNavigation;

/**
 * Options for the "بتدور علي ايه؟" cascading finder (Figma 595:15843).
 *
 * ===========================================================================
 * WHAT THE FOUR FIELDS ARE ACTUALLY BUILT ON — AND WHY
 * ===========================================================================
 * Figma labels them الماركة / النوع / المحرك / الموديل (Brand / Type / Engine /
 * Model). Only ONE of those is an attribute on this catalogue.
 *
 * VERIFIED against the live storefront rather than assumed: the layered
 * navigation on a search-results page exposes exactly one filterable
 * attribute, `brand`. There is no `model` attribute and no `engine` attribute;
 * "المحرك" exists on this site as a top-level CATEGORY name, not as an
 * attribute value. Building four attribute-backed dropdowns would therefore
 * have meant inventing three attributes and populating them with guesses —
 * which CLAUDE.md §9 rules out.
 *
 * So the finder is built on the two things that are real:
 *
 *   1. الماركة  -> the `brand` attribute's options (independent)
 *   2. النوع    -> top-level categories
 *   3. المحرك   -> children of the chosen Type      <- cascades
 *   4. الموديل  -> children of the chosen Engine    <- cascades
 *
 * Fields 2-4 are one category drill-down, which is exactly the journey the
 * design describes ("choose the make and model to reach the right parts") and
 * matches Figma's own Type placeholder, "اختر النوع ( المحرك, الالة)" — it
 * names المحرك as a TYPE VALUE, confirming these levels are categories.
 *
 * Levels 3 and 4 are fetched on demand by Controller\Ajax\CascadeOptions, so
 * the homepage ships only the first two levels and never serialises the whole
 * tree into the page.
 *
 * ===========================================================================
 * WHERE "SHOW RESULTS" GOES
 * ===========================================================================
 * The deepest selected category's own URL, with `?brand=<optionId>` appended
 * when a brand is chosen — `brand` is a real filterable attribute, so the
 * category page's own layered navigation applies it. With only a brand chosen,
 * it falls back to the SAME url the header's brand tiles use. No new search
 * route is invented for the submit.
 */
class CascadeOptions implements ArgumentInterface
{
    /** @var array<int, array<int, array{value: int, label: string, url: string}>> */
    private array $childCache = [];

    public function __construct(
        private readonly BrandNavigation $brandNavigation,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Brand options — the same list, order and URLs the header uses.
     *
     * @return array<int, array{label: string, value: string, url: string}>
     */
    public function getBrands(): array
    {
        $brands = [];

        foreach ($this->brandNavigation->getBrands() as $brand) {
            $brands[] = [
                'label' => $brand['label'],
                'value' => $brand['value'],
                'url' => $brand['url'],
            ];
        }

        return $brands;
    }

    /**
     * Level 1 of the category drill-down: the store's own root children.
     *
     * @return array<int, array{value: int, label: string, url: string}>
     */
    public function getTopCategories(): array
    {
        try {
            $rootId = (int) $this->storeManager->getStore()->getRootCategoryId();
        } catch (\Exception $exception) {
            $this->logger->warning(
                'Spartrak_Homepage: cascade finder could not resolve the store root: '
                . $exception->getMessage()
            );

            return [];
        }

        return $this->getChildren($rootId);
    }

    /**
     * Children of one category — the query behind every cascade step.
     *
     * ONE flat query per level, selecting only what a dropdown row needs,
     * filtered to active + in-menu categories so the finder can never offer a
     * dead end a shopper cannot reach any other way.
     *
     * Each option carries its own storefront URL: that is what the submit
     * navigates to, so the button leads to a real category page instead of a
     * synthesised search query. Resolving it here — where the category is
     * already loaded — costs nothing extra.
     *
     * @return array<int, array{value: int, label: string, url: string}>
     */
    public function getChildren(int $parentId): array
    {
        if ($parentId <= 0) {
            return [];
        }

        if (isset($this->childCache[$parentId])) {
            return $this->childCache[$parentId];
        }

        try {
            $collection = $this->categoryCollectionFactory->create();
            // url_path as well as url_key: getUrl() needs it to build the
            // full path for a nested category without a per-row lookup.
            $collection->addAttributeToSelect(['name', 'url_key', 'url_path']);
            $collection->addFieldToFilter('parent_id', $parentId);
            $collection->addAttributeToFilter('is_active', 1);
            $collection->addAttributeToFilter('include_in_menu', 1);
            $collection->addAttributeToSort('position', 'ASC');

            $options = [];
            /** @var Category $category */
            foreach ($collection as $category) {
                $options[] = [
                    'value' => (int) $category->getId(),
                    'label' => (string) $category->getName(),
                    'url' => (string) $category->getUrl(),
                ];
            }

            return $this->childCache[$parentId] = $options;
        } catch (\Exception $exception) {
            $this->logger->error(
                'Spartrak_Homepage: cascade finder failed loading children of ' . $parentId
                . ': ' . $exception->getMessage(),
                ['exception' => $exception]
            );

            return $this->childCache[$parentId] = [];
        }
    }

    /**
     * True when there is enough real data for the finder to be usable at all.
     *
     * A finder with no brands AND no categories is a form that cannot produce
     * a result, so the section renders nothing rather than a dead card.
     */
    public function hasOptions(): bool
    {
        return $this->getBrands() !== [] || $this->getTopCategories() !== [];
    }
}
