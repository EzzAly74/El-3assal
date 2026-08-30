<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Setup\Patch\Data;

use Magento\Directory\Setup\DataInstaller;
use Magento\Directory\Setup\DataInstallerFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * The 27 governorates of Egypt, added to Magento's OWN directory tables.
 *
 * ===========================================================================
 * WHY THIS IS NOT A CUSTOM TABLE
 * ===========================================================================
 * Magento already models "administrative subdivisions of a country" and uses
 * that model everywhere an address is entered, validated, taxed or shipped. It
 * simply ships no rows for Egypt - vendor/magento/module-directory carries
 * region patches for 30 countries and EG is not among them.
 *
 * Writing the governorates here rather than into a private table means:
 *
 *   - the customer address form renders a governorate DROPDOWN instead of a
 *     free-text box, so two shoppers in Giza cannot spell it three ways;
 *   - depots and branches foreign-key to the same list the address uses, so a
 *     depot's governorate and a shopper's governorate are comparable values;
 *   - tax and shipping rules, now or later, can be written per governorate
 *     with no bridge code at all.
 *
 * These are real public administrative divisions, not invented business data -
 * reference geography, which is exactly what this table is for.
 *
 * ===========================================================================
 * WHY IT WRITES BOTH LOCALES
 * ===========================================================================
 * Magento's own DataInstaller inserts an en_US name row and nothing else.
 * This storefront is Arabic-first, so an Arabic shopper choosing "Giza" from a
 * list of Latin names would be reading someone else's language on the most
 * sensitive field of the form. The Arabic names are inserted as a second
 * locale row per region, which is the mechanism the table already provides -
 * Magento_Directory reads directory_country_region_name by the store's locale.
 *
 * ===========================================================================
 * IDEMPOTENCE
 * ===========================================================================
 * A data patch runs once by registry, but "once" is not a guarantee on a
 * database that has been through a migration or a partial restore. Both halves
 * check before writing, so re-running this patch on a store that already has
 * EG regions adds nothing and breaks nothing.
 */
class AddEgyptGovernorates implements DataPatchInterface
{
    private const COUNTRY_ID = 'EG';

    /**
     * ISO 3166-2:EG code, English name, Arabic name.
     *
     * Ordered as Magento orders its own region lists - alphabetically by the
     * English name - because the admin form's dropdown has no other sort and
     * a shopper scanning for a governorate needs a predictable order.
     */
    private const GOVERNORATES = [
        ['EG-ALX', 'Alexandria', 'الإسكندرية'],
        ['EG-ASN', 'Aswan', 'أسوان'],
        ['EG-AST', 'Asyut', 'أسيوط'],
        ['EG-BH', 'Beheira', 'البحيرة'],
        ['EG-BNS', 'Beni Suef', 'بني سويف'],
        ['EG-C', 'Cairo', 'القاهرة'],
        ['EG-DK', 'Dakahlia', 'الدقهلية'],
        ['EG-DT', 'Damietta', 'دمياط'],
        ['EG-FYM', 'Faiyum', 'الفيوم'],
        ['EG-GH', 'Gharbia', 'الغربية'],
        ['EG-GZ', 'Giza', 'الجيزة'],
        ['EG-IS', 'Ismailia', 'الإسماعيلية'],
        ['EG-KFS', 'Kafr El Sheikh', 'كفر الشيخ'],
        ['EG-LX', 'Luxor', 'الأقصر'],
        ['EG-MT', 'Matrouh', 'مطروح'],
        ['EG-MN', 'Minya', 'المنيا'],
        ['EG-MNF', 'Monufia', 'المنوفية'],
        ['EG-WAD', 'New Valley', 'الوادي الجديد'],
        ['EG-SIN', 'North Sinai', 'شمال سيناء'],
        ['EG-PTS', 'Port Said', 'بورسعيد'],
        ['EG-KB', 'Qalyubia', 'القليوبية'],
        ['EG-KN', 'Qena', 'قنا'],
        ['EG-BA', 'Red Sea', 'البحر الأحمر'],
        ['EG-SHR', 'Sharqia', 'الشرقية'],
        ['EG-SHG', 'Sohag', 'سوهاج'],
        ['EG-JS', 'South Sinai', 'جنوب سيناء'],
        ['EG-SUZ', 'Suez', 'السويس'],
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly DataInstallerFactory $dataInstallerFactory,
        private readonly ResourceConnection $resourceConnection
    ) {
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

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        if (!$this->hasRegions()) {
            /** @var DataInstaller $dataInstaller */
            $dataInstaller = $this->dataInstallerFactory->create();

            // Magento's own installer, not a reimplementation of it. As well as
            // the region and en_US name rows it adds EG to
            // general/region/state_required, which is what actually turns the
            // address form's governorate field into a required dropdown.
            $dataInstaller->addCountryRegions(
                $this->moduleDataSetup->getConnection(),
                array_map(
                    static fn (array $row): array => [self::COUNTRY_ID, $row[0], $row[1]],
                    self::GOVERNORATES
                )
            );
        }

        $this->addArabicNames();

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    /**
     * True when this country already has at least one region row.
     */
    private function hasRegions(): bool
    {
        $connection = $this->moduleDataSetup->getConnection();

        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('directory_country_region'), 'region_id')
            ->where('country_id = ?', self::COUNTRY_ID)
            ->limit(1);

        return (bool) $connection->fetchOne($select);
    }

    /**
     * Adds the ar_EG name row for each governorate that does not have one.
     *
     * Keyed off the region CODE rather than an id captured at insert time, so
     * it works whether the rows were just written by this patch or were already
     * present from a previous install.
     */
    private function addArabicNames(): void
    {
        $connection = $this->moduleDataSetup->getConnection();
        $regionTable = $this->resourceConnection->getTableName('directory_country_region');
        $nameTable = $this->resourceConnection->getTableName('directory_country_region_name');

        // code => region_id, in one query rather than one per governorate.
        $regionIdsByCode = $connection->fetchPairs(
            $connection->select()
                ->from($regionTable, ['code', 'region_id'])
                ->where('country_id = ?', self::COUNTRY_ID)
        );

        if ($regionIdsByCode === []) {
            return;
        }

        $existing = $connection->fetchCol(
            $connection->select()
                ->from($nameTable, 'region_id')
                ->where('locale = ?', 'ar_EG')
                ->where('region_id IN (?)', array_values($regionIdsByCode))
        );

        $existing = array_flip(array_map('intval', $existing));

        $rows = [];

        foreach (self::GOVERNORATES as [$code, , $arabicName]) {
            $regionId = isset($regionIdsByCode[$code]) ? (int) $regionIdsByCode[$code] : null;

            if ($regionId === null || isset($existing[$regionId])) {
                continue;
            }

            $rows[] = [
                'locale' => 'ar_EG',
                'region_id' => $regionId,
                'name' => $arabicName,
            ];
        }

        if ($rows !== []) {
            $connection->insertMultiple($nameTable, $rows);
        }
    }
}
