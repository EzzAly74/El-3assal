<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Branch extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('spartrak_pickup_branch', 'branch_id');
    }
}
