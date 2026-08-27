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
                'label' => __('Banner — one image, or several as a carousel'),
            ],
            [
                'value' => SectionType::CATEGORY_TILES,
                'label' => __('Category tiles — chosen categories with the reveal visual'),
            ],
            [
                'value' => SectionType::PRODUCT_CAROUSEL,
                'label' => __('Product carousel — products from a category'),
            ],
            [
                'value' => SectionType::PRODUCT_VIDEO_CAROUSEL,
                'label' => __('Product showcase — products from a category, with video'),
            ],
        ];
    }
}
