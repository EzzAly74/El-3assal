<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Spartrak\PickupLocation\Model\Operator;
use Spartrak\PickupLocation\Model\ResourceModel\Operator\CollectionFactory;

/**
 * The operator dropdown on the depot form, and the operator filter on the
 * depot grid.
 *
 * ===========================================================================
 * IT LISTS DISABLED OPERATORS TOO, ON PURPOSE
 * ===========================================================================
 * The storefront hides a disabled operator's chip; the ADMIN still has to be
 * able to see and edit a depot that points at one. If this filtered to active
 * rows, opening such a depot would show an empty operator field, and the first
 * save would silently detach it - destroying data by rendering a form.
 *
 * Disabled entries are marked in the label instead, so the state is visible
 * without being destructive.
 *
 * Not cached across requests: an admin who has just created an operator must
 * find it in this list on the very next page load.
 */
class OperatorOptions implements OptionSourceInterface
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $options = null;

    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }

        // operator_id is nullable - a depot need not belong to an operator.
        $this->options = [['value' => '', 'label' => __('-- Please Select --')]];

        $collection = $this->collectionFactory->create();
        $collection->setOrder('sort_order', $collection::SORT_ORDER_ASC);
        $collection->setOrder('operator_id', $collection::SORT_ORDER_ASC);

        /** @var Operator $operator */
        foreach ($collection as $operator) {
            // The Arabic name is the required one (see the Save controller), so
            // it is the reliable label for an admin list.
            $label = (string) ($operator->getData('name_ar') ?: $operator->getData('name_en'));

            $this->options[] = [
                'value' => (int) $operator->getId(),
                'label' => $operator->isActive() ? $label : __('%1 (disabled)', $label),
            ];
        }

        return $this->options;
    }
}
