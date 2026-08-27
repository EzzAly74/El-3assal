<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Spartrak\Homepage\Model\Banner;
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

    public function __construct(
        private readonly LocaleContext $localeContext,
        private readonly Storage $storage
    ) {
    }

    /**
     * @return array{
     *     desktop_url: string,
     *     mobile_url: string,
     *     width: int|null,
     *     height: int|null,
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

        return [
            'desktop_url' => $this->storage->getUrl($desktop),
            'mobile_url' => $this->storage->getUrl($mobile),
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
            'alt' => $this->getTitle($banner),
            'url' => trim((string) $banner->getData('url')),
            'media' => self::MOBILE_MEDIA_CONDITION,
        ];
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
