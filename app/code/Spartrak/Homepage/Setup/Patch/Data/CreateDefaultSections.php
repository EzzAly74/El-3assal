<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Spartrak\Homepage\Model\SectionFactory;
use Spartrak\Homepage\Model\SectionRepository;
use Spartrak\Homepage\Model\SectionType;

/**
 * Creates the five homepage sections the Figma design defines.
 *
 * ===========================================================================
 * WHAT THIS DOES AND DOES NOT SEED
 * ===========================================================================
 * It creates the SHELL of each section — its type, its position on the page,
 * and its Arabic and English headings, all taken from the design. That is
 * structure, and structure is what a data patch is for.
 *
 * It deliberately does NOT set a category on any product section and does NOT
 * create any banner. Those are CONTENT, they differ per install, and there is
 * no honest value to write:
 *
 *   - a category id is environment-specific; hardcoding one here would be
 *     exactly the "do not hardcode categories/IDs" the brief rules out, and
 *     would silently point a live homepage at whatever category happened to
 *     hold that id;
 *   - a banner needs artwork that only the merchant has.
 *
 * A section with nothing behind it renders NOTHING — no empty heading, no
 * blank rail — so a fresh install shows a clean homepage, and each section
 * appears the moment an admin gives it content. That is why every section is
 * created ENABLED: the admin's next action is to add content, not to hunt for
 * a switch.
 *
 * ===========================================================================
 * RE-RUN SAFETY
 * ===========================================================================
 * Keyed on `code`, which carries a unique index. An existing row is left
 * completely alone — a merchant who renamed a heading or reordered the page
 * must not have that overwritten if this patch is ever re-applied.
 */
class CreateDefaultSections implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly SectionFactory $sectionFactory,
        private readonly SectionRepository $sectionRepository
    ) {
    }

    /**
     * Titles are the exact strings from the Figma homepage (frame 595:14462);
     * the English side is the working translation used in this module's
     * en_US.csv.
     */
    private const SECTIONS = [
        [
            'code' => 'home_hero',
            'type' => SectionType::BANNER,
            // The hero carries its message inside the artwork itself (Figma
            // 595:14562 has no heading above it), so both titles are empty and
            // the shared header renders nothing at all.
            'title_ar' => '',
            'title_en' => '',
            'sort_order' => 0,
        ],
        [
            'code' => 'home_top_categories',
            'type' => SectionType::CATEGORY_TILES,
            'title_ar' => 'الفئات الأكثر بحثا',
            'title_en' => 'Most searched categories',
            'sort_order' => 10,
        ],
        [
            'code' => 'home_best_sellers',
            'type' => SectionType::PRODUCT_CAROUSEL,
            'title_ar' => 'الأكثر مبيعا',
            'title_en' => 'Best sellers',
            'sort_order' => 20,
            'product_limit' => 10,
        ],
        [
            'code' => 'home_featured_offers',
            'type' => SectionType::PRODUCT_CAROUSEL,
            'title_ar' => 'عروض مميزه',
            'title_en' => 'Featured offers',
            'sort_order' => 30,
            'product_limit' => 10,
        ],
        [
            'code' => 'home_showcase',
            'type' => SectionType::PRODUCT_VIDEO_CAROUSEL,
            'title_ar' => 'شاهد المنتج، وأحكم بنفسك',
            'title_en' => 'See the product, judge for yourself',
            'sort_order' => 40,
            // Smaller than the plain rails: each item here is a tall media
            // card, and every one of them loads a poster image.
            'product_limit' => 6,
        ],
    ];

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('spartrak_homepage_section');

        $existing = $connection->fetchCol(
            $connection->select()->from($table, 'code')
        );

        foreach (self::SECTIONS as $definition) {
            if (in_array($definition['code'], $existing, true)) {
                continue;
            }

            $section = $this->sectionFactory->create();
            $section->addData($definition + [
                'is_active' => 1,
                'product_limit' => 10,
                'category_id' => null,
                'link_url' => null,
            ]);

            $this->sectionRepository->save($section);
        }

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
