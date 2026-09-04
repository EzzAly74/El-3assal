<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Checkout\Plugin\Checkout;

use Magento\Checkout\Block\Checkout\LayoutProcessor;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Spartrak\CustomerAddress\Model\GovernorateOptions;

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
     * @param DirectoryHelper         $directoryHelper        Supplies the store's configured country -
     *                                                        `general/country/default` for this store view.
     *                                                        Spartrak_CustomerAddress sets that to EG and
     *                                                        explains, in its config.xml, the several things
     *                                                        that quietly depended on it.
     * @param GovernorateOptions      $governorateOptions     The governorate list for that country, shared
     *                                                        with the account address form. Its collection
     *                                                        joins the region name for the current locale,
     *                                                        so the Arabic store gets Arabic governorates
     *                                                        with no work here.
     */
    public function __construct(
        private readonly DirectoryHelper $directoryHelper,
        private readonly GovernorateOptions $governorateOptions
    ) {
    }

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

        $this->removeTooltips($fields);
        $this->makeStreetASingleTextarea($fields);
        $this->wireGovernorateSelect($fields);

        return $jsLayout;
    }

    /**
     * Makes `المحافظة` an actual, populated, prompted dropdown.
     *
     * ===========================================================================
     * WHAT WENT WRONG: THE SELECT REPLACED ITSELF WITH A TEXT BOX
     * ===========================================================================
     * The rendered field was not an empty dropdown. It was the `region` FREE-TEXT
     * input standing in the dropdown's place - which is why it had no prompt (a
     * placeholder is set on it, but it is `region`'s label the design never
     * names) and why typing was possible at all.
     *
     * Core does that swap on purpose. `region_id` is a Select carrying
     * `customEntry: 'region'`, and Magento_Ui/js/form/element/select ends
     * setOptions() with:
     *
     *     if (this.customEntry) {
     *         isVisible = !!result.options.length;
     *         this.setVisible(isVisible);      // hide the SELECT
     *         this.toggleInput(!isVisible);    // show the text input
     *     }
     *
     * An empty option list therefore does not render an empty dropdown - it
     * renders the text box instead, and it overrides the `visible: false` this
     * plugin sets on `region` while doing it.
     *
     * And the list WAS empty, permanently. `region_id` gets its options from
     * `filterBy: { target: checkoutProvider:shippingAddress.country_id, field:
     * 'country_id' }`. Two things follow from that, both in select.js:
     *
     *   initFilter() seeds the list with `this.filter(this.default, ...)` -
     *   region_id's OWN default, which is undefined. Zero matches. So the field
     *   starts as a text box for everybody.
     *
     *   The only thing that fills it afterwards is the country value arriving on
     *   the PROVIDER. On a stock checkout the shopper supplies that by using the
     *   country dropdown. This form has no country dropdown: the design collects
     *   no country (see the class docblock), so nothing ever published one and
     *   the field never recovered. Browser autofill "fixed" it by writing a
     *   country in - exactly the event the select had been waiting for, which is
     *   why that looked like such a strange cure.
     *
     * Seeding `country_id`'s `value` and `default`, which this method used to do
     * alone, was not enough to close that loop and is not what makes it work now.
     *
     * ===========================================================================
     * THE FIX: A PLAIN SELECT WITH THE OPTIONS ALREADY IN IT
     * ===========================================================================
     * The store collects one country, fixed by configuration. In that world the
     * governorate field is not a dependent control at all - it is "the regions of
     * the configured country", which is knowable on the server. So the field is
     * given its options directly and the machinery that made it dependent is
     * removed:
     *
     *   component     the plain Select instead of .../element/region, whose only
     *                 extra behaviour is reacting to a country field that is not
     *                 on this form.
     *   options       built here, from the directory. The collection joins the
     *                 region name for the CURRENT LOCALE, so the Arabic store
     *                 gets Arabic governorate names for free.
     *   customEntry   dropped. With no customEntry the select can never swap
     *                 itself for `region`, so that field stays hidden as
     *                 intended - the failure mode above becomes unreachable
     *                 rather than merely unlikely.
     *   filterBy      dropped, along with the `imports` that pull the dictionary
     *                 of every allowed country's regions. Nothing left to filter.
     *
     * This also removes the whole `dictionaries.region_id` round trip from this
     * field's critical path (CLAUDE.md section 4): one small indexed query at
     * render time instead of a client-side filter over every region the store
     * ships.
     *
     * NO PLACEHOLDER, separately. The loop above writes `placeholder` on every
     * field, which is the right key for an input and is ignored by a select - a
     * select's prompt is its `caption`. So the design's `اختر المنطقة أو
     * المحافظة` was being set on an element that has no such property.
     *
     * IF THE CONFIGURED COUNTRY HAS NO REGIONS the field is left exactly as core
     * built it. Replacing a working dependent control with an empty static list
     * would be a worse answer than the one core already has, and a merchant who
     * switches the store to such a country should get core's behaviour back.
     *
     * @param array<string, mixed> $fields
     */
    private function wireGovernorateSelect(array &$fields): void
    {
        if (!isset($fields['region_id'])) {
            return;
        }

        $country = (string) $this->directoryHelper->getDefaultCountry();

        if ($country === '') {
            return;
        }

        // Hidden, but still submitted - see the class docblock. Both keys are
        // set because they seed different things: `default` the data provider,
        // `value` the element itself.
        if (isset($fields['country_id'])) {
            $fields['country_id']['default'] = $country;
            $fields['country_id']['value'] = $country;
        }

        $options = $this->getGovernorateOptions($country);

        if ($options === []) {
            return;
        }

        $fields['region_id']['component'] = 'Magento_Ui/js/form/element/select';
        $fields['region_id']['options'] = $options;
        $fields['region_id']['config']['caption'] = __(self::FIELDS['region_id']['placeholder']);
        $fields['region_id']['config']['elementTmpl'] = 'ui/form/element/select';

        unset(
            $fields['region_id']['filterBy'],
            $fields['region_id']['imports'],
            $fields['region_id']['config']['customEntry']
        );
    }

    /**
     * The configured country's regions, as UI-select options.
     *
     * MOVED OUT (2026-09-02) to Spartrak\CustomerAddress\Model\GovernorateOptions.
     * The account address form needs the identical list — same country, same
     * fallback for a region with no name in the current locale — and two copies
     * of it would disagree the first time the configured country changed. That
     * model carries the reasoning; this stays as a one-line delegate so the
     * call site above still reads as it did.
     *
     * @param string $country
     * @return array<int, array{value: string, label: string}>
     */
    private function getGovernorateOptions(string $country): array
    {
        return $this->governorateOptions->toOptionArray($country);
    }

    /**
     * Core hangs a "For delivery questions." help bubble off the telephone
     * field - see LayoutProcessor::getAddressAttributes, which is the only
     * field in the form given one.
     *
     * Figma draws no such control anywhere on this form, and it is not merely
     * decorative clutter: it renders a `tabindex="0"` button and boots a
     * mageInit dropdown widget, so it adds a keyboard stop and a JS instance to
     * a form with nothing to explain.
     *
     * Unset rather than hidden in CSS, because hiding it would leave both the
     * tab stop and the widget exactly where they are. Every field is swept, not
     * just telephone, so a tooltip added to another attribute later does not
     * reappear on its own.
     *
     * @param array<string, mixed> $fields
     */
    private function removeTooltips(array &$fields): void
    {
        foreach ($fields as &$field) {
            if (is_array($field) && isset($field['config']['tooltip'])) {
                unset($field['config']['tooltip']);
            }
        }

        // $field is still bound to the last element by reference; leaving it
        // that way is how the next foreach over the same array silently
        // overwrites it.
        unset($field);
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
                /**
                 * ===========================================================
                 * THE COMPONENT IS SWAPPED, NOT JUST THE TEMPLATE
                 * ===========================================================
                 * This used to set `elementTmpl` alone and leave the line on
                 * core's `Magento_Ui/js/form/element/abstract`. That template
                 * (ui/form/element/textarea.html) binds
                 *
                 *     attr: { name: inputName, cols: cols, rows: rows, ... }
                 *
                 * and `abstract` has neither `cols` nor `rows`. `rows` was
                 * supplied here; `cols` was not, so Knockout threw
                 *
                 *     Unable to process binding "attr: ..."
                 *     cols is not defined
                 *
                 * The throw happened inside `foreach: getRegion(
                 * 'additional-fieldsets')`, which aborts the whole loop — so
                 * every field AFTER the street textarea stopped rendering. That
                 * is why the add/edit address dialog came up half-built, with
                 * the mobile-number fields missing and empty message strips
                 * where their markup should have been, and why returning to the
                 * shipping tab (which re-renders the form) failed outright.
                 *
                 * `Magento_Ui/js/form/element/textarea` is `abstract` plus
                 * exactly the three defaults the template wants — cols, rows and
                 * that same elementTmpl. Naming the component supplies all
                 * three, so there is no list of properties here to keep in step
                 * with a template this module does not own.
                 */
                $line['component'] = 'Magento_Ui/js/form/element/textarea';
                // Figma 721:29511 draws a 120px-tall box; the width is the
                // grid's, so `cols` is left at the component's own default.
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
