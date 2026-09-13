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

    /**
     * One enabled section, by its dashboard `code`.
     *
     * `code` carries a UNIQUE constraint (see db_schema.xml), so this is a
     * single-row lookup by design rather than a filter that happens to match
     * one row today.
     *
     * The `is_active` half is deliberately part of the filter and not left to
     * the caller: a page that mounts a section by code is asking for something
     * to RENDER, and a disabled row must resolve to "nothing" at the same
     * place for every caller — otherwise disabling a section in the dashboard
     * would silently keep working on whichever page forgot the check.
     */
    public function addCodeFilter(string $code): self
    {
        $this->addFieldToFilter('code', $code);
        $this->addFieldToFilter('is_active', 1);
        $this->setPageSize(1);

        return $this;
    }
}
