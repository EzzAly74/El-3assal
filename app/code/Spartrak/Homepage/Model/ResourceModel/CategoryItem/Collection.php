<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model\ResourceModel\CategoryItem;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Spartrak\Homepage\Model\CategoryItem as CategoryItemModel;
use Spartrak\Homepage\Model\ResourceModel\CategoryItem as CategoryItemResource;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'item_id';

    protected function _construct(): void
    {
        $this->_init(CategoryItemModel::class, CategoryItemResource::class);
    }

    /**
     * Enabled category picks for the given sections, in dashboard order.
     *
     * Batched across sections for the same reason the banner collection is —
     * see Banner\Collection::addActiveForSections().
     *
     * @param int[] $sectionIds
     */
    public function addActiveForSections(array $sectionIds): self
    {
        $this->addFieldToFilter('section_id', ['in' => $sectionIds]);
        $this->addFieldToFilter('is_active', 1);
        $this->setOrder('sort_order', self::SORT_ORDER_ASC);
        $this->setOrder('item_id', self::SORT_ORDER_ASC);

        return $this;
    }
}
