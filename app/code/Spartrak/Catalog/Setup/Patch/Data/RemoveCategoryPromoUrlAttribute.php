<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Catalog\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Drops `spartrak_promo_url`.
 *
 * ===========================================================================
 * WHY A SECOND PATCH RATHER THAN AN EDIT TO THE FIRST
 * ===========================================================================
 * AddCategoryPromoAttributes briefly created a link attribute so the promo
 * band could be wrapped in an anchor. That was removed from it, which is
 * enough for a FRESH install — but a data patch runs once and is then recorded
 * in patch_list, so editing it does nothing on an install that already ran it.
 * The attribute would sit in eav_attribute forever, invisible (the admin form
 * no longer declares a field for it) and unreadable by any code.
 *
 * So the removal is its own patch. That is the only mechanism Magento offers
 * for undoing applied setup, and it makes the change explicit in version
 * control rather than something an operator has to remember to do by hand.
 *
 * ===========================================================================
 * WHY THE FIELD WENT
 * ===========================================================================
 * The band's call to action is the "البحث السريع" button on top of it, which
 * opens the quick-search dialog rather than navigating. A link on the wrapper
 * would have been a second, competing destination AND would have nested a
 * button inside an anchor — invalid markup, and unusable with a keyboard.
 *
 * removeAttribute() is a no-op when the attribute is already absent, so this
 * is safe on a fresh install where the earlier revision never ran.
 */
class RemoveCategoryPromoUrlAttribute implements DataPatchInterface
{
    /**
     * Written as a literal, NOT as a constant on AddCategoryPromoAttributes.
     * That constant is gone, and a removal patch has to keep naming the thing
     * it removes long after every other reference to it has been deleted.
     */
    private const ATTR_URL = 'spartrak_promo_url';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $eavSetup->removeAttribute(Category::ENTITY, self::ATTR_URL);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        // Must not run before the patch that created it, or on a fresh install
        // the removal would be recorded first and the (now absent) creation
        // would leave nothing to clean up in a later release.
        return [AddCategoryPromoAttributes::class];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
