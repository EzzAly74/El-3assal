<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model;

use Spartrak\Homepage\Block\Section\Banner as BannerSection;
use Spartrak\Homepage\Block\Section\BrandCarousel as BrandCarouselSection;
use Spartrak\Homepage\Block\Section\CascadeSearch as CascadeSearchSection;
use Spartrak\Homepage\Block\Section\CategoryTiles as CategoryTilesSection;
use Spartrak\Homepage\Block\Section\ProductCarousel as ProductCarouselSection;
use Spartrak\Homepage\Block\Section\ProductPromoCarousel as ProductPromoCarouselSection;

/**
 * type => [block class, template]. The single place the mapping lives.
 *
 * ===========================================================================
 * WHY IT IS A CLASS AND NOT A CONST ON Block\Sections ANY MORE
 * ===========================================================================
 * It was a private const there, which was correct while the homepage was the
 * only page that rendered a section. It is not any more: the /brands landing
 * page mounts the cascade finder through Block\SectionByCode. Two blocks
 * needing the same map is exactly one map too few, and copying it would be
 * the duplication CLAUDE.md section 9 rules out — a section type added to one
 * copy and not the other renders on one page and vanishes on the other, with
 * nothing failing loudly.
 *
 * Adding a type stays a three-line job: a constant in Model\SectionType, an
 * entry here, and the template.
 *
 * ===========================================================================
 * WHY THESE ARE ALIASED IMPORTS AND NOT `Section\Banner::class`
 * ===========================================================================
 * This blanked the entire homepage once — every section rendered as an empty
 * string with no visible error — so the paragraph travels with the map.
 *
 * A file that also imports `Spartrak\Homepage\Model\Section` creates the
 * ALIAS `Section`, and PHP resolves a relative qualified name through an
 * alias whenever its first segment matches one. So `Section\Banner::class`
 * did NOT mean `Spartrak\Homepage\Block\Section\Banner` — it silently meant
 * `Spartrak\Homepage\Model\Section\Banner`, which does not exist.
 *
 * `::class` is resolved at compile time and never checks that the class is
 * real, so nothing failed until createBlock() ran, and the caller's catch
 * then turned the exception into ''. Result: a blank page and a log line.
 *
 * Fully-qualified aliased imports remove the ambiguity entirely — there is no
 * relative name left for a `Section` alias to capture. This file does not
 * import the Section model at all, which removes it a second time.
 */
class SectionRenderers
{
    /** @var array<string, array{0: class-string, 1: string}> */
    private const RENDERERS = [
        SectionType::BANNER => [
            BannerSection::class,
            'Spartrak_Homepage::section/banner.phtml',
        ],
        SectionType::CATEGORY_TILES => [
            CategoryTilesSection::class,
            'Spartrak_Homepage::section/category-tiles.phtml',
        ],
        SectionType::PRODUCT_CAROUSEL => [
            ProductCarouselSection::class,
            'Spartrak_Homepage::section/product-carousel.phtml',
        ],
        SectionType::PRODUCT_VIDEO_CAROUSEL => [
            ProductCarouselSection::class,
            'Spartrak_Homepage::section/product-video-carousel.phtml',
        ],
        SectionType::PRODUCT_PROMO_CAROUSEL => [
            ProductPromoCarouselSection::class,
            'Spartrak_Homepage::section/product-promo-carousel.phtml',
        ],
        SectionType::BRAND_CAROUSEL => [
            BrandCarouselSection::class,
            'Spartrak_Homepage::section/brand-carousel.phtml',
        ],
        SectionType::CASCADE_SEARCH => [
            CascadeSearchSection::class,
            'Spartrak_Homepage::section/cascade-search.phtml',
        ],
    ];

    /**
     * The block class and template for a section type, or null when the type
     * is unknown.
     *
     * Null rather than an exception: a row typed by hand into the database, or
     * a type removed in a later release, must be SKIPPED by the caller, not
     * allowed to take a page down with it. The caller logs it.
     *
     * @return array{0: class-string, 1: string}|null
     */
    public function get(string $type): ?array
    {
        return self::RENDERERS[$type] ?? null;
    }
}
