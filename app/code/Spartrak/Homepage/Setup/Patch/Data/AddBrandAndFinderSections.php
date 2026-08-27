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
 * Adds the brand rail and the cascading finder to installs that already ran
 * CreateDefaultSections.
 *
 * ===========================================================================
 * WHY A SECOND PATCH RATHER THAN EXTENDING THE FIRST
 * ===========================================================================
 * Magento records a data patch as applied and never runs it again. Adding two
 * rows to CreateDefaultSections' array therefore does nothing at all on a site
 * where that patch has already run — which is every site this module is
 * already live on. New sections need a new patch; that is the whole reason
 * this file exists.
 *
 * CreateDefaultSections DOES still list all seven, so a FRESH install gets the
 * complete set from one patch. This one then finds both rows already present
 * and skips them. Both patches key on `code` and leave existing rows untouched,
 * so they are safe to run in either order and safe to re-run.
 */
class AddBrandAndFinderSections implements DataPatchInterface
{
    /**
     * Positioned between the category tiles (10) and the best-sellers rail
     * (20), which is where Figma puts them on the homepage frame.
     */
    private const SECTIONS = [
        [
            'code' => 'home_brands',
            'type' => SectionType::BRAND_CAROUSEL,
            'title_ar' => 'تصفح جميع الماركات',
            'title_en' => 'Browse all brands',
            'sort_order' => 15,
        ],
        [
            'code' => 'home_finder',
            'type' => SectionType::CASCADE_SEARCH,
            // The finder's card carries its own heading (Figma 595:15845), so
            // the shared section header is deliberately left empty.
            'title_ar' => '',
            'title_en' => '',
            'sort_order' => 18,
        ],
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly SectionFactory $sectionFactory,
        private readonly SectionRepository $sectionRepository
    ) {
    }

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
     * Runs after the base set, so a fresh install creates everything in one
     * pass and this patch simply finds nothing to do.
     *
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [CreateDefaultSections::class];
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
