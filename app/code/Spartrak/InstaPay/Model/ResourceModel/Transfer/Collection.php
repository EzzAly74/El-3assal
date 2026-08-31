<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\InstaPay\Model\ResourceModel\Transfer;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Spartrak\InstaPay\Model\ResourceModel\Transfer as TransferResource;
use Spartrak\InstaPay\Model\Transfer;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(Transfer::class, TransferResource::class);
    }
}
