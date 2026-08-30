<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAddress\Plugin;

use Magento\Checkout\Block\Checkout\LayoutProcessor;

/**
 * Re-points the checkout's additional-phone field at `custom_attributes`.
 *
 * ===========================================================================
 * THE FIELD APPEARS BY ITSELF; IT JUST POSTS TO THE WRONG PLACE
 * ===========================================================================
 * Adding `customer_register_address` to the attribute's used_in_forms is what
 * puts the field on the checkout at all - AttributeMerger builds the address
 * fieldset straight from address metadata, which is also why the desktop modal
 * and the mobile bottom sheet both get it with no template work.
 *
 * But AttributeMerger assigns every field a flat dataScope of
 * `<prefix>.<attributeCode>`, and Magento_Checkout/js/model/new-customer-address
 * maps a HARDCODED list of address properties onto the object it sends. That
 * list has no `additional_phone` in it, so a field at
 * `shippingAddress.additional_phone` is dropped by the browser before a request
 * is ever made - silently, with no console error and nothing in the payload.
 *
 * The one extension point that mapper does forward is
 * `customAttributes: addressData['custom_attributes']`. So the field is moved
 * under that key, which is the supported route for any non-core address field.
 * Spartrak\CustomerAddress\Plugin\AllowAdditionalPhoneOnQuoteAddress then makes
 * the quote address willing to accept it, and Observer\FlattenAdditionalPhone
 * lands it on the column.
 *
 * All three are required. Any one missing and the value disappears at a
 * different point in the chain, each time without an error.
 *
 * ===========================================================================
 * WHY IT ALSO SETS THE LABEL AND POSITION
 * ===========================================================================
 * Figma's add-address form (557:4731) places رقم اضافي directly beneath the
 * primary telephone field. sortOrder is set relative to core's telephone (which
 * AttributeMerger gives 130 by default from the attribute's own sort_order), so
 * the pair stays together whichever way the rest of the form is reordered.
 */
class ScopeAdditionalPhoneToCustomAttributes
{
    private const ATTRIBUTE_CODE = 'additional_phone';

    /**
     * Every address fieldset the checkout builds. Shipping and billing are
     * separate component trees with separate dataScopes, and a shopper may
     * enter a different second contact for each.
     *
     * The billing forms are keyed by payment method code at runtime, so they
     * are found by walking rather than by a fixed path.
     */
    public function afterProcess(LayoutProcessor $subject, array $jsLayout): array
    {
        $shippingPath = &$jsLayout['components']['checkout']['children']['steps']['children']
            ['shipping-step']['children']['shippingAddress']['children']['shipping-address-fieldset']
            ['children'];

        if (isset($shippingPath[self::ATTRIBUTE_CODE])) {
            $shippingPath[self::ATTRIBUTE_CODE] = $this->rescope(
                $shippingPath[self::ATTRIBUTE_CODE],
                'shippingAddress'
            );
        }

        $billingRoot = &$jsLayout['components']['checkout']['children']['steps']['children']
            ['billing-step']['children']['payment']['children']['payments-list']['children'];

        if (is_array($billingRoot)) {
            foreach ($billingRoot as $formKey => &$form) {
                $fields = &$form['children']['form-fields']['children'];

                if (isset($fields[self::ATTRIBUTE_CODE])) {
                    // The billing dataScope is the form's own key minus the
                    // '-form' suffix Magento appends - the same derivation
                    // AttributeMerger used when it built the fieldset.
                    $fields[self::ATTRIBUTE_CODE] = $this->rescope(
                        $fields[self::ATTRIBUTE_CODE],
                        'billingAddress' . $this->billingSuffix((string) $formKey)
                    );
                }

                unset($fields);
            }

            unset($form);
        }

        return $jsLayout;
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function rescope(array $field, string $prefix): array
    {
        $field['dataScope'] = $prefix . '.custom_attributes.' . self::ATTRIBUTE_CODE;
        $field['label'] = __('Additional phone');
        // Immediately after telephone, which AttributeMerger places at the
        // attribute's own sort_order.
        $field['sortOrder'] = 145;

        // Optional by design - a shopper with one number must not be blocked.
        $field['validation'] = array_merge(
            $field['validation'] ?? [],
            ['max_text_length' => 32]
        );

        return $field;
    }

    /**
     * `checkmo-form` -> `checkmo`, and a shared form -> ''.
     */
    private function billingSuffix(string $formKey): string
    {
        if (!str_ends_with($formKey, '-form')) {
            return '';
        }

        $method = substr($formKey, 0, -strlen('-form'));

        return $method === '' ? '' : ('-' . $method);
    }
}
