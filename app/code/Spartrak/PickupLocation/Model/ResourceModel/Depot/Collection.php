<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model\ResourceModel\Depot;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Spartrak\PickupLocation\Model\Depot as DepotModel;
use Spartrak\PickupLocation\Model\ResourceModel\JoinsRegionName;
use Spartrak\PickupLocation\Model\ResourceModel\Depot as DepotResource;

class Collection extends AbstractCollection
{
    use JoinsRegionName;

    protected $_idFieldName = 'depot_id';

    protected function _construct(): void
    {
        $this->_init(DepotModel::class, DepotResource::class);
    }

    /**
     * Enabled depots, in dashboard order. See the Branch collection for why
     * the primary key is the tiebreaker.
     */
    public function addActiveInSortOrder(): self
    {
        $this->addFieldToFilter('is_active', 1);
        $this->setOrder('sort_order', self::SORT_ORDER_ASC);
        $this->setOrder('depot_id', self::SORT_ORDER_ASC);

        return $this;
    }

    /**
     * Restricts to one operator. Used by the storefront chip filter.
     */
    public function addOperatorFilter(int $operatorId): self
    {
        $this->addFieldToFilter('operator_id', $operatorId);

        return $this;
    }
}
