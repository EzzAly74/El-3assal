<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\InstaPay\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Transfer extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('spartrak_instapay_transfer', 'transfer_id');
    }
}
