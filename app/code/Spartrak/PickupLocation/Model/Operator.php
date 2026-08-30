<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model;

use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Model\AbstractModel;
use Spartrak\PickupLocation\Model\ResourceModel\Operator as OperatorResource;

/**
 * A transport operator - the chip above the depot list.
 *
 * Not a location, so it does not extend AbstractLocation: it has no address and
 * cannot be collected from. It is a reference entity that depots point at.
 */
class Operator extends AbstractModel implements IdentityInterface
{
    public const CACHE_TAG = 'spartrak_pickup_operator';

    protected $_cacheTag = self::CACHE_TAG;

    protected $_eventPrefix = 'spartrak_pickup_operator';

    protected function _construct(): void
    {
        $this->_init(OperatorResource::class);
    }

    /**
     * Invalidates the DEPOT tag as well as its own.
     *
     * Renaming an operator changes every depot row that renders its chip, so a
     * page cached against the depot list has to be dropped too. Broad on
     * purpose: operators change rarely, and a stale chip is a wrong fact on a
     * checkout screen.
     *
     * @return string[]
     */
    public function getIdentities(): array
    {
        return [
            self::CACHE_TAG . '_' . $this->getId(),
            Depot::CACHE_TAG,
        ];
    }

    public function isActive(): bool
    {
        return (bool) $this->getData('is_active');
    }

    /**
     * @return array<string, mixed>
     */
    public function getNameColumns(): array
    {
        return [
            'name_ar' => $this->getData('name_ar'),
            'name_en' => $this->getData('name_en'),
        ];
    }
}
