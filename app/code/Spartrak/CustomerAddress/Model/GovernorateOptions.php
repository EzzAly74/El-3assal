<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAddress\Model;

use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;

/**
 * "المحافظة" — the governorate list, for every form that asks for one.
 *
 * ===========================================================================
 * WHY THIS EXISTS AS A SHARED MODEL
 * ===========================================================================
 * It was written twice. Spartrak\Checkout\Plugin\Checkout\AddressFormLayout
 * built this list to feed the checkout's `region_id` select (Figma 557:5173),
 * and the account address form (562:18126) needs the same list, for the same
 * country, rendered from the same rule about missing locale names.
 *
 * Two copies of "which regions does this store offer" is the duplication
 * CLAUDE.md section 9 rules out, and the failure mode is specific rather than
 * theoretical: the copies disagree the first time the configured country
 * changes, and the disagreement shows up as a governorate a shopper can pick
 * at checkout but not save to their address book.
 *
 * ===========================================================================
 * THE COUNTRY IS CONFIGURATION, NOT AN ARGUMENT
 * ===========================================================================
 * `general/country/default` for the current store view — Spartrak_CustomerAddress
 * sets it to EG and its config.xml records what depended on that. Callers can
 * override it, but none do: this storefront ships to one country and both forms
 * hide the country field entirely rather than asking a question with one answer.
 *
 * ===========================================================================
 * MEMOISED PER COUNTRY
 * ===========================================================================
 * The region collection is one indexed query joined to the locale's region
 * names. Cheap, but not free, and the checkout's layout processor can be asked
 * for the same list more than once per request while it walks the form tree.
 * Per-request only — no cache entry to invalidate when a merchant edits the
 * directory tables.
 */
class GovernorateOptions
{
    /** @var array<string, array<int, array{value: string, label: string}>> */
    private array $memo = [];

    public function __construct(
        private readonly DirectoryHelper $directoryHelper,
        private readonly RegionCollectionFactory $regionCollectionFactory
    ) {
    }

    /**
     * The store view's configured country.
     */
    public function getCountryId(): string
    {
        return (string) $this->directoryHelper->getDefaultCountry();
    }

    /**
     * @param string|null $country Defaults to the configured country.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(?string $country = null): array
    {
        $country = $country !== null && $country !== '' ? $country : $this->getCountryId();

        if ($country === '') {
            return [];
        }

        if (isset($this->memo[$country])) {
            return $this->memo[$country];
        }

        $collection = $this->regionCollectionFactory->create()
            ->addCountryFilter($country)
            ->load();

        $options = [];

        foreach ($collection as $region) {
            $name = (string) $region->getName();

            if ($name === '') {
                // A region with no name for this locale would render as a blank
                // line the shopper can select. Its default_name is the honest
                // fallback and is what core's own option array falls back to.
                $name = (string) $region->getDefaultName();
            }

            $options[] = [
                // A string, because that is what a <select> posts back and what
                // the checkout's Select.getOption() matches its initial value
                // against.
                'value' => (string) $region->getId(),
                'label' => $name,
            ];
        }

        return $this->memo[$country] = $options;
    }
}
