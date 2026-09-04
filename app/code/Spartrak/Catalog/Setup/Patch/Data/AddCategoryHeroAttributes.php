<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Catalog\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Category\Attribute\Backend\Image as ImageBackend;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * The category HERO BANNER's admin fields (Figma 488:12966).
 *
 * ===========================================================================
 * WHY CATEGORY ATTRIBUTES AND NOT A NEW TABLE
 * ===========================================================================
 * CLAUDE.md section 6 requires every banner to be dashboard-managed with a
 * SEPARATE desktop and mobile asset, per store view. A category hero is
 * one-per-category by definition, so its natural home is the category itself
 * — not a registry table keyed back to a category, which would model a
 * cardinality that does not exist and would give admins a second place to
 * look for something that belongs on the category edit screen.
 *
 * Spartrak_Homepage's spartrak_homepage_banner table is deliberately NOT
 * reused here: that table models an ORDERED LIST of slides belonging to a
 * homepage section. This is a single fixed slide belonging to a category.
 * Same rule (section 6), different cardinality, different owner.
 *
 * Everything section 6 asks for is already provided by the EAV layer at
 * SCOPE_STORE, for free and with no code of ours in the path:
 *   desktop asset   spartrak_hero_image
 *   mobile asset    spartrak_hero_image_mobile
 *   alt text        the heading below / the category name (see Block\Category\Hero)
 *   status          the category's own is_active
 *   store view      SCOPE_STORE on all three attributes
 *   sort order      not applicable - one hero per category, no list to order
 *   scheduling      not applicable - see the note at the foot of this class
 *
 * ===========================================================================
 * WHY Backend\Image AND NOT A CUSTOM UPLOADER
 * ===========================================================================
 * Declaring the two image attributes with Magento's OWN category-image
 * backend model buys the entire admin round trip with no code:
 *   - Controller\Adminhtml\Category\Save::imagePreprocessing() iterates every
 *     category attribute whose backend is this class, so a cleared field is
 *     persisted as cleared;
 *   - Model\Category\DataProvider::convertValues() likewise tests for this
 *     backend, so the saved filename is handed to the form as the
 *     {name, url, size} shape the image uploader expects;
 *   - the backend model's own beforeSave/afterSave moves the file out of
 *     catalog/tmp/category and records just the filename.
 * The matching form fields are declared in
 * view/adminhtml/ui_component/category_form.xml and post to Magento's own
 * catalog/category_image/upload controller.
 *
 * NO SCHEDULING FIELD, DELIBERATELY. Section 6 asks for scheduling "where
 * applicable". A hero is the category's own masthead rather than a campaign
 * slot: there is no second hero to rotate to when a window closes, so a
 * from/to date could only ever blank the top of the page. Campaign scheduling
 * belongs to the homepage banner section, which has a list to rotate through
 * and already carries it.
 */
class AddCategoryHeroAttributes implements DataPatchInterface
{
    /** Desktop asset. Figma 488:12966 draws it 1440x318, full bleed. */
    public const ATTR_IMAGE = 'spartrak_hero_image';

    /** Mobile asset. Never derived from the desktop one - CLAUDE.md section 6. */
    public const ATTR_IMAGE_MOBILE = 'spartrak_hero_image_mobile';

    /**
     * Optional headline (Figma 488:12970, "منتجات جون ديير").
     *
     * OPTIONAL because the category already has a name, and repeating it in
     * a second field would be a data-duplication trap. Block\Category\Hero
     * falls back to the category name when this is empty, so the hero is
     * correct on a category nobody has touched.
     */
    public const ATTR_HEADING = 'spartrak_hero_heading';

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

        $shared = [
            'type' => 'varchar',
            'required' => false,
            'user_defined' => false,
            'visible' => true,
            'group' => 'General Information',
            // SCOPE_STORE is the whole point: an Arabic and an English store
            // view get their own artwork and their own headline without a
            // second table or a locale column anywhere in this module.
            'global' => ScopedAttributeInterface::SCOPE_STORE,
        ];

        $eavSetup->addAttribute(Category::ENTITY, self::ATTR_IMAGE, $shared + [
            'label' => 'Hero Banner - Desktop Image',
            'input' => 'image',
            'backend' => ImageBackend::class,
            'sort_order' => 300,
            'note' => 'Shown at the top of this category page. Figma reference size 1440 x 318.',
        ]);

        $eavSetup->addAttribute(Category::ENTITY, self::ATTR_IMAGE_MOBILE, $shared + [
            'label' => 'Hero Banner - Mobile Image',
            'input' => 'image',
            'backend' => ImageBackend::class,
            'sort_order' => 310,
            'note' => 'Served instead of the desktop image below 768px. Upload a real mobile crop - '
                . 'the desktop file is never resized to stand in for it.',
        ]);

        $eavSetup->addAttribute(Category::ENTITY, self::ATTR_HEADING, $shared + [
            'label' => 'Hero Banner - Heading',
            'input' => 'text',
            'sort_order' => 290,
            'note' => 'Leave empty to use the category name.',
        ]);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
