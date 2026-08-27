<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model;

use Magento\Framework\Model\AbstractModel;
use Spartrak\Homepage\Model\ResourceModel\CategoryItem as CategoryItemResource;

/**
 * One category pick inside a "category tiles" section.
 *
 * Carries no image column BY DESIGN. The brief is explicit that the tile
 * artwork and the large reveal visual are STATIC FRONTEND ASSETS and must
 * not be configurable from the dashboard — so the dashboard owns only which
 * category appears and in what order, and the theme owns how it looks. See
 * ViewModel\CategoryVisuals for the asset-resolution side of that contract.
 */
class CategoryItem extends AbstractModel
{
    protected $_eventPrefix = 'spartrak_homepage_category_item';

    protected function _construct(): void
    {
        $this->_init(CategoryItemResource::class);
    }

    public function getSectionId(): ?int
    {
        $value = (int) $this->getData('section_id');

        return $value > 0 ? $value : null;
    }

    public function getCategoryId(): int
    {
        return (int) $this->getData('category_id');
    }
}
