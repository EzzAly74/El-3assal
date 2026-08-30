<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model\Carrier;

/**
 * Collect from a SpareTrak branch.
 *
 * Figma 554:13119 - the shopper picks from a list of map-pin cards, each a
 * branch name and its address. No search, because the branch count is small
 * and bounded by how many shops the company runs.
 */
class BranchPickup extends AbstractPickup
{
    public const CODE = 'spartrak_branch';

    public const METHOD = 'branch';

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
        return $this->locationCatalog->hasBranches();
    }
}
