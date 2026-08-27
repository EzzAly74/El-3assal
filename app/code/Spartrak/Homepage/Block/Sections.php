<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Block;

use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Spartrak\Homepage\Model\Section;
use Spartrak\Homepage\Model\SectionList;
use Spartrak\Homepage\Model\SectionType;

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
     * type => [block class, template]. The single place the mapping lives.
     */
    private const RENDERERS = [
        SectionType::BANNER => [
            Section\Banner::class,
            'Spartrak_Homepage::section/banner.phtml',
        ],
        SectionType::CATEGORY_TILES => [
            Section\CategoryTiles::class,
            'Spartrak_Homepage::section/category-tiles.phtml',
        ],
        SectionType::PRODUCT_CAROUSEL => [
            Section\ProductCarousel::class,
            'Spartrak_Homepage::section/product-carousel.phtml',
        ],
        SectionType::PRODUCT_VIDEO_CAROUSEL => [
            Section\ProductCarousel::class,
            'Spartrak_Homepage::section/product-video-carousel.phtml',
        ],
    ];

    public function __construct(
        Context $context,
        private readonly SectionList $sectionList,
        array $data = []
    ) {
        parent::__construct($context, $data);
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

        if (!isset(self::RENDERERS[$type])) {
            // A row typed by hand into the database, or a type removed in a
            // later release. Skipped rather than fatal — a broken row must
            // not take the homepage down with it.
            $this->_logger->warning(
                'Spartrak_Homepage: section "' . $section->getCode() . '" has unknown type "' . $type . '".'
            );

            return '';
        }

        [$blockClass, $template] = self::RENDERERS[$type];

        try {
            /** @var Section\AbstractSection $child */
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
