<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model;

use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Model\AbstractModel;
use Spartrak\Homepage\Model\ResourceModel\Banner as BannerResource;

/**
 * One banner item inside a banner section.
 *
 * Deliberately holds NO presentation logic. Which of its four images the
 * storefront actually serves is decided by ViewModel\BannerResolver, because
 * that decision depends on the current store's locale and on the viewport —
 * neither of which a persisted entity should know about.
 *
 * @method string|null getUrl()
 */
class Banner extends AbstractModel implements IdentityInterface
{
    public const CACHE_TAG = 'spartrak_homepage_banner';

    protected $_cacheTag = self::CACHE_TAG;

    protected $_eventPrefix = 'spartrak_homepage_banner';

    protected function _construct(): void
    {
        $this->_init(BannerResource::class);
    }

    /**
     * Invalidates on BOTH its own tag and its section's.
     *
     * The homepage renders banners through their section, so a page that
     * showed this banner was tagged with the section — editing one banner has
     * to drop that page too, not just anything tagged with this banner id.
     *
     * @return string[]
     */
    public function getIdentities(): array
    {
        $identities = [self::CACHE_TAG . '_' . $this->getId()];

        if ($this->getSectionId() !== null) {
            $identities[] = Section::CACHE_TAG . '_' . $this->getSectionId();
        }

        return $identities;
    }

    public function getSectionId(): ?int
    {
        $value = (int) $this->getData('section_id');

        return $value > 0 ? $value : null;
    }

    public function isActive(): bool
    {
        return (bool) $this->getData('is_active');
    }
}
