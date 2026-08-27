<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model;

use Spartrak\Homepage\Model\ResourceModel\Banner\CollectionFactory as BannerCollectionFactory;
use Spartrak\Homepage\Model\ResourceModel\CategoryItem\CollectionFactory as CategoryItemCollectionFactory;
use Spartrak\Homepage\Model\ResourceModel\Section\CollectionFactory as SectionCollectionFactory;

/**
 * Loads the whole homepage — every enabled section plus its children — in a
 * FIXED number of queries, no matter how many sections the dashboard holds.
 *
 * ===========================================================================
 * THE QUERY BUDGET, AND WHY IT IS A FIXED NUMBER
 * ===========================================================================
 * The obvious implementation is "load sections, then for each one load its
 * banners / its categories". That is the N+1 the brief rules out: ten
 * sections would mean eleven queries, and the count would grow every time an
 * admin adds a row in the dashboard.
 *
 * Instead this runs at most THREE queries for the entire page:
 *
 *   1. every enabled section, ordered
 *   2. every enabled banner belonging to ANY of those sections   (one IN())
 *   3. every enabled category pick belonging to ANY of them      (one IN())
 *
 * ...and queries 2 and 3 are skipped outright when no section of that type is
 * enabled. The flat results are then grouped in PHP, which costs nothing at
 * these row counts.
 *
 * Product loading is NOT part of this budget — it is per-section by nature
 * and lives in Model\Product\CategoryProductProvider, which applies its own
 * page-size cap.
 *
 * ===========================================================================
 * WHY THERE IS NO CACHE LAYER IN HERE
 * ===========================================================================
 * The homepage is served from Magento's full-page cache, so on the hot path
 * this class does not run at all. Adding a second cache underneath FPC would
 * buy nothing on the request that matters and would introduce a second thing
 * to invalidate. Invalidation is handled the native way instead: each section
 * is an IdentityInterface, the block returns those identities, and saving a
 * section in the dashboard drops exactly the cached pages that showed it.
 *
 * The in-request memo below is not a cache — it exists because the page's
 * block tree asks for the section list twice (once to render, once to report
 * cache identities), and that must not be two round trips.
 */
class SectionList
{
    /** @var Section[]|null */
    private ?array $sections = null;

    public function __construct(
        private readonly SectionCollectionFactory $sectionCollectionFactory,
        private readonly BannerCollectionFactory $bannerCollectionFactory,
        private readonly CategoryItemCollectionFactory $categoryItemCollectionFactory
    ) {
    }

    /**
     * Every enabled section, in dashboard order, with children attached.
     *
     * Children are attached as plain data on the section model
     * (`banners`, `category_items`) so the view layer never has to reach back
     * into a collection factory to finish rendering a row.
     *
     * @return Section[]
     */
    public function getSections(): array
    {
        if ($this->sections !== null) {
            return $this->sections;
        }

        /** @var ResourceModel\Section\Collection $collection */
        $collection = $this->sectionCollectionFactory->create();
        $collection->addActiveFilter();

        /** @var Section[] $sections */
        $sections = array_values($collection->getItems());

        if (!$sections) {
            return $this->sections = [];
        }

        $this->attachBanners($sections);
        $this->attachCategoryItems($sections);

        return $this->sections = $sections;
    }

    /**
     * Query 2 of the budget — all banners for all banner sections at once.
     *
     * @param Section[] $sections
     */
    private function attachBanners(array $sections): void
    {
        $sectionIds = $this->idsOfType($sections, [SectionType::BANNER]);

        if (!$sectionIds) {
            return;
        }

        /** @var ResourceModel\Banner\Collection $collection */
        $collection = $this->bannerCollectionFactory->create();
        $collection->addActiveForSections($sectionIds);

        $grouped = [];
        /** @var Banner $banner */
        foreach ($collection as $banner) {
            $grouped[(int) $banner->getSectionId()][] = $banner;
        }

        foreach ($sections as $section) {
            if (in_array((int) $section->getId(), $sectionIds, true)) {
                $section->setData('banners', $grouped[(int) $section->getId()] ?? []);
            }
        }
    }

    /**
     * Query 3 of the budget — all category picks for all tile sections.
     *
     * Only the pick rows are loaded here. The CATEGORIES themselves (name,
     * url, description) are resolved separately, in one batched EAV query,
     * by ViewModel\CategoryTiles — keeping this class free of catalogue
     * dependencies and keeping that EAV load out of sections that do not need
     * it.
     *
     * @param Section[] $sections
     */
    private function attachCategoryItems(array $sections): void
    {
        $sectionIds = $this->idsOfType($sections, [SectionType::CATEGORY_TILES]);

        if (!$sectionIds) {
            return;
        }

        /** @var ResourceModel\CategoryItem\Collection $collection */
        $collection = $this->categoryItemCollectionFactory->create();
        $collection->addActiveForSections($sectionIds);

        $grouped = [];
        /** @var CategoryItem $item */
        foreach ($collection as $item) {
            $grouped[(int) $item->getSectionId()][] = $item;
        }

        foreach ($sections as $section) {
            if (in_array((int) $section->getId(), $sectionIds, true)) {
                $section->setData('category_items', $grouped[(int) $section->getId()] ?? []);
            }
        }
    }

    /**
     * @param Section[] $sections
     * @param string[] $types
     * @return int[]
     */
    private function idsOfType(array $sections, array $types): array
    {
        $ids = [];

        foreach ($sections as $section) {
            if (in_array((string) $section->getType(), $types, true)) {
                $ids[] = (int) $section->getId();
            }
        }

        return $ids;
    }

    /**
     * Cache identities for every section on the page.
     *
     * Returned by the container block so the full-page cache entry carries a
     * tag per section — see the class note on invalidation.
     *
     * @return string[]
     */
    public function getIdentities(): array
    {
        $identities = [];

        foreach ($this->getSections() as $section) {
            $identities[] = $section->getIdentities();
        }

        return $identities ? array_values(array_unique(array_merge(...$identities))) : [];
    }
}
