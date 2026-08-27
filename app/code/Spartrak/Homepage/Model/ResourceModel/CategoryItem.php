<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class CategoryItem extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('spartrak_homepage_category_item', 'item_id');
    }
}
