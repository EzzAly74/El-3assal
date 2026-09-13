<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Block;

use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Spartrak\Homepage\Block\Section\AbstractSection;
use Spartrak\Homepage\Model\Section;
use Spartrak\Homepage\Model\SectionList;
use Spartrak\Homepage\Model\SectionRenderers;

/**
 * The homepage. The ONLY block the homepage layout mounts.
 *
 * ===========================================================================
 * NO CMS BLOCKS, NO CMS CONTENT, ANYWHERE ON THIS PAGE
 * ===========================================================================
 * cms_index_index is Magento's homepage handle, but nothing about this page's
 * content comes from Magento_Cms: not a block, not a page, not a widget. The
 * layout file mounts this class and nothing else, and every section below is
 * assembled from this module's own tables. That is a hard architectural
 * requirement of the brief, and it is enforced structurally — there is no
 * code path here that can reach CMS content.
 *
 * ===========================================================================
 * HOW A SECTION BECOMES HTML
 * ===========================================================================
 *   dashboard row  ->  Model\SectionList (3 queries, all sections)
 *                  ->  type  ->  child block class + template
 *                  ->  rendered in dashboard sort order
 *
 * A section type is a lookup, not a conditional chain, so adding a type never
 * edits this method's logic — see Model\SectionType.
 *
 * ===========================================================================
 * CACHING
 * ===========================================================================
 * Implements IdentityInterface, so the full-page cache entry for the homepage
 * carries a tag for every section that rendered into it. Saving a section (or
 * any banner belonging to one — see Model\Banner::getIdentities) invalidates
 * exactly the pages that showed it. The block itself is left cacheable: there
 * is nothing customer-specific in any section, and punching a hole here would
 * cost an uncached block render on the site's most-hit page.
 */
class Sections extends Template implements IdentityInterface
{
    /**
     * How long a rendered-sections entry survives when nothing invalidates it.
     *
     * Content changes do NOT wait for this - they arrive through the identity
     * tags (see getCacheLifetime's note). A day matches Magento's own default
     * full-page-cache TTL (system/full_page_cache/ttl), so the block entry and
     * the page entry containing it expire on the same clock instead of one
     * outliving the other.
     */
    private const CACHE_LIFETIME = 86400;

