<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model;

/**
 * The renderer types a homepage section can take.
 *
 * A section's `type` is the ONLY thing that decides which template renders
 * it. Everything else — title, order, enabled state, the category it reads
 * from, the banners hanging off it — is shared column data, which is what
 * makes "add another banner section" a dashboard row rather than a deploy.
 *
 * Adding a type is three edits: a constant here, a case in
 * Block\Sections::getTemplateFor(), and the template itself. Nothing else in
 * the module needs to know the list.
 */
final class SectionType
{
    /**
     * One or more admin-managed images. ONE enabled image renders as a plain
     * static banner; TWO OR MORE render as a carousel — see
     * Block\Section\Banner::isCarousel().
     */
    public const BANNER = 'banner';

    /**
     * Admin-picked categories shown as tiles with a static, theme-owned
     * visual per tile (Figma node 595:15067, "الفئات الأكثر بحثا").
     */
    public const CATEGORY_TILES = 'category_tiles';

    /**
     * Real products from one admin-chosen category, in the shared product
     * carousel (Figma 595:15115 "الأكثر مبيعا", 595:14586 "عروض مميزه").
     */
    public const PRODUCT_CAROUSEL = 'product_carousel';

    /**
     * Same product source as PRODUCT_CAROUSEL, rendered with the video
     * treatment from Figma 595:14821 ("شاهد المنتج، وأحكم بنفسك").
     */
    public const PRODUCT_VIDEO_CAROUSEL = 'product_video_carousel';

    /**
     * Types whose content comes from a category product query.
     *
     * @return string[]
     */
    public static function productTypes(): array
    {
        return [self::PRODUCT_CAROUSEL, self::PRODUCT_VIDEO_CAROUSEL];
    }

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::BANNER,
            self::CATEGORY_TILES,
            self::PRODUCT_CAROUSEL,
            self::PRODUCT_VIDEO_CAROUSEL,
        ];
    }
}
