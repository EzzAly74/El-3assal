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
 * Adds the split promo section - Figma's "Top Products Container" (595:15329).
 *
 * ===========================================================================
 * WHY IT WAS MISSING
 * ===========================================================================
 * The type, the block, the template and the admin fields for this layout all
 * shipped - but no row ever used the type, so the layout was unreachable and
 * the section simply did not exist on the homepage. An editor who wanted it had
 * to know to create a section by hand and pick the right type from a dropdown.
 *
 * The symptom was a confusing one: the promo fields showed on EVERY section's
 * form (see the section form's note on the switcher bug), so it looked as
 * though any section could grow a promo panel. Filling them in on a section of
 * some other type did nothing at all, because only this type's template reads
 * them.
 *
 * ===========================================================================
 * WHY THE PANEL COPY IS NOT SEEDED HERE
 * ===========================================================================
 * The badge, headline, body and artwork are CONTENT, and content belongs to the
 * dashboard - so the patch creates the section and stops. Figma's wording
 * ("عروض مميزه علي طلمبات المايه متفوتهاش!") is sample copy in a design file,
 * not a string this module gets to own.
 *
 * A section of this type with an empty panel renders as the plain full-width
 * rail rather than a broken half-layout (Block ProductPromoCarousel::hasPromo),
 * so this is safe to create before anyone has filled it in.
 *
 * ===========================================================================
 * POSITION
 * ===========================================================================
 * sort_order 25 puts it between the best-sellers rail (20) and the video
 * showcase (40), which is where the Figma frame stacks it: the split sits at
 * y=2857, below Flash Deals and above the Buyer Section.
 */
class AddPromoSection implements DataPatchInterface
{
    private const CODE = 'home_promo';

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
            $connection->select()->from($table, 'code')->where('code = ?', self::CODE)
        );

        if (!$existing) {
            $section = $this->sectionFactory->create();
            $section->addData([
                'code' => self::CODE,
                'type' => SectionType::PRODUCT_PROMO_CAROUSEL,
                // The rail's own heading, which Figma 595:15339 draws as
                // "عروض مميزة". Editable like every other section title.
                'title_ar' => 'عروض مميزة',
                'title_en' => 'Featured offers',
                // Figma 595:15340 shows three cards in the narrowed rail.
                'product_limit' => 6,
                'category_id' => null,
                'link_url' => null,
                'is_active' => 1,
                'sort_order' => 25,
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
        return [AddBrandAndFinderSections::class];
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
