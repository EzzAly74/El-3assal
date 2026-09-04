<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Catalog\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Model\Entity\Attribute\Source\Table as TableSource;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The five product-specification filters Figma's sidebar draws under the price
 * group — الكبسولة, القطر, الحجم, اللون, الضمان (Figma 488:12258, rows
 * 488:12263 / 488:12267 / 488:12271 / 488:12275 / 538:7634).
 *
 * ===========================================================================
 * "SHOULD I ADD THESE BY HAND ON EACH CATEGORY, OR SHOULD THEY BE CODED?"
 * ===========================================================================
 * Coded — this file. But it is worth being exact about what "coded" can and
 * cannot mean here, because the answer is split in two:
 *
 *   SCHEMA is code.  Whether an attribute EXISTS, whether it is filterable,
 *                    what it is called in Arabic and in English, and which
 *                    attribute sets carry it are deployment facts. They must
 *                    be identical on every environment, they must survive a
 *                    fresh install, and they must not depend on somebody
 *                    remembering a sequence of admin clicks. That is all of
 *                    them, and it is all here.
 *
 *   DATA is not.     Which diameters this catalogue actually sells, which
 *                    warranty terms are offered, which colours exist — and
 *                    which product has which — is business data. Inventing
 *                    an option list here would be exactly the fabricated
 *                    data CLAUDE.md section 9 forbids, and it would be wrong
 *                    the day it shipped. So the attributes are created with
 *                    NO options; the admin adds the real ones once, under
 *                    Stores > Attributes, or they arrive with a product
 *                    import.
 *
 * And this is also why nothing needs doing "on each category": layered
 * navigation is driven by the attribute's own is_filterable flag plus the
 * values products in that category actually carry. Set the flag once (here),
 * give products values, and every category shows exactly the filters its own
 * products justify — with no per-category configuration anywhere.
 *
 * UNTIL PRODUCTS CARRY VALUES, THESE FILTERS DO NOT APPEAR. That is Magento
 * behaving correctly, not a bug: a filter with no options is a dead control.
 *
 * ===========================================================================
 * IDEMPOTENT, AND DELIBERATELY ASSERTIVE ABOUT is_filterable
 * ===========================================================================
 * `color` and `size` may well already exist — `color` ships with Magento's own
 * catalog install. Existing attributes are NOT recreated; they are updated so
 * the five behave the same way. That update DOES force is_filterable on, which
 * is the whole point of the patch: the design says these five filter, so they
 * filter. It is called out here rather than left as a surprise.
 *
 * ===========================================================================
 * ARABIC LABELS
 * ===========================================================================
 * A layered-navigation filter's title is the attribute's STORE LABEL, which
 * i18n CSV files do not reach — Magento reads it straight from
 * eav_attribute_label, it never passes through __(). So the Arabic titles are
 * written per store view here, to every store whose configured locale is
 * Arabic, resolved at patch time rather than hardcoded to a store id that
 * differs between environments. The admin (default) label stays English so
 * the backend grid stays readable for a non-Arabic operator.
 */
class AddSpecFilterAttributes implements DataPatchInterface
{
    /**
     * code => [admin label, Arabic store label, sort order within its group].
     *
     * The Arabic strings are the exact ones in Figma. The codes are plain
     * English words rather than a `spartrak_` prefix on purpose: these are
     * ordinary catalogue specifications an admin will manage forever, not
     * internal theme fields, and two of them already exist under these names.
     */
    private const ATTRIBUTES = [
        'capsule' => ['Capsule', 'الكبسولة', 100],
        'diameter' => ['Diameter', 'القطر', 110],
        'size' => ['Size', 'الحجم', 120],
        'color' => ['Color', 'اللون', 130],
        'warranty' => ['Warranty', 'الضمان', 140],
    ];

    /**
     * Magento's own store-locale config path. Written out rather than pulled
     * from Magento\Directory\Helper\Data so this patch does not take a
     * dependency on a helper — and on Magento_Directory — to read one string.
     */
    private const XML_PATH_LOCALE = 'general/locale/code';

