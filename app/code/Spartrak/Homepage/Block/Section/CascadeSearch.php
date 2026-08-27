<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Block\Section;

use Magento\Framework\View\Element\Template\Context;
use Spartrak\Homepage\Model\LocaleContext;
use Spartrak\Homepage\ViewModel\CascadeOptions;

/**
 * The "بتدور علي ايه؟" cascading finder (Figma 595:15843).
 *
 * Thin: every option comes from ViewModel\CascadeOptions, which is also what
 * the AJAX endpoint calls — so the level rendered into the page and the levels
 * fetched later are produced by one code path and cannot disagree.
 */
class CascadeSearch extends AbstractSection
{
    public function __construct(
        Context $context,
        LocaleContext $localeContext,
        private readonly CascadeOptions $cascadeOptions,
        array $data = []
    ) {
        parent::__construct($context, $localeContext, $data);
    }

    /**
     * @return array<int, array{label: string, value: string, url: string}>
     */
    public function getBrands(): array
    {
        return $this->cascadeOptions->getBrands();
    }

    /**
     * @return array<int, array{value: int, label: string, url: string}>
     */
    public function getTopCategories(): array
    {
        return $this->cascadeOptions->getTopCategories();
    }

    public function hasContent(): bool
    {
        return $this->cascadeOptions->hasOptions();
    }

    /**
     * The endpoint the widget calls for levels 3 and 4.
     */
    public function getOptionsUrl(): string
    {
        return $this->getUrl('spartrak-homepage/ajax/cascadeOptions');
    }

    /**
     * Fallback destination for a submit that names a brand but no category.
     *
     * Identical to the header's own brand link, so the finder and the header
     * lead to the same place for the same brand.
     */
    public function getBrandUrlMap(): string
    {
        $map = [];

        foreach ($this->getBrands() as $brand) {
            $map[$brand['value']] = $brand['url'];
        }

        return (string) json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * This section's card carries its own heading (Figma 595:15845), which is
     * a different component from the shared section header — so the shared one
     * is suppressed rather than rendered empty above it.
     */
    public function showsCarouselNav(): bool
    {
        return false;
    }
}
