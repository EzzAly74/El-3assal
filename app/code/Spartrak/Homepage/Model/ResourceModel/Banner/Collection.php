<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model\ResourceModel\Banner;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Spartrak\Homepage\Model\Banner as BannerModel;
use Spartrak\Homepage\Model\ResourceModel\Banner as BannerResource;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'banner_id';

    protected function _construct(): void
    {
        $this->_init(BannerModel::class, BannerResource::class);
    }

    /**
     * Enabled banners for the given sections, in dashboard order.
     *
     * Takes an ARRAY of section ids on purpose: the homepage loads every
     * banner for every banner section in ONE query rather than one query per
     * section (CLAUDE.md section 4 — "Avoid N+1 queries"). The caller groups
     * the flat result by section_id.
     *
     * @param int[] $sectionIds
     */
    public function addActiveForSections(array $sectionIds): self
    {
        $this->addFieldToFilter('section_id', ['in' => $sectionIds]);
        $this->addFieldToFilter('is_active', 1);
        $this->setOrder('sort_order', self::SORT_ORDER_ASC);
        $this->setOrder('banner_id', self::SORT_ORDER_ASC);

        return $this;
    }
}
