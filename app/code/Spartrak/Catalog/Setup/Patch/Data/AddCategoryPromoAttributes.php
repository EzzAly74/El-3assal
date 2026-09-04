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
 * The category page's SECOND banner — the promo band that renders directly
 * below the "تسوق بالمنتجات" tile rail.
 *
 * ===========================================================================
 * THIS BAND IS NOT IN FIGMA. IT IS A BUSINESS REQUIREMENT.
 * ===========================================================================
 * The category frame (488:10810) goes breadcrumbs -> hero (488:12966) ->
 * Categories Section (507:5234) -> Explore (488:12195), with nothing between
 * the rail and the product area. So there is no frame to be pixel-perfect
 * against here and none is invented: the band reuses the hero's own
 * construction — full bleed, admin artwork, one asset per viewport, box
 * reserved before it paints — and adds no new visual language.
 *
 * Recorded rather than left to look designed, per CLAUDE.md section 3.
 *
 * ===========================================================================
 * WHY A SECOND SET OF ATTRIBUTES AND NOT A REUSE OF THE HERO'S
 * ===========================================================================
 * Same rule as the hero (section 6: every banner dashboard-managed, desktop
 * and mobile always separate), different CONTENT MODEL:
 *
 *   hero   artwork + heading + the category description, no link. It is the
 *          page's masthead, so it carries the <h1> and is not clickable.
 *   promo  artwork ONLY, plus a destination and alt text. It is an
 *          advertisement, so it links somewhere and has no DOM text at all.
 *
 * That last difference is why `spartrak_promo_alt` exists and the hero has no
 * equivalent: the hero's heading and blurb are real text a screen reader
 * already reads, which makes its artwork decorative (alt=""). This band has
 * no text, so its meaning lives entirely in the picture and section 15
 * requires a real alt — section 6 says an admin-managed banner must expose an
 * alt-text field, and this is the banner that actually needs one.
 *
 * NO SORT ORDER and NO SCHEDULING, for the same reason as the hero: this is
 * one fixed slot on one category, not a rotating campaign list. Section 6 asks
 * for both "where applicable"; there is nothing here to order or rotate to.
 * Campaign scheduling belongs to Spartrak_Homepage's banner section, which has
 * a list to rotate through and already carries it.
 */
class AddCategoryPromoAttributes implements DataPatchInterface
{
    /** Desktop asset. */
    public const ATTR_IMAGE = 'spartrak_promo_image';

    /** Mobile asset. Never derived from the desktop one - section 6. */
    public const ATTR_IMAGE_MOBILE = 'spartrak_promo_image_mobile';

    /*
     * THERE IS DELIBERATELY NO LINK ATTRIBUTE.
     *
     * An earlier revision of this patch created `spartrak_promo_url` so the
     * whole band could be an anchor. It is gone: the band's call to action is
     * the "البحث السريع" button sitting on top of it, which opens the quick-
     * search dialog rather than navigating anywhere. A wrapping link would
     * have made the entire banner a second, competing destination and nested
     * an interactive control inside an anchor, which is invalid markup and
     * unusable with a keyboard.
     *
     * RemoveCategoryPromoUrlAttribute drops the column on installs that
     * already ran the earlier revision.
     */

    /**
     * Alt text. REQUIRED for meaning, not optional decoration — see the class
     * note. Empty falls back to nothing rather than to the category name,
     * because a wrong alt is worse than an empty one and the template then
     * treats the band as decorative.
     */
    public const ATTR_ALT = 'spartrak_promo_alt';

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
            // Per store view, so Arabic and English get their own artwork,
            // their own destination and their own alt text with no second
            // table and no locale column anywhere in this module.
            'global' => ScopedAttributeInterface::SCOPE_STORE,
        ];

        $eavSetup->addAttribute(Category::ENTITY, self::ATTR_IMAGE, $shared + [
            'label' => 'Promo Banner - Desktop Image',
            'input' => 'image',
            'backend' => ImageBackend::class,
            'sort_order' => 330,
            'note' => 'Full-width band shown below the category tile rail.',
        ]);

        $eavSetup->addAttribute(Category::ENTITY, self::ATTR_IMAGE_MOBILE, $shared + [
            'label' => 'Promo Banner - Mobile Image',
            'input' => 'image',
            'backend' => ImageBackend::class,
            'sort_order' => 340,
            'note' => 'Served instead of the desktop image below 768px. Upload a real mobile crop - '
                . 'the desktop file is never resized to stand in for it.',
        ]);

        $eavSetup->addAttribute(Category::ENTITY, self::ATTR_ALT, $shared + [
            'label' => 'Promo Banner - Alt Text',
            'input' => 'text',
            'sort_order' => 360,
            'note' => 'What the banner says, for screen readers and for when the image fails to load. '
                . 'Leave empty only if the banner is purely decorative.',
        ]);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        // The hero attributes claim sort orders 290-310 in the same group;
        // this patch takes 330-360. Declaring the dependency keeps that
        // ordering deterministic on a fresh install rather than dependent on
        // which patch the scanner happens to reach first.
        return [AddCategoryHeroAttributes::class];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
