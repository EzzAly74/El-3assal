<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAddress\Setup\Patch\Data;

use Magento\Customer\Model\Indexer\Address\AttributeProvider;
use Magento\Customer\Setup\CustomerSetup;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Registers `additional_phone` as a customer ADDRESS attribute.
 *
 * The column is created by db_schema.xml; this creates the EAV attribute row
 * that makes Magento's metadata layer aware of it. Both are required - see
 * db_schema.xml for what breaks with only one.
 *
 * ===========================================================================
 * backend_type = static IS THE LOAD-BEARING LINE
 * ===========================================================================
 * `static` is what tells the EAV layer "the value lives in a real column on
 * customer_address_entity". Declaring it as `varchar` instead would make
 * Magento write into customer_address_entity_varchar while the column added by
 * db_schema.xml stayed permanently empty - and nothing would report an error.
 * Spartrak_CustomerAuth's AddPhoneAttributes records the same trap for the
 * customer entity.
 *
 * ===========================================================================
 * used_in_forms IS WHAT PUTS IT ON THE SCREEN
 * ===========================================================================
 * Nothing renders this field explicitly. Magento builds address forms from
 * attribute metadata, so listing the forms here is what makes it appear in:
 *
 *   customer_address_edit      My Account > Address Book
 *   customer_register_address  the CHECKOUT's new-address form - this is the
 *                              one Figma 557:4731 / 687:15189 draws
 *   adminhtml_customer_address the admin's customer address form
 *
 * That is also why it needs no template of its own on either viewport: the
 * desktop modal and the mobile bottom sheet render the same metadata-driven
 * fieldset, so one registration serves both.
 */
class AddAdditionalPhoneAttribute implements DataPatchInterface
{
    private const ATTRIBUTE_CODE = 'additional_phone';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly CustomerSetupFactory $customerSetupFactory
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

        /** @var CustomerSetup $customerSetup */
        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $customerSetup->addAttribute(
            AttributeProvider::ENTITY,
            self::ATTRIBUTE_CODE,
            [
                'label' => 'Additional Phone',
                'type' => 'static',
                'input' => 'text',
                'required' => false,
                'visible' => true,
                'user_defined' => true,
                'system' => false,
                'sort_order' => 145,
                'position' => 145,
                'is_used_in_grid' => false,
                'is_visible_in_grid' => false,
                'is_filterable_in_grid' => false,
                'is_searchable_in_grid' => false,
                // A phone number, not free text. The same validation class
                // Magento puts on `telephone`, so the two behave alike.
                'validate_rules' => '{"max_text_length":32}',
            ]
        );

        $attribute = $customerSetup->getEavConfig()
            ->getAttribute(AttributeProvider::ENTITY, self::ATTRIBUTE_CODE);

        $attribute->setData('used_in_forms', [
            'adminhtml_customer_address',
            'customer_address_edit',
            'customer_register_address',
        ]);

        $attribute->save();

        $this->moduleDataSetup->endSetup();

        return $this;
    }
}
