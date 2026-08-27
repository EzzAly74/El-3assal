<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Block\Section;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Framework\View\Element\Template\Context;
use Spartrak\Homepage\Model\Image\Storage;
use Spartrak\Homepage\Model\LocaleContext;
use Spartrak\Homepage\Model\Product\CategoryProductProvider;
use Spartrak\Homepage\ViewModel\CategoryUrl;

/**
 * The split product section (Figma 595:15329) — a rail beside a promo panel.
 *
 * ===========================================================================
 * IT EXTENDS ProductCarousel RATHER THAN REPEATING IT
 * ===========================================================================
 * The rail half is the SAME thing the plain carousel renders: same category,
 * same limit, same provider, same shared Card - Product, same LCP rules. So
 * this class inherits all of it and adds only what the promo panel needs.
 *
 * The practical consequence is the one that matters to a merchant: switching a
 * section between "Product carousel" and "Product carousel with promo panel"
 * is a dropdown change that KEEPS its category, its product limit and its
 * headings. The two layouts are one component in Figma, and they are one
 * component here.
 */
class ProductPromoCarousel extends ProductCarousel
{
    public function __construct(
        Context $context,
        LocaleContext $localeContext,
        CategoryProductProvider $productProvider,
        CategoryUrl $categoryUrl,
        ImageHelper $imageHelper,
        private readonly Storage $storage,
        array $data = []
    ) {
        parent::__construct($context, $localeContext, $productProvider, $categoryUrl, $imageHelper, $data);
    }

    /**
     * Figma 595:15333 keeps the prev/next buttons in the rail's own header on
     * this layout too, so the shared section header still carries them.
     */
    public function showsCarouselNav(): bool
    {
        return $this->isCarousel();
    }

    /**
     * Current locale's value for a promo field, falling back to the other
     * language rather than leaving a hole in the panel.
     */
    private function promo(string $base): string
    {
        $section = $this->getSection();

        if ($section === null) {
            return '';
        }

        $value = trim((string) $section->getData($base . '_' . $this->localeContext->getColumnSuffix()));

        if ($value !== '') {
            return $value;
        }

        return trim((string) $section->getData($base . '_' . $this->localeContext->getFallbackColumnSuffix()));
    }

    public function getPromoBadge(): string
    {
        return $this->promo('promo_badge');
    }

    public function getPromoHeading(): string
    {
        return $this->promo('promo_heading');
    }

    public function getPromoText(): string
    {
        return $this->promo('promo_text');
    }

    /**
     * Promo artwork, resolved to a URL plus its intrinsic size.
     *
     * Dimensions come from the real file header (same helper the banners use),
     * so the panel reserves the right box and contributes nothing to CLS.
     *
     * @return array{url: string, width: int|null, height: int|null}|null
     */
    public function getPromoImage(): ?array
    {
        $file = $this->promo('promo_image');

        if ($file === '') {
            return null;
        }

        $dimensions = $this->storage->getDimensions($file);

        return [
            'url' => $this->storage->getUrl($file),
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
        ];
    }

    /**
     * True when the panel has anything at all to show.
     *
     * A section of this type with an EMPTY promo panel still renders — as the
     * plain rail, full width. That is the graceful outcome: an admin who
     * switches the type before filling the panel in sees the rail they already
     * had, not a broken half-empty layout.
     */
    public function hasPromo(): bool
    {
        return $this->getPromoBadge() !== ''
            || $this->getPromoHeading() !== ''
            || $this->getPromoText() !== ''
            || $this->getPromoImage() !== null;
    }
}
