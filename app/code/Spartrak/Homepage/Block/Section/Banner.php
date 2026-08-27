<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Block\Section;

use Magento\Framework\View\Element\Template\Context;
use Spartrak\Homepage\Model\Banner as BannerModel;
use Spartrak\Homepage\Model\LocaleContext;
use Spartrak\Homepage\ViewModel\BannerResolver;

/**
 * A banner section: one static image, or several as a carousel.
 *
 * ===========================================================================
 * THE SINGLE-vs-CAROUSEL RULE
 * ===========================================================================
 * The brief is precise about this and it is enforced in isCarousel():
 *
 *   1 enabled banner   ->  a plain static banner. NO carousel markup, NO
 *                          arrows, NO dots, and — the part that actually
 *                          costs something — the carousel JS is never
 *                          requested, because the template only emits the
 *                          data-mage-init attribute that pulls it in when
 *                          there is more than one slide.
 *   2+ enabled banners ->  a carousel, in dashboard sort order.
 *
 * "Do not initialize or load carousel functionality when only one item
 * exists" is therefore true in the strong sense: on a single-banner homepage
 * the browser downloads and parses zero carousel bytes.
 *
 * This block is reused by EVERY banner section on the page. A second, third
 * or tenth banner section is a dashboard row — no new model, table, template
 * or JS.
 */
class Banner extends AbstractSection
{
    public function __construct(
        Context $context,
        LocaleContext $localeContext,
        private readonly BannerResolver $bannerResolver,
        array $data = []
    ) {
        parent::__construct($context, $localeContext, $data);
    }

    /**
     * Enabled banners for this section, already resolved to renderable data,
     * in dashboard order.
     *
     * Rows whose artwork is missing entirely are dropped HERE rather than in
     * the template, so isCarousel() counts what will actually paint — a
     * section holding one good banner and one empty row must render as a
     * static banner, not as a one-slide carousel.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBanners(): array
    {
        if ($this->hasData('resolved_banners')) {
            return $this->getData('resolved_banners');
        }

        $resolved = [];
        $section = $this->getSection();

        /** @var BannerModel[] $banners */
        $banners = $section ? ($section->getData('banners') ?: []) : [];

        foreach ($banners as $banner) {
            $data = $this->bannerResolver->resolve($banner);

            if ($data !== null) {
                $resolved[] = $data;
            }
        }

        $this->setData('resolved_banners', $resolved);

        return $resolved;
    }

    public function isCarousel(): bool
    {
        return count($this->getBanners()) > 1;
    }

    public function hasContent(): bool
    {
        return $this->getBanners() !== [];
    }

    /**
     * Loading strategy for slide $index.
     *
     * The first slide of the FIRST section on the page is the LCP element —
     * it is the biggest thing above the fold — so it loads eagerly at high
     * priority. Every other slide, and every banner in every later section,
     * is lazy: carousel slides 2..n are off-screen by definition, and later
     * sections are below the fold.
     *
     * @return array{loading: string, fetchpriority: string|null, decoding: string}
     */
    public function getImageLoading(int $index): array
    {
        $isLcp = $this->isAboveFold() && $index === 0;

        return [
            'loading' => $isLcp ? 'eager' : 'lazy',
            'fetchpriority' => $isLcp ? 'high' : null,
            // sync on the LCP image: async lets the browser defer the decode
            // past first paint, which is the one place that costs LCP.
            'decoding' => $isLcp ? 'sync' : 'async',
        ];
    }
}
