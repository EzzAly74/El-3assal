<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model;

use Spartrak\PickupLocation\Model\ResourceModel\Depot as DepotResource;

/**
 * A third-party coach depot a shopper can collect an order from.
 *
 * The operator is nullable by schema (retiring an operator must not delete the
 * physical depot - see db_schema.xml), so every reader has to cope with a
 * depot that currently wears no chip.
 */
class Depot extends AbstractLocation
{
    public const CACHE_TAG = 'spartrak_pickup_depot';

    protected $_cacheTag = self::CACHE_TAG;

    protected $_eventPrefix = 'spartrak_pickup_depot';

    protected function _construct(): void
    {
        $this->_init(DepotResource::class);
    }

    public function getCacheTagName(): string
    {
        return self::CACHE_TAG;
    }

    public function getOperatorId(): ?int
    {
        $value = (int) $this->getData('operator_id');

        return $value > 0 ? $value : null;
    }
}
