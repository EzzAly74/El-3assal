<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Catalog\Block\Category;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Spartrak\Homepage\ViewModel\CascadeOptions;

/**
 * Data source for the "البحث السريع" quick-search dialog on the category page.
 *
 * ===========================================================================
 * WHY THIS EXISTS INSTEAD OF MOUNTING Spartrak_Homepage's CascadeSearch BLOCK
 * ===========================================================================
 * The dialog first mounted that block directly, with no section — its four
 * data methods all read CascadeOptions and none of them touch the section, so
 * on paper it worked off the homepage unchanged.
 *
 * It rendered NOTHING, silently. AbstractSection::_toHtml() opens with:
 *
 *     if ($this->getSection() === null || !$this->hasContent()) {
 *         return '';
 *     }
 *
 * — a section block with no dashboard row behind it is, correctly, not a
 * section. And because promo.phtml only draws the button when the dialog
 * produced markup, an empty string took the button with it: banner, no button,
 * no error anywhere.
 *
 * Faking a Section row to satisfy that guard would have been the wrong fix. It
 * is guarding something real — the homepage's sections ARE dashboard rows, and
 * this dialog is not one.
 *
 * ===========================================================================
 * WHAT IS STILL SHARED, WHICH IS EVERYTHING THAT MATTERS
 * ===========================================================================
 * The block is new; the FINDER is not. Both this and the homepage section read
 * the very same Spartrak\Homepage\ViewModel\CascadeOptions — which is also
 * what the AJAX endpoint calls, so the levels rendered into the page and the
 * levels fetched later come from one code path and cannot disagree. The
 * dialog's behaviour is the same spartrakCascadeSearch widget over the same
 * data-finder-* contract, and nothing in Spartrak_Homepage was edited.
 *
 * A ViewModel is exactly the right seam to share across modules: it has no
 * page, no section and no layout of its own. What is NOT shared is the section
 * machinery, which was never this dialog's to use.
 *
 * NOTE ON module.xml: Spartrak_Homepage is deliberately NOT in this module's
 * sequence. It already sequences AFTER Spartrak_Catalog (its product sections
 * render through Spartrak_Catalog::product/card.phtml), so declaring the
 * reverse produces "Circular sequence reference" and blocks setup:upgrade
 * outright. A constructor type-hint is resolved by the autoloader at runtime
 * and never consults the sequence graph, so no declaration is needed.
 */
class QuickSearch extends Template
{
    public function __construct(
        Context $context,
        private readonly CascadeOptions $cascadeOptions,
        array $data = []
    ) {
        parent::__construct($context, $data);
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

    /**
     * False when the finder has nothing to offer.
     *
     * The template returns early on this, and promo.phtml then suppresses the
     * button too — a control that opens an empty dialog is worse than no
     * control.
     */
    public function hasContent(): bool
    {
        return $this->cascadeOptions->hasOptions();
    }

    /**
     * The endpoint the widget calls for levels 2 and 3.
     *
     * The same route the homepage section returns, because it is the same
     * controller serving the same widget. Kept as a literal here rather than
     * reaching into that block for it: this is one route string, and importing
     * a block to read it would recreate exactly the coupling this class exists
     * to avoid.
     */
    public function getOptionsUrl(): string
    {
        return $this->getUrl('spartrak-homepage/ajax/cascadeOptions');
    }

    /**
     * Fallback destination for a submit that names a brand but no category, as
     * a JSON map the widget reads.
     */
    public function getBrandUrlMap(): string
    {
        $map = [];

        foreach ($this->getBrands() as $brand) {
            $map[$brand['value']] = $brand['url'];
        }

        return (string) json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
