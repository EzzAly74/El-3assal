<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model\ResourceModel\Operator;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Spartrak\PickupLocation\Model\Operator as OperatorModel;
use Spartrak\PickupLocation\Model\ResourceModel\Operator as OperatorResource;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'operator_id';

    protected function _construct(): void
    {
        $this->_init(OperatorModel::class, OperatorResource::class);
    }

    public function addActiveInSortOrder(): self
    {
        $this->addFieldToFilter('is_active', 1);
        $this->setOrder('sort_order', self::SORT_ORDER_ASC);
        $this->setOrder('operator_id', self::SORT_ORDER_ASC);

        return $this;
    }
}
