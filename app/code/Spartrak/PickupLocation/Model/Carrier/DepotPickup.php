<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model\Carrier;

/**
 * Collect from a coach depot.
 *
 * Figma 554:13750 - a searchable list with operator filter chips, because the
 * depot network is third-party, national, and far too long to scan. The search
 * and the chips are storefront behaviour; this class only decides whether the
 * option exists and what it costs.
 */
class DepotPickup extends AbstractPickup
{
    public const CODE = 'spartrak_depot';

    public const METHOD = 'depot';

    /**
     * @var string
     */
    protected $_code = self::CODE;

    public function getMethodCode(): string
    {
        return self::METHOD;
    }

    protected function hasLocations(): bool
    {
        return $this->locationCatalog->hasDepots();
    }
}
