<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model\ResourceModel\Branch;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Spartrak\PickupLocation\Model\Branch as BranchModel;
use Spartrak\PickupLocation\Model\ResourceModel\JoinsRegionName;
use Spartrak\PickupLocation\Model\ResourceModel\Branch as BranchResource;

class Collection extends AbstractCollection
{
    use JoinsRegionName;

    protected $_idFieldName = 'branch_id';

    protected function _construct(): void
    {
        $this->_init(BranchModel::class, BranchResource::class);
    }

    /**
     * Enabled branches, in dashboard order.
     *
     * branch_id is the tiebreaker so two branches sharing a sort_order do not
     * swap places between requests - an unstable list on a checkout screen
     * looks like a bug to a shopper mid-decision.
     */
    public function addActiveInSortOrder(): self
    {
        $this->addFieldToFilter('is_active', 1);
        $this->setOrder('sort_order', self::SORT_ORDER_ASC);
        $this->setOrder('branch_id', self::SORT_ORDER_ASC);

        return $this;
    }
}
