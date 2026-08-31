/**
 * Spartrak - the derived values the address card shows, and its selection.
 *
 * Every one of these could have been written inline in the template. They are
 * here instead because a Knockout template that computes is a template that
 * cannot be read: core's own address card is nine lines of
 * `_.values(_.compact(...))` and `<br>`, and it is genuinely hard to tell what
 * it prints. Naming each value once makes the card's markup say what it shows.
 */
define([
    'ko',
    'underscore',
    'uiRegistry',
    'Magento_Checkout/js/model/quote'
], function (ko, _, registry, quote) {
    'use strict';

    /**
     * Core's own name for the shipping component - the one that owns the
     * address form, its modal, and the save. Stable across 2.4.x.
     */
    var SHIPPING_COMPONENT = 'checkout.steps.shipping-step.shippingAddress';

    /**
     * The extra phone Figma adds to the address form (557:10405).
     *
     * It arrives as a custom attribute rather than a top-level field, because
     * that is how Magento carries an address attribute it did not ship itself.
     * Two shapes are possible depending on which side of the REST boundary the
     * address came from - an array of {attribute_code, value} objects, or a map
     * keyed by code - so both are handled. See Spartrak_CustomerAddress.
     *
     * @param {Object} address
     * @return {String}
     */
    function additionalPhoneOf(address) {
        var attributes = address.customAttributes;

        if (!attributes) {
            return '';
        }

        if (_.isArray(attributes)) {
            var match = _.find(attributes, function (attribute) {
                return attribute && attribute['attribute_code'] === 'additional_phone';
            });

            return match ? String(match.value || '') : '';
        }

        if (attributes['additional_phone']) {
            var entry = attributes['additional_phone'];

            return String(entry && entry.value !== undefined ? entry.value : entry);
        }

        return '';
    }

    return function (target) {
        return target.extend({

            /** @inheritdoc */
            initObservable: function () {
                this._super();

                /**
                 * Two-way binding for the radio group.
                 *
                 * READ returns the key of the address the quote currently ships
                 * to, so exactly one radio in the group reports itself checked -
                 * and it re-evaluates when the quote changes, including when
                 * something other than a click changed it (a pickup mode
                 * synthesising an address, a saved default resolving on load).
                 *
                 * WRITE runs when the shopper picks a different card, and hands
                 * off to core's own selectAddress(). Nothing about how an
                 * address is applied to the quote is reimplemented here.
                 */
                this.spartrakSelection = ko.computed({
                    read: function () {
                        var shipping = quote.shippingAddress();

                        return shipping ? shipping.getKey() : null;
                    },
                    write: function (key) {
                        if (key === this.address().getKey()) {
                            this.selectAddress();
                        }
                    },
                    owner: this
                });

                return this;
            },

            /**
             * Edit THIS address.
             *
             * ===================================================================
             * WHY IT DELEGATES INSTEAD OF OPENING THE FORM ITSELF
             * ===================================================================
             * Core's version opens the address modal and nothing more, which is
             * why editing a saved address in stock Magento produces a duplicate:
             * the form has no idea which address it is meant to be amending.
             *
             * The form, its modal and its save all belong to the shipping
             * component, so the address id is handed THERE - to
             * spartrakEditAddress, which prefills the fields, remembers the id,
             * and makes the save an update instead of an insert. A renderer
             * reaching into the form itself would be a second place that knows
             * how the form works.
             *
             * registry.async, not registry.get: the button can be pressed
             * before the shipping component has finished registering on a slow
             * connection, and async waits rather than failing on an undefined.
             *
             * @return {void}
             */
            editAddress: function () {
                var address = this.address();

                registry.async(SHIPPING_COMPONENT)(function (shipping) {
                    if (shipping && typeof shipping.spartrakEditAddress === 'function') {
                        shipping.spartrakEditAddress(address);
                    }
                });
            },

            /**
             * @return {String}
             */
            spartrakName: function () {
                var address = this.address();

                return _.compact([address.firstname, address.lastname]).join(' ');
            },

            /**
             * The whole address on one line, as Figma prints it:
             * `12 Tahrir Square, Downtown Cairo, Cairo Governorate 11511, Egypt`.
             *
             * Postcode is joined to the region with a space rather than a comma,
             * which is how a postal address is written; everything else is comma
             * separated. Empty parts drop out rather than leaving ", ," behind -
             * postcode in particular is legitimately blank for the pickup flows,
             * where the address is synthesised from a branch.
             *
             * @return {String}
             */
            spartrakAddressLine: function () {
                var address = this.address(),
                    regionAndPostcode = _.compact([address.region, address.postcode]).join(' ');

                return _.compact([
                    _.values(_.compact(address.street || [])).join(', '),
                    address.city,
                    regionAndPostcode,
                    this.getCountryName(address.countryId)
                ]).join(', ');
            },

            /**
             * @return {String}
             */
            spartrakAdditionalPhone: function () {
                return additionalPhoneOf(this.address());
            },

            /**
             * Whether this is the customer's default shipping address.
             *
             * Read defensively: an address typed during checkout has no default
             * flag at all, and the flag's name differs between the address
             * shapes Magento uses either side of the REST boundary.
             *
             * @return {Boolean}
             */
            spartrakIsDefault: function () {
                var address = this.address();

                if (typeof address.isDefaultShipping === 'function') {
                    return !!address.isDefaultShipping();
                }

                return !!(address.isDefaultShipping || address['default_shipping']);
            }
        });
    };
});