    public function __construct(
        Context $context,
        private readonly SectionList $sectionList,
        private readonly HttpContext $httpContext,
        // The type => [block, template] map used to be a private const here.
        // It moved to Model\SectionRenderers when Block\SectionByCode gained a
        // second caller for it — see that class for the whole reasoning,
        // including why its entries are fully-qualified aliased imports.
        private readonly SectionRenderers $sectionRenderers,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * ===========================================================================
     * WHY THIS BLOCK IS CACHED, AND WHAT IT COST NOT TO BE
     * ===========================================================================
     * The full-page cache is not a guarantee, it is a hit rate. Every
     * `cache:flush`, every `setup:upgrade`, every `static-content:deploy`, every
     * TTL expiry and every URL a crawler invents empties or misses it - and on a
     * miss, whoever asked for the page waits for it to be built. Measured on the
     * live store, mobile UA, brotli, three consecutive forced misses:
     *
     *     homepage   MISS  5.19 s / 5.75 s / 4.98 s      HIT  0.68 s
     *     PDP        MISS  1.23 s                        HIT  0.65 s
     *
     * The PDP renders the same 272KB header, the same mega-nav, the same
     * stylesheet and the same footer in 1.2 s. So the ~4 s difference is this
     * page's own eight sections - 58 product cards over four catalog collections
     * - and it was being paid again, in full, on every single miss. That is the
     * dominant term in the homepage's LCP whenever the page is cold, which after
     * any deploy is every visitor until the first one has paid for it.
     *
     * ===========================================================================
     * WHY cache_lifetime IS THE WHOLE FIX
     * ===========================================================================
     * The block already implemented IdentityInterface, so its tags were correct -
     * but tags alone do nothing here. Magento\Framework\View\Element\AbstractBlock
     * only consults the block cache when a lifetime is set:
     *
     *     protected function _loadCache()
     *     {
     *         if ($this->getCacheLifetime() === null || !$this->_cacheState->isEnabled(self::CACHE_GROUP)) {
     *             return false;
     *
     * `getCacheLifetime()` returns null by default, so `_loadCache()` returned
     * false on every render and the eight sections were rebuilt from the catalog
     * every time. Declaring a lifetime is what switches the mechanism on; the
     * invalidation side was already right and is not touched. `getCacheTags()`
     * merges `getIdentities()` on its own, so saving a section or one of its
     * banners in the dashboard still drops exactly the entries that showed it -
     * no lifetime-based staleness window for content changes.
     *
     * The lifetime is therefore a floor, not the invalidation strategy: it only
     * decides how long an entry survives when nothing has changed.
     *
     * ===========================================================================
     * WHY IT IS SAFE, WHICH IS THE ONLY QUESTION THAT MATTERS
     * ===========================================================================
     * The class docblock above already asserts "there is nothing
     * customer-specific in any section" - and that assertion is load-bearing
     * twice over, because it is also what licenses the page to sit in the PUBLIC
     * full-page cache at all. If it were false, the site would already be serving
     * one shopper's homepage to another; block caching adds no new exposure.
     *
     * Even so, the key carries the two axes Magento's own page cache varies on
     * rather than trusting that assertion to stay true:
     *
     *   store      the sections are per-store-view content (title_ar/title_en,
     *              LTR/RTL, per-locale category names), so an Arabic entry must
     *              never answer an English request. AbstractBlock's default key
     *              is the block NAME alone - one entry for the whole
     *              installation - which would have done exactly that.
     *   group+auth read from Framework\App\Http\Context, which is the same
     *              source the full-page cache varies on, so a block entry can
     *              never outlive or out-scope the page entry containing it.
     *              Product cards paint prices; customer-group pricing and
     *              catalog rules make those group-dependent whether or not this
     *              store uses more than one group today.
     *
     * Http\Context is used deliberately in preference to Customer\Model\Session:
     * touching the session from a cached block is how a block quietly becomes
     * uncacheable, and the context object is the sanctioned read for exactly
     * this decision.
     */
    protected function getCacheLifetime()
    {
        return self::CACHE_LIFETIME;
    }

    /**
     * @return array<int, mixed>
     */
    public function getCacheKeyInfo(): array
    {
        return [
            'SPARTRAK_HOMEPAGE_SECTIONS',
            $this->_storeManager->getStore()->getId(),
            $this->httpContext->getValue(CustomerContext::CONTEXT_GROUP),
            $this->httpContext->getValue(CustomerContext::CONTEXT_AUTH),
        ];
    }

    /**
     * @return Section[]
     */
    public function getSections(): array
    {
        return $this->sectionList->getSections();
    }

    /**
     * Renders one section through its typed child block.
     *
     * `position` is handed down because the LCP rule depends on it: the first
     * section on the page owns the largest above-the-fold paint, and only it
     * is allowed to mark an image as high priority (CLAUDE.md section 12).
     * Every later section is below the fold and lazy-loads.
     */
    public function renderSection(Section $section, int $position): string
    {
        $type = (string) $section->getType();
        $renderer = $this->sectionRenderers->get($type);

        if ($renderer === null) {
            // A row typed by hand into the database, or a type removed in a
            // later release. Skipped rather than fatal — a broken row must
            // not take the homepage down with it.
            $this->_logger->warning(
                'Spartrak_Homepage: section "' . $section->getCode() . '" has unknown type "' . $type . '".'
            );

            return '';
        }

        [$blockClass, $template] = $renderer;

        try {
            /** @var AbstractSection $child */
            $child = $this->getLayout()->createBlock($blockClass);
            $child->setTemplate($template);
            $child->setSection($section);
            $child->setPosition($position);

            return $child->toHtml();
        } catch (\Exception $exception) {
            $this->_logger->error(
                'Spartrak_Homepage: section "' . $section->getCode() . '" failed to render: '
                . $exception->getMessage(),
                ['exception' => $exception]
            );

            return '';
        }
    }

    /**
     * @return string[]
     */
    public function getIdentities(): array
    {
        return $this->sectionList->getIdentities();
    }
}