    /**
     * The attribute-edit flags every one of the five must end up with,
     * whether it was created by this patch or already existed.
     *
     * used_in_product_listing is on because the PLP renders these on the
     * card/quick view path and Magento would otherwise refetch each product
     * individually to read them.
     */
    private const FILTER_FLAGS = [
        'is_filterable' => 1,
        'is_filterable_in_search' => 1,
        'is_visible_on_front' => 1,
        'used_in_product_listing' => 1,
        'is_searchable' => 1,
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $entityTypeId = (int) $eavSetup->getEntityTypeId(Product::ENTITY);
        $arabicStoreIds = $this->getArabicStoreIds();

        foreach (self::ATTRIBUTES as $code => [$adminLabel, $arabicLabel, $sortOrder]) {
            $existingId = $eavSetup->getAttributeId(Product::ENTITY, $code);

            if (!$existingId) {
                $eavSetup->addAttribute(Product::ENTITY, $code, [
                    // int + select + the Table source is Magento's own
                    // dropdown-with-options shape, which is what layered
                    // navigation buckets on. A free-text attribute would
                    // produce one filter option per distinct string.
                    'type' => 'int',
                    'input' => 'select',
                    'source' => TableSource::class,
                    'label' => $adminLabel,
                    'required' => false,
                    'user_defined' => true,
                    'visible' => true,
                    'comparable' => false,
                    'is_html_allowed_on_front' => false,
                    'used_for_sort_by' => false,
                    // GLOBAL: a diameter is the same number in every store
                    // view. Only its LABEL is per-locale, and that is an
                    // option-label concern, not a scope one.
                    'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                    'group' => 'Product Details',
                    'sort_order' => $sortOrder,
                ] + self::FILTER_FLAGS);

                $existingId = $eavSetup->getAttributeId(Product::ENTITY, $code);
            } else {
                foreach (self::FILTER_FLAGS as $flag => $value) {
                    $eavSetup->updateAttribute(Product::ENTITY, $code, $flag, $value);
                }
            }

            if (!$existingId) {
                continue;
            }

            $this->attachToEveryAttributeSet($eavSetup, $entityTypeId, (int) $existingId, $sortOrder);
            $this->writeStoreLabels((int) $existingId, $arabicLabel, $arabicStoreIds);
        }

        return $this;
    }

    /**
     * addAttribute() only reaches the DEFAULT attribute set. A catalogue that
     * uses more than one set would otherwise get the filter on some products
     * and not others, which reads as a broken filter rather than as a
     * configuration gap — so every set gets it, in that set's own default
     * group (resolved per set, never assumed to be named the same thing).
     */
    private function attachToEveryAttributeSet(
        EavSetup $eavSetup,
        int $entityTypeId,
        int $attributeId,
        int $sortOrder
    ): void {
        foreach ($eavSetup->getAllAttributeSetIds($entityTypeId) as $setId) {
            $groupId = $eavSetup->getDefaultAttributeGroupId($entityTypeId, (int) $setId);

            if (!$groupId) {
                continue;
            }

            // addAttributeToSet is a no-op when the attribute is already in
            // the set, so re-running the patch changes nothing.
            $eavSetup->addAttributeToSet($entityTypeId, (int) $setId, (int) $groupId, $attributeId, $sortOrder);
        }
    }

    /**
     * Writes one attribute's Arabic store label, replacing any existing row
     * for the same store so the patch is safe to re-run.
     *
     * Done through the connection rather than through a model because this is
     * a single two-column upsert into eav_attribute_label and loading the
     * attribute model per store would be four objects and a save event for a
     * string.
     *
     * @param int[] $storeIds
     */
    private function writeStoreLabels(int $attributeId, string $label, array $storeIds): void
    {
        if ($storeIds === []) {
            return;
        }

        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('eav_attribute_label');

        $connection->delete($table, [
            'attribute_id = ?' => $attributeId,
            'store_id IN (?)' => $storeIds,
        ]);

        $rows = [];
        foreach ($storeIds as $storeId) {
            $rows[] = [
                'attribute_id' => $attributeId,
                'store_id' => $storeId,
                'value' => $label,
            ];
        }

        $connection->insertMultiple($table, $rows);
    }

    /**
     * Every store view whose configured locale is Arabic.
     *
     * Resolved from config rather than hardcoded, because a store id is an
     * environment fact and this patch has to give the same result on the
     * developer's install, on staging and in production.
     *
     * @return int[]
     */
    private function getArabicStoreIds(): array
    {
        $storeIds = [];

        foreach ($this->storeManager->getStores() as $store) {
            $locale = (string) $this->scopeConfig->getValue(
                self::XML_PATH_LOCALE,
                ScopeInterface::SCOPE_STORE,
                $store->getId()
            );

            if (str_starts_with(strtolower($locale), 'ar')) {
                $storeIds[] = (int) $store->getId();
            }
        }

        return $storeIds;
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
