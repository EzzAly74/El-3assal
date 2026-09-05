<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Block\Section;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Framework\View\Element\Template\Context;
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
    /**
     * Candidate widths for the panel's artwork. The box is capped at 604px
     * (.spartrak-home-split__image) and goes full-width inside the stacked
     * mobile panel, so the set spans both; 1208 is the retina end of it.
     *
     * @var int[]
     */
    private const PROMO_WIDTHS = [400, 604, 1208];

    /** The desktop cap, and therefore what `src` points at. */
    private const PROMO_DEFAULT_WIDTH = 604;

    public function __construct(
        Context $context,
        LocaleContext $localeContext,
        CategoryProductProvider $productProvider,
        CategoryUrl $categoryUrl,
        ImageHelper $imageHelper,
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

    /*
     * The per-locale resolver these four fields share lives on
     * AbstractSection::localised() - the same one the title and subtitle use.
     * It was duplicated here; it is not any more.
     */

    public function getPromoBadge(): string
    {
        return $this->localised('promo_badge');
    }

    public function getPromoHeading(): string
    {
        return $this->localised('promo_heading');
    }

    public function getPromoText(): string
    {
        return $this->localised('promo_text');
    }

    /**
     * The panel's artwork: the SOURCE CATEGORY'S own image, derived at the
     * sizes the panel draws it.
     *
     * Not an upload on this form any more. The section already names a
     * category, that category already carries an image in Catalog, and asking
     * an editor to upload a second copy of it here made two places to keep one
     * fact - and two places to forget. Choosing the category now chooses the
     * artwork. See ViewModel\CategoryUrl::getImage() for the full note.
     *
     * It DOES now return width/height, reversing the note that used to stand
     * here. Two things changed: reading the header is no longer per-render
     * (Model\Image\Resizer memoises within the request and the homepage is
     * full-page-cached, so it is once per cache build), and the CSS
     * aspect-ratio that comment relied on to reserve the box does not exist —
     * .spartrak-home-split__image is `width: 100%; height: auto` with no ratio
     * declared, so the panel below it was shifting on every cold load.
     *
     * @return array{url: string, srcset: string, width: int|null, height: int|null}
     */
    public function getPromoImage(): array
    {
        $categoryId = $this->getSection()?->getCategoryId();

        return $categoryId === null
            ? ['url' => '', 'srcset' => '', 'width' => null, 'height' => null]
            : $this->categoryUrl->getImage($categoryId, self::PROMO_WIDTHS, self::PROMO_DEFAULT_WIDTH);
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
            || $this->getPromoImage()['url'] !== '';
    }
}
