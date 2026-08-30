<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model\Source;

use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * The governorate dropdown on the branch and depot forms.
 *
 * Reads Magento's OWN region table - the same rows
 * Setup\Patch\Data\AddEgyptGovernorates wrote, and the same ones a customer
 * picks from on the address form. That shared source is the whole point of
 * putting the governorates there instead of in a private table: a depot in
 * Giza and a shopper in Giza hold the same region_id, so the two are
 * comparable without a translation step.
 *
 * Scoped to the store's default country rather than a literal, for the reason
 * given on Plugin\Checkout\ApplyPickupLocation::defaultCountryId().
 */
class GovernorateOptions implements OptionSourceInterface
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $options = null;

    public function __construct(
        private readonly RegionCollectionFactory $regionCollectionFactory,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }

        // An empty first entry, because the governorate is OPTIONAL on both
        // forms (region_id is nullable, and SET NULL on delete). Without it the
        // select would silently commit the first governorate in the list to
        // every location an admin creates without touching the field.
        $this->options = [['value' => '', 'label' => __('-- Please Select --')]];

        $collection = $this->regionCollectionFactory->create()
            ->addCountryFilter($this->defaultCountryId())
            ->load();

        foreach ($collection as $region) {
            $this->options[] = [
                'value' => (int) $region->getId(),
                // getName() resolves through directory_country_region_name for
                // the ADMIN's locale, falling back to default_name - so an
                // Arabic-speaking admin sees Arabic governorates without this
                // class choosing a language itself.
                'label' => $region->getName() ?: $region->getDefaultName(),
            ];
        }

        return $this->options;
    }

    private function defaultCountryId(): string
    {
        return (string) $this->scopeConfig->getValue(DirectoryHelper::XML_PATH_DEFAULT_COUNTRY);
    }
}
