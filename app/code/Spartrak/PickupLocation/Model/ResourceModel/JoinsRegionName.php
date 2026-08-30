<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model\ResourceModel;

/**
 * Pulls the governorate name in alongside a location row.
 *
 * A trait rather than a shared base collection: branch and depot already
 * extend Magento's AbstractCollection and each binds its own model and
 * resource, so a common parent would exist only to hold this one method. A
 * trait says "these two share an implementation" without inventing a type
 * relationship that nothing else needs.
 *
 * ONE query rather than a lookup per row. Both storefront lists print a
 * governorate on every row, so joining here is the difference between one
 * statement and forty.
 */
trait JoinsRegionName
{
    /**
     * LEFT JOIN, not INNER: region_id is nullable by schema, and a location
     * with no governorate recorded must still appear in the list.
     *
     * directory_country_region_name is keyed by locale, so the caller states
     * which one it wants.
     */
    public function joinRegionName(string $locale): self
    {
        $connection = $this->getConnection();

        $this->getSelect()->joinLeft(
            ['spartrak_region_name' => $this->getTable('directory_country_region_name')],
            $connection->quoteInto(
                'spartrak_region_name.region_id = main_table.region_id AND spartrak_region_name.locale = ?',
                $locale
            ),
            ['region_name' => 'name']
        );

        return $this;
    }
}
