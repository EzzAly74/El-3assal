<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model\ResourceModel\Section;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Spartrak\Homepage\Model\ResourceModel\Section as SectionResource;
use Spartrak\Homepage\Model\Section as SectionModel;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'section_id';

    protected function _construct(): void
    {
        $this->_init(SectionModel::class, SectionResource::class);
    }

    /**
     * The storefront's only read: enabled sections, in dashboard order.
     *
     * Ties break on section_id so the order is deterministic — two sections
     * left on the default sort_order of 0 must not reshuffle between
     * requests, or the full-page cache would store one order and a
     * regenerated page another.
     */
    public function addActiveFilter(): self
    {
        $this->addFieldToFilter('is_active', 1);
        $this->setOrder('sort_order', self::SORT_ORDER_ASC);
        $this->setOrder('section_id', self::SORT_ORDER_ASC);

        return $this;
    }
}
