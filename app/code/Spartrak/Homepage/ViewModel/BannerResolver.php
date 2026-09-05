<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Spartrak\Homepage\Model\Banner;
use Spartrak\Homepage\Model\Image\Resizer;
use Spartrak\Homepage\Model\Image\Storage;
use Spartrak\Homepage\Model\LocaleContext;

/**
 * Turns one stored banner row into everything the template needs to paint it.
 *
 * ===========================================================================
 * THE TWO-AXIS CHOICE
 * ===========================================================================
 * A banner row holds four images — desktop/mobile x English/Arabic. The
 * LANGUAGE axis is resolved HERE, server-side, because the store view is
 * known before a byte of HTML is sent. The VIEWPORT axis is resolved by the
 * BROWSER, through <picture>/<source media>, because the server does not know
 * the viewport and must not guess at it with user-agent sniffing.
 *
 * That split is what makes the brief's rule enforceable — "never load both
 * desktop and mobile assets on one render, never download a mobile asset on
 * desktop". A <picture> element downloads exactly ONE candidate: the browser
 * evaluates the media conditions before the preload scanner fetches anything.
 * A CSS background-image swap or a JS-driven src would both fail that test.
 *
 * ===========================================================================
 * WHY IT RETURNS AN ARRAY RATHER THAN RENDERING
 * ===========================================================================
 * Markup belongs to the theme (CLAUDE.md section 7). This produces the data —
 * urls, dimensions, alt text, link — and the template owns every tag.
 */
class BannerResolver implements ArgumentInterface
{
    /**
     * The viewport switch, kept identical to the theme's own
     * @breakpoint-header-collapse. Above it the page is laid out as desktop;
     * at or below it, as mobile. Hardcoding a different number here would
     * mean the banner swapped at one width and the rest of the page at
     * another.
     */
    public const MOBILE_MEDIA_CONDITION = '(max-width: 767px)';

    /**
     * ===========================================================================
     * WHY THE HERO NEEDS A CANDIDATE SET AT ALL
     * ===========================================================================
     * <picture> already stopped a phone downloading the desktop file, which is
     * what this class was built to do. What it never addressed is the size of
     * the file it DOES download, and nothing in Magento resizes an admin
     * upload that is not a product image. Measured on the live homepage:
     *
     *   Group_2_1.webp        5760x1200   204,614 B   drawn into 1440x300
     *   home_hero_mob.webp    1344x 784   133,508 B   drawn into  440x220
     *
     * The desktop file is four times the linear size of its own box. It is
     * also the LCP element, so those bytes sit on the critical path - the one
     * place CLAUDE.md section 12 says to spend the least.
     *
     * A 1440-wide derivative of it measures 45,226 B. The candidates below let
     * the browser pick that, or the 2880 retina one, from the real viewport
     * and device ratio instead of every device taking the largest.
     *
     * @var int[]
     */
    private const DESKTOP_WIDTHS = [768, 1200, 1440, 1920, 2880];

    /** The authored desktop box, so `src` points at the 1x file. */
    private const DESKTOP_DEFAULT_WIDTH = 1440;

    /**
     * The mobile frame is full-bleed, so the widest realistic ask is a ~430pt
     * phone at 3x. The set stops there because Resizer never enlarges and the
     * uploads are not wider than this anyway.
     *
     * @var int[]
     */
    private const MOBILE_WIDTHS = [440, 768, 880, 1100, 1344];

    private const MOBILE_DEFAULT_WIDTH = 880;

    public function __construct(
        private readonly LocaleContext $localeContext,
        private readonly Storage $storage,
        private readonly Resizer $resizer
    ) {
    }

