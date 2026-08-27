<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model;

use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Model\AbstractModel;
use Spartrak\Homepage\Model\ResourceModel\Section as SectionResource;

/**
 * One homepage section.
 *
 * IdentityInterface is what wires this entity into Magento's own full-page
 * cache invalidation: the homepage block returns the identities of every
 * section it rendered, so saving a section in the dashboard drops exactly the
 * cached pages that showed it — no manual cache flush, no lifetime guessing.
 *
 * @method string|null getCode()
 * @method string|null getType()
 * @method string|null getTitleEn()
 * @method string|null getTitleAr()
 * @method string|null getLinkUrl()
 */
class Section extends AbstractModel implements IdentityInterface
{
    public const CACHE_TAG = 'spartrak_homepage_section';

    protected $_cacheTag = self::CACHE_TAG;

    protected $_eventPrefix = 'spartrak_homepage_section';

    protected function _construct(): void
    {
        $this->_init(SectionResource::class);
    }

    /**
     * @return string[]
     */
    public function getIdentities(): array
    {
        return [self::CACHE_TAG . '_' . $this->getId()];
    }

    public function getSectionId(): ?int
    {
        $value = $this->getData('section_id');

        return $value === null ? null : (int) $value;
    }

    public function isActive(): bool
    {
        return (bool) $this->getData('is_active');
    }

    public function getSortOrder(): int
    {
        return (int) $this->getData('sort_order');
    }

    /**
     * The source category for a product section, or null when unset.
     *
     * Returns null rather than 0 for "not configured" so callers can tell an
     * unconfigured section from category 0 — which does not exist, but would
     * otherwise silently run a real query that returns nothing.
     */
    public function getCategoryId(): ?int
    {
        $value = (int) $this->getData('category_id');

        return $value > 0 ? $value : null;
    }

    public function getProductLimit(): int
    {
        $limit = (int) $this->getData('product_limit');

        // Guard rail, not a business rule: a section saved with 0 (or a value
        // someone edited straight into the DB) must never turn into an
        // unbounded catalogue query on the homepage.
        return $limit > 0 ? min($limit, 50) : 10;
    }
}
