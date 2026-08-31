<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Checkout\Plugin\Checkout;

use Magento\Checkout\Block\Checkout\LayoutProcessor;

/**
 * Shapes Magento's checkout address form into the one Figma drew (557:5173).
 *
 * ===========================================================================
 * WHY A LayoutProcessor PLUGIN AND NOT A REPLACEMENT FORM
 * ===========================================================================
 * It was tempting to write a Spartrak address form outright - six fields, full
 * control, no negotiation with core. That would have thrown away everything
 * Magento's attribute-driven form gives for free:
 *
 *   - the region control switching between a select and a free-text box
 *     depending on whether the chosen country HAS regions;
 *   - validation rules that come from each attribute's own configuration, so
 *     changing "telephone is required" in the admin still works;
 *   - fields other modules add through customer_eav_attribute, which would
 *     silently stop appearing;
 *   - the country/region/postcode interdependencies, which are genuinely
 *     intricate and which nobody wants to own a second copy of.
 *
 * So this reshapes the form core already built: labels, order, and which fields
 * are visible. Every field keeps its own component, validation and data source.
 *
 * ===========================================================================
 * HIDDEN IS NOT REMOVED
 * ===========================================================================
 * `country_id`, `city` and `postcode` are set invisible, NOT unset. They stay in
 * the form's data source, keep their defaults, and are still submitted:
 *
 *   country_id  takes its value from general/country/default, so the address
 *               gets a country without asking for one.
 *   city        is filled from the chosen governorate on save - see
 *               Spartrak\CustomerAddress\Model\GovernorateCity for why that is
 *               the right value for Egypt and not a placeholder.
 *   postcode    is optional for Egypt; see Spartrak_CustomerAddress's config.xml.
 *
 * Unsetting them instead would have produced an address Magento's own validator
 * rejects, from a form with no field to fix it in.
 */
class AddressFormLayout
{
    /**
     * Fields Figma's form does not draw.
     *
     * `company`, `fax`, `vat_id`, `prefix`, `middlename` and `suffix` are
     * genuinely unused by this storefront; the other three are load-bearing and
     * are explained in the class docblock above.
     */
    private const HIDDEN = [
        'company', 'fax', 'vat_id', 'prefix', 'middlename', 'suffix',
        'country_id', 'city', 'postcode', 'region',
    ];

    /**
     * label, placeholder and order, in Figma's reading order.
     *
     * The placeholders are the design's own prompt text, not invented copy.
     */
    private const FIELDS = [
        'firstname' => [
            'label'       => 'First name',
            'placeholder' => 'Please enter your first name',
            'sortOrder'   => 10,
        ],
        'lastname' => [
            'label'       => 'Last name',
            'placeholder' => 'Please enter your last name',
            'sortOrder'   => 20,
        ],
        'region_id' => [
            'label'       => 'Governorate',
            'placeholder' => 'Choose a region or governorate',
            'sortOrder'   => 30,
        ],
        'street' => [
            'label'       => 'Address details',
            'placeholder' => 'Please write your address in full',
            'sortOrder'   => 40,
        ],
        'telephone' => [
            'label'       => 'Mobile number',
            'placeholder' => '01xxxxxxxxxx',
            'sortOrder'   => 50,
        ],
        'additional_phone' => [
            'label'       => 'Additional number',
            'placeholder' => '01xxxxxxxxxx',
            'sortOrder'   => 60,
        ],
    ];

    /**
     * @param LayoutProcessor $subject
     * @param array<string, mixed> $jsLayout
     * @return array<string, mixed>
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterProcess(LayoutProcessor $subject, array $jsLayout): array
    {
        /**
         * Checked BEFORE the reference is taken, and this is not fussiness.
         * `$x = &$a['b']['c']` on a path that does not exist CREATES every
         * missing level as an empty array, so an isset() test on the reference
         * would report "absent" while having just written half a component tree
         * into the layout it was supposed to leave alone.
         */
        if (!isset(
            $jsLayout['components']['checkout']['children']['steps']['children']
            ['shipping-step']['children']['shippingAddress']['children']
            ['shipping-address-fieldset']['children']
        )) {
            return $jsLayout;
        }

        $fields = &$jsLayout['components']['checkout']['children']['steps']['children']
            ['shipping-step']['children']['shippingAddress']['children']
            ['shipping-address-fieldset']['children'];

        if (!is_array($fields)) {
            return $jsLayout;
        }

        foreach (self::HIDDEN as $code) {
            if (isset($fields[$code])) {
                $fields[$code]['visible'] = false;
            }
        }

        foreach (self::FIELDS as $code => $spec) {
            if (!isset($fields[$code])) {
                continue;
            }

            $fields[$code]['label'] = __($spec['label']);
            $fields[$code]['sortOrder'] = $spec['sortOrder'];
            $fields[$code]['placeholder'] = __($spec['placeholder']);
        }

        $this->makeStreetASingleTextarea($fields);

        return $jsLayout;
    }

    /**
     * Figma draws the address as ONE 120px-tall textarea (721:29511), not as
     * Magento's row of street lines.
     *
     * `street` stays an array-backed group, because that is what the attribute
     * is and what every consumer of an address expects; only its first line is
     * re-templated as a textarea. Collapsing the attribute itself to a scalar
     * would have broken every other address form on the site, the admin
     * included.
     *
     * Lines beyond the first are hidden rather than deleted, for the same
     * reason as the fields above: a merchant who raises
     * customer/address/street_lines gets them back by changing the setting, and
     * an address imported with two lines still round-trips.
     *
     * @param array<string, mixed> $fields
     */
    private function makeStreetASingleTextarea(array &$fields): void
    {
        if (!isset($fields['street']['children']) || !is_array($fields['street']['children'])) {
            return;
        }

        foreach ($fields['street']['children'] as $index => &$line) {
            if (!is_array($line)) {
                continue;
            }

            if ((int) $index === 0) {
                $line['config']['elementTmpl'] = 'ui/form/element/textarea';
                $line['config']['rows'] = 4;
                $line['placeholder'] = __('Please write your address in full');
                // The group already carries Figma's label; a second one on the
                // line inside it would render twice.
                $line['label'] = '';

                continue;
            }

            $line['visible'] = false;
        }
    }
}
