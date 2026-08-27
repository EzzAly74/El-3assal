<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Setup\Patch\Data;

use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetup;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Eav\Model\Entity\Attribute\Set as AttributeSet;
use Magento\Eav\Model\Entity\Attribute\SetFactory as AttributeSetFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Registers phone_number and phone_verified_at as customer attributes.
 *
 * The COLUMNS are created by db_schema.xml; this patch creates the matching EAV
 * attribute rows so Magento's metadata layer knows they exist. Both halves are
 * required and they do different jobs:
 *
 *   - Without the columns, there is no UNIQUE index and "one account per phone"
 *     is only a hopeful application-level check.
 *   - Without these attribute rows, setCustomAttribute('phone_number', ...) is
 *     silently dropped on save, the admin customer form cannot show the field,
 *     and the value is invisible to the customer grid indexer.
 *
 * backend_type is 'static', which is what tells the EAV layer "the value lives
 * in a real column on customer_entity, not in customer_entity_varchar". Getting
 * this wrong is the classic failure here: declaring it as 'varchar' makes
 * Magento write to the EAV table while the unique index sits on an
 * always-empty column, so uniqueness silently stops being enforced.
 */
class AddPhoneAttributes implements DataPatchInterface
{
    private const ATTRIBUTE_PHONE_NUMBER = 'phone_number';
    private const ATTRIBUTE_PHONE_VERIFIED_AT = 'phone_verified_at';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly CustomerSetupFactory $customerSetupFactory,
        private readonly AttributeSetFactory $attributeSetFactory
    ) {
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

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        /** @var CustomerSetup $customerSetup */
        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $customerEntity = $customerSetup->getEavConfig()
            ->getEntityType(CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER);
        $attributeSetId = (int) $customerEntity->getDefaultAttributeSetId();

        /** @var AttributeSet $attributeSet */
        $attributeSet = $this->attributeSetFactory->create();
        $attributeGroupId = (int) $attributeSet->getDefaultGroupId($attributeSetId);

        $customerSetup->addAttribute(
            Customer::ENTITY,
            self::ATTRIBUTE_PHONE_NUMBER,
            [
                'label' => 'Phone Number',
                // Static: value lives in customer_entity.phone_number.
                'type' => 'static',
                'input' => 'text',
                'required' => false,
                'visible' => true,
                'user_defined' => false,
                'system' => false,
                'position' => 90,
                'sort_order' => 90,
                'global' => true,
                // Length matches the column so admin input cannot exceed it.
                'frontend_class' => 'validate-length maximum-length-20',
                'is_used_in_grid' => true,
                'is_visible_in_grid' => true,
                'is_filterable_in_grid' => true,
                // Searchable: this is the store's primary customer identifier, so
                // "find the customer who called about order X" is a phone search.
                'is_searchable_in_grid' => true,
            ]
        );

        $customerSetup->addAttribute(
            Customer::ENTITY,
            self::ATTRIBUTE_PHONE_VERIFIED_AT,
            [
                'label' => 'Phone Verified At',
                'type' => 'static',
                'input' => 'date',
                'required' => false,
                // Not shown on the storefront: it is an audit fact about the
                // account, not something a shopper edits or needs to read.
                'visible' => true,
                'user_defined' => false,
                'system' => false,
                'position' => 91,
                'sort_order' => 91,
                'global' => true,
                'is_used_in_grid' => true,
                'is_visible_in_grid' => false,
                'is_filterable_in_grid' => false,
                'is_searchable_in_grid' => false,
            ]
        );

        $phoneNumber = $customerSetup->getEavConfig()
            ->getAttribute(Customer::ENTITY, self::ATTRIBUTE_PHONE_NUMBER);
        $phoneNumber->addData([
            'attribute_set_id' => $attributeSetId,
            'attribute_group_id' => $attributeGroupId,
            // adminhtml_customer only. Deliberately NOT customer_account_create
            // or customer_account_edit: the phone number is set through the OTP
            // flow, which proves ownership. Exposing it on the account-edit form
            // would let a shopper change their login identifier to an unverified
            // number — or to one belonging to somebody else — with no proof at
            // all. A "change my number" journey needs its own OTP flow; when that
            // is built, it goes through Otp\Service, not through this form.
            'used_in_forms' => ['adminhtml_customer'],
        ]);
        $phoneNumber->save();

        $verifiedAt = $customerSetup->getEavConfig()
            ->getAttribute(Customer::ENTITY, self::ATTRIBUTE_PHONE_VERIFIED_AT);
        $verifiedAt->addData([
            'attribute_set_id' => $attributeSetId,
            'attribute_group_id' => $attributeGroupId,
            'used_in_forms' => ['adminhtml_customer'],
        ]);
        $verifiedAt->save();

        $this->moduleDataSetup->endSetup();

        return $this;
    }
}
