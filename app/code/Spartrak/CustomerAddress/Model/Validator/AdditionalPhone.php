<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAddress\Model\Validator;

use Magento\Customer\Model\Address\AbstractAddress;
use Magento\Customer\Model\Address\ValidatorInterface;
use Spartrak\CustomerAuth\Model\Phone\Normalizer;

/**
 * `رقم اضافي` has to be a phone number, or absent. Nothing in between.
 *
 * ===========================================================================
 * WHAT WAS WRONG: THE FIELD WAS NEVER CHECKED BY ANYTHING
 * ===========================================================================
 * Measured on the deployed store, not inferred. The address form's own markup
 * carries no rule for it —
 *
 *     telephone         required  data-validate="{'required':true}"
 *     additional_phone  -         -
 *
 * — and the form fragment the account dialog loads emits no `data-mage-init`
 * at all, so Magento's validation widget never initialised on it and even
 * those attributes were inert. On the server, `additional_phone` is registered
 * with `validate_rules {"max_text_length":32}`, and
 * Customer\Model\Metadata\Form\Text::validateLength() only applies a length
 * rule when an `input_validation` rule is ALSO present — which it is not. So
 * the value took any 32 characters at all: a name, an email, half a sentence.
 *
 * It reached the database intact, which is why this reads as "not handled in
 * the backend" rather than as data loss: the column was written faithfully
 * with whatever arrived.
 *
 * ===========================================================================
 * THE SAME RULE AS THE SHOPPER'S OWN MOBILE NUMBER, MINUS "REQUIRED"
 * ===========================================================================
 * There is exactly one definition of a usable phone number on this store, and
 * it is Spartrak\CustomerAuth\Model\Phone\Normalizer — the class that guards
 * sign-up, sign-in and the phone-change OTP, where the number IS the account
 * identity. It folds Arabic-Indic and Persian digits to Latin, understands the
 * trunk prefix (`01…`), `+20…` and `0020…` alike, and then requires an
 * Egyptian mobile NSN (`1[0125]` + eight digits) because the number has to be
 * reachable.
 *
 * Reusing it is the whole point (CLAUDE.md section 9): a second regex here
 * would be a second answer to "what is a valid number on this store", and the
 * two would drift the first time a carrier prefix is added. `normalizeOrNull()`
 * is the read-only form of that rule — it returns null instead of throwing, so
 * this validator can report its own message rather than surface an
 * authentication-flow one.
 *
 * OPTIONAL IS ENFORCED BY THE FIRST GUARD, NOT BY THE RULE. An empty value
 * returns no errors and never reaches the normalizer, which would reject it
 * ("Please enter your phone number."). Figma draws this field without an
 * asterisk (557:5173) and the template has neither `required` nor a required
 * rule; this class must agree with both.
 *
 * ===========================================================================
 * WHY A ValidatorInterface AND NOT A PLUGIN OR AN OBSERVER
 * ===========================================================================
 * `Magento\Customer\Model\Address\CompositeValidator` is where Magento keeps
 * this kind of rule, and it is reached from `AbstractAddress::validate()` —
 * which BOTH address models inherit:
 *
 *   customer address  AddressRepository::save() validates before it saves, so
 *                     every writer is covered at once: the account dialog
 *                     (core's customer/address/formPost), the checkout's
 *                     Spartrak\Checkout\Controller\Address\Save, the admin
 *                     customer form and the REST API.
 *   quote address     Quote\Model\QuoteValidator::validateBeforeSubmit() calls
 *                     validate() on the shipping and billing addresses, so a
 *                     number posted straight at the shipping-information
 *                     endpoint cannot slip past the storefront.
 *
 * A plugin on the repository would have missed the quote; an observer on
 * `*_save_before` would have fired after the repository had already validated
 * (the sequencing trap recorded in full on
 * Plugin\FillCityOnAddressRepositorySave).
 *
 * The message names the field in the shopper's own words and gives an example,
 * because "invalid format" tells somebody nothing about what to type.
 */
class AdditionalPhone implements ValidatorInterface
{
    private const ATTRIBUTE_CODE = 'additional_phone';

    public function __construct(
        private readonly Normalizer $normalizer
    ) {
    }

    /**
     * @param AbstractAddress $address
     * @return array<int, \Magento\Framework\Phrase>
     */
    public function validate(AbstractAddress $address)
    {
        $value = $this->read($address);

        // Absent is correct. This is the one optional field on the form.
        if ($value === '') {
            return [];
        }

        if ($this->normalizer->normalizeOrNull($value) === null) {
            return [
                __(
                    'Please enter a valid Egyptian mobile number for the additional number, '
                    . 'for example 01012345678, or leave it empty.'
                ),
            ];
        }

        return [];
    }

    /**
     * Read the value from wherever this particular address is carrying it.
     *
     * Two places, because the two address models store it differently and this
     * validator runs against both:
     *
     *   quote address     posted by the checkout as
     *                     `custom_attributes: {additional_phone: "…"}`, and only
     *                     moved onto the flat column later, by
     *                     Observer\FlattenAdditionalPhone on
     *                     `sales_quote_address_save_before` — which is AFTER
     *                     validation. So at this moment the custom attribute is
     *                     the only copy.
     *   customer address  a real EAV attribute, flattened onto the model by
     *                     Address::updateData() before AddressRepository
     *                     validates, so `getData()` has it.
     *
     * Checked in that order and not the reverse: when both are present the
     * custom attribute is the one that came from this request.
     */
    private function read(AbstractAddress $address): string
    {
        $attribute = $address->getCustomAttribute(self::ATTRIBUTE_CODE);

        if ($attribute !== null) {
            $value = $attribute->getValue();

            return is_scalar($value) ? trim((string) $value) : '';
        }

        $value = $address->getData(self::ATTRIBUTE_CODE);

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
