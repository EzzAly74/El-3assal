<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Block\Section;

use Magento\Framework\View\Element\Template\Context;
use Spartrak\Catalog\ViewModel\BrandNavigation;
use Spartrak\Homepage\Model\LocaleContext;

/**
 * "تصفح جميع الماركات" — the brand rail (Figma 695:46254).
 *
 * ===========================================================================
 * IT REUSES THE HEADER'S BRAND SOURCE, ON PURPOSE
 * ===========================================================================
 * The brief asks for tiles that "navigate to brand as in header". The only way
 * to guarantee that stays true is to ask the SAME class the header asks —
 * Spartrak_Catalog's BrandNavigation — rather than rebuilding a brand list
 * here and hoping the two URL schemes stay in step.
 *
 * That gives, for free:
 *   - the same admin-managed vocabulary (the `brand` attribute's options, in
 *     the admin's own option order — nothing hardcoded);
 *   - the same logos (each option's visual swatch image);
 *   - the same destination URL, built by that class's buildFilterUrl();
 *   - the same per-store cache, so this rail adds NO queries to a page the
 *     header has already warmed.
 *
 * A brand added, renamed or reordered in Stores > Attributes therefore changes
 * the header and this rail together, which is the behaviour a merchant expects
 * and the reason this block holds no brand logic of its own.
 */
class BrandCarousel extends AbstractSection
{
    public function __construct(
        Context $context,
        LocaleContext $localeContext,
        private readonly BrandNavigation $brandNavigation,
        array $data = []
    ) {
        parent::__construct($context, $localeContext, $data);
    }

    /**
     * @return array<int, array{label: string, value: string, logo: ?string, url: string}>
     */
    public function getBrands(): array
    {
        return $this->brandNavigation->getBrands();
    }

    public function hasContent(): bool
    {
        return $this->brandNavigation->hasBrands();
    }

    /**
     * Figma puts this rail's arrows OVER the tiles (695:46298), not in the
     * section header the product rails use — so the shared header renders its
     * "view all" link only and the arrows are emitted by this section's own
     * template.
     */
    public function showsCarouselNav(): bool
    {
        return false;
    }

    public function isCarousel(): bool
    {
        return count($this->getBrands()) > 1;
    }

    /**
     * The brand's first character, for the plate shown when an option has no
     * swatch image uploaded yet.
     *
     * An honest empty state — never a broken <img>, and never a brand silently
     * dropped from the rail because its logo is missing.
     */
    public function getInitial(string $label): string
    {
        $label = trim($label);

        if ($label === '') {
            return '';
        }

        // mb-aware: an Arabic brand name's first character is multi-byte, and
        // substr() would emit half a codepoint.
        return mb_substr($label, 0, 1);
    }
}
