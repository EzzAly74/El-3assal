<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model;

use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Model\AbstractModel;

/**
 * What a branch and a depot genuinely have in common.
 *
 * They are separate tables and separate entities (see db_schema.xml for why),
 * but both are "a named, addressed place in a governorate that can be turned
 * off and ordered". That shared shape lives here so the two concrete classes
 * carry only what actually differs - a phone number on one, an operator on the
 * other.
 *
 * NO LOCALE RESOLUTION HAPPENS HERE. A persisted entity has no business
 * knowing which store view is rendering it; getName()/getAddress() return the
 * raw column pair and Spartrak\Locale\Model\StoreLanguage picks between them
 * at the read edge. The same rule Spartrak_Homepage follows with its banners.
 */
abstract class AbstractLocation extends AbstractModel implements IdentityInterface
{
    /**
     * The cache tag every concrete location type publishes under.
     *
     * Declared abstract rather than defaulted so a new location type cannot
     * silently share another type's tag and invalidate the wrong pages.
     */
    abstract public function getCacheTagName(): string;

    /**
     * @return string[]
     */
    public function getIdentities(): array
    {
        return [$this->getCacheTagName() . '_' . $this->getId()];
    }

    public function isActive(): bool
    {
        return (bool) $this->getData('is_active');
    }

    public function getSortOrder(): int
    {
        return (int) $this->getData('sort_order');
    }

    public function getRegionId(): ?int
    {
        $value = (int) $this->getData('region_id');

        return $value > 0 ? $value : null;
    }

    /**
     * The raw per-locale name pair, for a StoreLanguage::pick() call.
     *
     * @return array<string, mixed>
     */
    public function getNameColumns(): array
    {
        return [
            'name_ar' => $this->getData('name_ar'),
            'name_en' => $this->getData('name_en'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAddressColumns(): array
    {
        return [
            'address_ar' => $this->getData('address_ar'),
            'address_en' => $this->getData('address_en'),
        ];
    }
}
