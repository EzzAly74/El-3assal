<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Spartrak\Homepage\Model\ResourceModel\Section\CollectionFactory;
use Spartrak\Homepage\Model\Section;
use Spartrak\Homepage\Model\SectionType;

/**
 * "Which section does this banner belong to?"
 *
 * Lists ONLY banner-type sections. Offering a product carousel here would let
 * an admin attach artwork to a section that can never render it — a silent
 * dead end they would have no way to diagnose from the dashboard.
 */
class SectionOptions implements OptionSourceInterface
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @return array<int, array{value: int|string, label: string|\Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('type', SectionType::BANNER);
        $collection->setOrder('sort_order', 'ASC');

        $options = [['value' => '', 'label' => __('-- Please select --')]];

        /** @var Section $section */
        foreach ($collection as $section) {
            // The identifier is shown alongside the title because the title is
            // free text and two banner sections may legitimately share one.
            $title = trim((string) ($section->getTitleEn() ?: $section->getTitleAr()));
            $label = $title !== ''
                ? $title . ' (' . $section->getCode() . ')'
                : (string) $section->getCode();

            $options[] = ['value' => (int) $section->getId(), 'label' => $label];
        }

        return $options;
    }
}