    /**
     * @return array{
     *     desktop_url: string,
     *     mobile_url: string,
     *     desktop_srcset: string,
     *     mobile_srcset: string,
     *     width: int|null,
     *     height: int|null,
     *     mobile_width: int|null,
     *     mobile_height: int|null,
     *     alt: string,
     *     url: string,
     *     media: string
     * }|null  null when the row has no usable artwork at all
     */
    public function resolve(Banner $banner): ?array
    {
        $suffix = $this->localeContext->getColumnSuffix();
        $fallback = $this->localeContext->getFallbackColumnSuffix();

        $desktop = $this->pick($banner, 'image_desktop', $suffix, $fallback);
        $mobile = $this->pick($banner, 'image_mobile', $suffix, $fallback);

        // A row with no desktop artwork still renders if it has mobile
        // artwork — one image on every viewport beats a hole in the page.
        // A row with neither is skipped entirely by the caller.
        if ($desktop === '' && $mobile === '') {
            return null;
        }

        if ($desktop === '') {
            $desktop = $mobile;
        }

        if ($mobile === '') {
            $mobile = $desktop;
        }

        // Dimensions come from the DESKTOP file because that is what the
        // <img> element itself points at; the <source> only overrides which
        // bytes are fetched, and the reserved box is set from the img.
        $dimensions = $this->storage->getDimensions($desktop);

        // The MOBILE file is a different asset with its own shape, and the
        // <source> swap happens before the img ever loads. Its ratio is read
        // here so the frame can reserve the correct box on a phone too —
        // without it a portrait mobile hero would be cropped to the desktop
        // ratio, which is exactly the artwork loss this pair of values fixes.
        // Re-reading the same path is free: Storage memoises per request.
        $mobileDimensions = $mobile === $desktop
            ? $dimensions
            : $this->storage->getDimensions($mobile);

        $desktopUrl = $this->storage->getUrl($desktop);
        $mobileUrl = $this->storage->getUrl($mobile);

        return [
            'desktop_url' => $desktopUrl,
            'mobile_url' => $mobileUrl,
            'desktop_srcset' => $this->srcset($desktopUrl, self::DESKTOP_WIDTHS, self::DESKTOP_DEFAULT_WIDTH),
            'mobile_srcset' => $mobile === $desktop
                ? $this->srcset($desktopUrl, self::DESKTOP_WIDTHS, self::DESKTOP_DEFAULT_WIDTH)
                : $this->srcset($mobileUrl, self::MOBILE_WIDTHS, self::MOBILE_DEFAULT_WIDTH),
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
            'mobile_width' => $mobileDimensions[0] ?? null,
            'mobile_height' => $mobileDimensions[1] ?? null,
            'alt' => $this->getTitle($banner),
            'url' => trim((string) $banner->getData('url')),
            'media' => self::MOBILE_MEDIA_CONDITION,
        ];
    }

    /**
     * The `w`-descriptor candidate list for one banner image, or '' when no
     * derivative could be produced.
     *
     * '' is the important case, not an edge case: an SVG upload has no raster
     * header, a host may lack a WebP encoder, and pub/media can be read-only
     * mid-deploy. Each returns null from the Resizer, and the template then
     * omits `srcset` entirely and renders exactly the markup it rendered
     * before any of this - the original file, at full size. Slower than it
     * could be, identical to what shipped, and never a missing hero.
     *
     * @param int[] $widths
     */
    private function srcset(string $url, array $widths, int $defaultWidth): string
    {
        $resized = $this->resizer->responsive($url, $widths, $defaultWidth);

        return $resized === null ? '' : $resized['srcset'];
    }

    /**
     * The banner's accessible name.
     *
     * Returns '' rather than a placeholder when the admin left both titles
     * empty: alt="" is the CORRECT markup for an image that carries no
     * information a screen-reader user needs, and inventing "Banner 3" would
     * be noise read aloud on every page load. The dashboard exposes the field
     * so a merchant can describe a banner that IS informative.
     */
    public function getTitle(Banner $banner): string
    {
        $suffix = $this->localeContext->getColumnSuffix();
        $fallback = $this->localeContext->getFallbackColumnSuffix();

        $title = trim((string) $banner->getData('title_' . $suffix));

        return $title !== '' ? $title : trim((string) $banner->getData('title_' . $fallback));
    }

    /**
     * Current locale's column, falling back to the other language's.
     */
    private function pick(Banner $banner, string $base, string $suffix, string $fallback): string
    {
        $value = trim((string) $banner->getData($base . '_' . $suffix));

        return $value !== '' ? $value : trim((string) $banner->getData($base . '_' . $fallback));
    }
}
