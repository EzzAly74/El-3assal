<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Block\Section;

use Magento\Framework\View\Element\Template\Context;
use Spartrak\Homepage\Model\LocaleContext;
use Spartrak\Homepage\ViewModel\CategoryTiles as CategoryTilesViewModel;

/**
 * The "الفئات الأكثر بحثا" section (Figma 595:15067).
 *
 * Thin by design: the data work — batching the EAV load, preserving dashboard
 * order, resolving each category's static theme artwork — belongs to
 * ViewModel\CategoryTiles, which is unit-testable without a layout. This
 * block only adapts it to the template.
 */
class CategoryTiles extends AbstractSection
{
    public function __construct(
        Context $context,
        LocaleContext $localeContext,
        private readonly CategoryTilesViewModel $categoryTiles,
        array $data = []
    ) {
        parent::__construct($context, $localeContext, $data);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTiles(): array
    {
        $section = $this->getSection();

        return $section === null ? [] : $this->categoryTiles->getTiles($section);
    }

    public function hasContent(): bool
    {
        return $this->getTiles() !== [];
    }

    /**
     * The tile whose large visual is showing when the page first paints.
     *
     * Always the first tile. Fixing it server-side rather than letting JS
     * pick one after hydration is what keeps this section off the CLS ledger:
     * the correct visual is in the initial HTML, at its final size, and the
     * reveal animation only ever CROSS-FADES between visuals that are already
     * laid out.
     *
     * @return array<string, mixed>|null
     */
    public function getInitialTile(): ?array
    {
        return $this->getTiles()[0] ?? null;
    }

    /**
     * Whether any tile in this section actually has a large visual shipped.
     *
     * When none do, the template drops the whole reveal stage — including its
     * JS — instead of animating between empty boxes.
     */
    public function hasVisuals(): bool
    {
        foreach ($this->getTiles() as $tile) {
            if (!empty($tile['visual_image'])) {
                return true;
            }
        }

        return false;
    }
}
