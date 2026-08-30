<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model;

use Spartrak\PickupLocation\Model\ResourceModel\Branch as BranchResource;

/**
 * A company branch a shopper can collect an order from.
 *
 * Carries a phone number, which a depot does not: a branch is staffed by the
 * company, so "call the branch" is a real instruction. A depot is a third
 * party's coach station and the shopper deals with the operator, not with us.
 */
class Branch extends AbstractLocation
{
    public const CACHE_TAG = 'spartrak_pickup_branch';

    protected $_cacheTag = self::CACHE_TAG;

    protected $_eventPrefix = 'spartrak_pickup_branch';

    protected function _construct(): void
    {
        $this->_init(BranchResource::class);
    }

    public function getCacheTagName(): string
    {
        return self::CACHE_TAG;
    }

    public function getPhone(): ?string
    {
        $value = trim((string) $this->getData('phone'));

        return $value !== '' ? $value : null;
    }
}
