<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Spartrak\Homepage\Model\SectionType;

/**
 * The section-type dropdown.
 *
 * Labels describe what the admin GETS, not what the code calls it — an admin
 * choosing between "banner" and "product_carousel" is being asked to read our
 * type names, which is a leak, not a UI.
 *
 * Each one is a SHORT NAME, then the reason to use it. The name is what the
 * merchandiser will say out loud and search the list for; the clause after it
 * is what tells them why this row is worth filling in rather than leaving
 * empty. The previous labels led with the mechanism ("Product carousel with
 * promo panel — rail beside a promotional block"), which reads as a
 * description of our code and gives nobody a reason to reach for it.
 */
class SectionTypeOptions implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => SectionType::BANNER,
                'label' => __('Hero Banner — the first thing every shopper sees'),
            ],
            [
                'value' => SectionType::CATEGORY_TILES,
                'label' => __('Category Spotlight — send shoppers straight into a category'),
            ],
            [
                'value' => SectionType::PRODUCT_CAROUSEL,
                'label' => __('Product Rail — a scrollable row of products from one category'),
            ],
            [
                'value' => SectionType::PRODUCT_VIDEO_CAROUSEL,
                'label' => __('Video Showcase — products shown in action, with video'),
            ],
            [
                'value' => SectionType::PRODUCT_PROMO_CAROUSEL,
                'label' => __('Promo + Products — a campaign block beside a product rail'),
            ],
            [
                'value' => SectionType::BRAND_CAROUSEL,
                'label' => __('Brand Strip — every brand you stock, in one row'),
            ],
            [
                'value' => SectionType::CASCADE_SEARCH,
                'label' => __('Part Finder — brand and model drill-down to the exact part'),
            ],
        ];
    }
}
