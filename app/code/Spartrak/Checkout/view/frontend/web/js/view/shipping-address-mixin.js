/**
 * Spartrak - makes the checkout's address form save to the customer's address
 * book, and makes `تعديل` edit the address it belongs to.
 *
 * ===========================================================================
 * WHAT CORE DOES, AND WHY IT IS NOT ENOUGH
 * ===========================================================================
 * Core's `saveNewAddress()` does not persist anything. It converts the form
 * into a quote address, selects it, and closes the modal; the address reaches
 * the customer's book later, when the ORDER is placed, because
 * `save_in_address_book` was set.
 *
 * That is fine for adding. It cannot express editing at all: there is no id in
 * play, so saving an existing address through it produces a SECOND address that
 * happens to look like the first. Core is honest about this - a customer
 * address reports `isEditable() === false`, which is why core hides the edit
 * button that Figma draws on every card.
 *
 * So the save is redirected: for a signed-in shopper it goes to
 * Spartrak\Checkout\Controller\Address\Save, which writes through
 * AddressRepositoryInterface, and the checkout is then refreshed from what the
 * server actually stored.
 *
 * ===========================================================================
 * WHY OVERRIDING saveNewAddress() IS THE RIGHT SEAM
 * ===========================================================================
 * Core builds the modal's buttons once, into a module-scoped `popUp`, with
 * `click: self.saveNewAddress.bind(self)`. `bind` resolves the method off the
 * INSTANCE, and this mixin is part of that instance - so overriding the method
 * captures the button without touching the modal, its buttons, its labels or
 * its responsive behaviour. The desktop modal and the mobile bottom sheet are
 * that same modal at two widths, so both are covered by this one override.
 *
 * ===========================================================================
 * GUESTS
 * ===========================================================================
 * A guest has no address book, and this must not pretend otherwise. When
 * nobody is signed in, `_super()` runs and core's original behaviour is exactly
 * what happens - the address lives on the quote and nothing is persisted.
 */
define([
    'jquery',
    'ko',
    'underscore',
    'uiRegistry',
    'mage/translate',
    'Magento_Customer/js/model/customer',
    'Magento_Customer/js/model/address-list',
    'Magento_Checkout/js/model/address-converter',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Checkout/js/checkout-data',
    'Spartrak_Checkout/js/model/address-book'
], function (
    $,
    ko,
    _,
    registry,
    $t,
    customer,
    addressList,
    addressConverter,
    fullScreenLoader,
    checkoutData,
    addressBook
) {
    'use strict';

    return function (Shipping) {
        return Shipping.extend({

            /** @inheritdoc */
            initialize: function () {
                this._super();

                /**
                 * =======================================================
                 * ONE WAY TO ADD AN ADDRESS, AND IT IS THE POP-UP
                 * =======================================================
                 * Core computes `isFormInline` as `addressList().length === 0`
                 * and uses it for two things: which rendering of the address
                 * form the template shows, and - in
                 * `validateShippingInformation()` - whether advancing to
                 * payment should validate that form first.
                 *
                 * Both are wrong for this storefront, and the second one was a
                 * bug a shopper could not get past.
                 *
                 * THE RENDERING. Figma's empty address book (557:4898) draws an
                 * illustration, a sentence and ONE button, which opens the
                 * modal. Core's flag additionally printed the full six-field
                 * form into the page underneath all three and hid the "new
                 * address" button - two competing ways to do the same thing, on
                 * a shopper's first order.
                 *
                 * THE VALIDATION. `validateShippingInformation()` branches on
                 * this flag, so with no saved address it validated the inline
                 * form before advancing. On a PICKUP segment
                 * (`استلام من الفرع` / `استلام من الموقف`) Figma draws no
                 * address form at all - the shipping address is synthesised
                 * from the chosen location by
                 * Spartrak\PickupLocation\Plugin\Checkout\ApplyPickupLocation -
                 * so core validated fields the design had replaced, found them
                 * empty, called `focusInvalid()` on a form that was not on
                 * screen, and returned false. The CTA looked dead and said
                 * nothing. With the flag false, that method takes its other
                 * branch: it requires a shipping METHOD, which every segment
                 * sets, and leaves the address to the quote.
                 *
                 * Pinned here rather than conditioned around in the template
                 * because it is ONE fact - "this checkout has a single,
                 * pop-up address form" - and it has to be true for core's own
                 * code as well as for ours. A template-only fix would have
                 * hidden the inline form and left the validation branch behind
                 * it.
                 *
                 * Assigned, not observable: core declares it as a plain
                 * property on `defaults` and reads it synchronously in
                 * `validateShippingInformation()`. Making it observable here
                 * would hand core a function where it expects a boolean, and
                 * `if (function)` is always true - the exact bug this removes.
                 */
                this.isFormInline = false;

                /**
                 * The id of the address currently open in the form, or null
                 * when adding. This single value is what makes one form, one
                 * modal and one save handler serve both operations.
                 */
                this.spartrakEditingAddressId = ko.observable(null);

                /**
                 * Figma's `العنوان الافتراضي` toggle (557:10445).
                 *
                 * DELIBERATELY SEPARATE FROM saveInAddressBook. They answer
                 * different questions - "keep this for next time" versus "send
                 * here by default" - and core's own `saveInAddressBook` property
                 * is left exactly as it was, at its default of 1.
                 */
                this.spartrakIsDefaultShipping = ko.observable(false);

                this.spartrakSaveError = ko.observable(null);

                return this;
            },

            /**
             * Open the form to ADD an address.
             *
             * Overridden to clear the edit state first. Without this, opening
             * "new address" straight after an edit would still carry the
             * previous id and would overwrite that address instead of creating
             * one - the single worst bug this feature could have.
             */
            showFormPopUp: function () {
                this.spartrakEditingAddressId(null);
                this.spartrakIsDefaultShipping(false);
                this.spartrakSaveError(null);
                this.spartrakResetForm();
                this.spartrakSyncTitle();

                this._super();
            },

            /**
             * Tell the dialog which of its two jobs it is doing.
             *
             * The modal is built once, from the layout's static title, so
             * without this an edit would open under "Add a new shipping
             * address" - and a shopper reading that heading would reasonably
             * expect a second address to appear.
             *
             * `setTitle` is the modal widget's own API, so the heading element,
             * its markup and its aria wiring stay the widget's business.
             */
            spartrakSyncTitle: function () {
                var popUp = this.getPopUp();

                if (popUp && typeof popUp.setTitle === 'function') {
                    popUp.setTitle(this.spartrakFormTitle());
                }

                this.spartrakSyncSaveLabel();
            },

            /**
             * Relabel the dialog's save button for what it is about to do.
             *
             * ===================================================================
             * WHY THIS POKES THE DOM
             * ===================================================================
             * Core builds the modal's buttons ONCE, into a module-scoped `popUp`,
             * from the static text in the layout - and the modal widget exposes
             * `setTitle` but nothing equivalent for a button. There is no API to
             * ask.
             *
             * The alternative was leaving it reading `اضف عنوان جديد` ("add a new
             * address") while editing one, which is what it did: the heading said
             * edit, the button said add, and a shopper reasonably expected a
             * second address to appear.
             *
             * Scoped to this dialog by the class the layout puts on the button,
             * so it cannot reach any other button on the page.
             */
            spartrakSyncSaveLabel: function () {
                var label = this.spartrakEditingAddressId()
                        ? $t('Save changes')
                        : $t('Add a new address'),
                    $button = $('.spartrak-address-modal__save');

                // The span is core's own markup; writing to it rather than the
                // button keeps whatever else the widget put inside.
                if ($button.length) {
                    ($button.find('span').first().length ? $button.find('span').first() : $button).text(label);
                }
            },

            /**
             * Open the form to EDIT a saved address.
             *
             * Called by the address card's `تعديل` control - see
             * js/view/address-renderer-mixin.js.
             *
             * @param {Object} address a Magento_Customer/js/model/customer/address
             */
            spartrakEditAddress: function (address) {
                var self = this;

                if (!address || !address.customerAddressId) {
                    // An address typed during this checkout has no id yet.
                    // Core's own behaviour - reopen the form on it - is right.
                    this._super && this._super();

                    return;
                }

                this.spartrakEditingAddressId(address.customerAddressId);
                this.spartrakIsDefaultShipping(
                    typeof address.isDefaultShipping === 'function' ? !!address.isDefaultShipping() : false
                );
                this.spartrakSaveError(null);

                /**
                 * CAPTURED BEFORE THE PREFILL OVERWRITES IT.
                 *
                 * Core watches the form provider and mirrors anything with a
                 * non-empty street into checkout-data - the browser-side draft
                 * of an address the shopper was part-way through typing. Filling
                 * the form with a SAVED address therefore clobbers that draft,
                 * and cancelling would not bring it back: core's own revert
                 * snapshots on modal-open, which happens after this prefill, so
                 * it would restore the prefill.
                 *
                 * Keeping our own copy is what lets onClosePopUp put the
                 * shopper's half-typed address back if they change their mind.
                 */
                this.spartrakDraftBeforeEdit = $.extend(true, {}, checkoutData.getShippingAddressFromData());

                registry.async('checkoutProvider')(function (checkoutProvider) {
                    /**
                     * `quoteAddressToFormAddressData` is core's own converter:
                     * camelCase to snake_case, the street array into the indexed
                     * object the multiline field wants, and customAttributes
                     * into a map keyed by attribute code - which is how
                     * `additional_phone` reaches its field.
                     *
                     * Prefilling any other way would mean re-deriving all three
                     * of those shapes by hand.
                     */
                    checkoutProvider.set(
                        'shippingAddress',
                        self.spartrakBlankNulls(
                            addressConverter.quoteAddressToFormAddressData(address)
                        )
                    );
                });

                this.spartrakSyncTitle();
                this.isFormPopUpVisible(true);
            },

            /**
             * Replace nulls in the prefill with empty strings.
             *
             * ===================================================================
             * WITHOUT THIS, SAVE SILENTLY DOES NOTHING
             * ===================================================================
             * Diagnosed in the browser against a real saved address. The
             * converter above faithfully reports "this address has no company"
             * as `company: null` - along with email, fax, postcode, middlename,
             * prefix, suffix and vat_id, ten fields in all.
             *
             * Magento's `min_text_length` / `max_text_length` rules then read
             * `value.length` off that null:
             *
             *     TypeError: Cannot read properties of null (reading 'length')
             *
             * and Magento_Ui/js/form/element/abstract::validate() runs the
             * validator BEFORE it checks `visible()`, so being hidden does not
             * spare the field. The throw escapes triggerShippingDataValidateEvent(),
             * which saveNewAddress() calls before anything else - so the method
             * dies there, addressBook.save() is never reached, no request is
             * made, and no message is shown. The button looks dead.
             *
             * Verified: with `company` coerced to '' the field validates and the
             * whole form's validation runs to completion instead of throwing.
             *
             * `same_as_billing` and `save_in_address_book` are deliberately left
             * alone. They are flags, not text - nothing validates them, and
             * blanking `save_in_address_book` would read as falsy and stop the
             * address being written to the book at all.
             *
             * @param {Object} data
             * @return {Object}
             */
            spartrakBlankNulls: function (data) {
                var skip = { 'same_as_billing': true, 'save_in_address_book': true };

                _.each(data, function (value, key) {
                    if (skip[key]) {
                        return;
                    }

                    if (value === null || value === undefined) {
                        data[key] = '';

                        return;
                    }

                    // street arrives as an indexed object and custom_attributes
                    // as a map keyed by attribute code; both can carry the same
                    // nulls, and `additional_phone` lives in the second one.
                    if (_.isObject(value) && !_.isArray(value)) {
                        this.spartrakBlankNulls(value);
                    }
                }, this);

                return data;
            },

            /**
             * Cancel.
             *
             * When an edit is abandoned the shopper's own draft goes back -
             * both into checkout-data and into the form provider - so a
             * half-typed new address survives someone opening an existing one to
             * look at it. Core's revert cannot do this; see the snapshot taken
             * in spartrakEditAddress for why.
             *
             * Adding is left entirely to core: there is no prefill to undo.
             */
            onClosePopUp: function () {
                var draft;

                if (!this.spartrakEditingAddressId()) {
                    this._super();

                    return;
                }

                draft = this.spartrakDraftBeforeEdit || {};
                checkoutData.setShippingAddressFromData($.extend(true, {}, draft));

                registry.async('checkoutProvider')(function (checkoutProvider) {
                    checkoutProvider.set('shippingAddress', $.extend(true, {}, draft));
                });

                this.spartrakEditingAddressId(null);
                this.spartrakIsDefaultShipping(false);
                this.spartrakSaveError(null);
                this.getPopUp().closeModal();
            },

            /**
             * Empty the form between uses.
             *
             * The provider keeps whatever was last typed, so without this an
             * "add new address" opened after an edit would arrive prefilled
             * with the edited address - and a shopper who pressed save would
             * create a duplicate believing they had added something new.
             */
            spartrakResetForm: function () {
                var self = this;

                registry.async('checkoutProvider')(function (checkoutProvider) {
                    var current = checkoutProvider.get('shippingAddress') || {},
                        blank = {};

                    _.each(current, function (value, key) {
                        blank[key] = _.isObject(value) && !_.isArray(value) ? {} : '';
                    });

                    checkoutProvider.set('shippingAddress', blank);

                    /**
                     * =========================================================
                     * CLEARING THE VALIDATION THE BLANKING JUST CAUSED
                     * =========================================================
                     * Writing '' into a UI form element runs its own validate()
                     * through the value subscription, and every required field
                     * fails that check the moment it is emptied. The result was
                     * that "add a new address" opened with
                     * "This is a required field." already printed under the
                     * first name, last name and street — before the shopper had
                     * typed a character.
                     *
                     * Measured on the live checkout: zero fields in error before
                     * showFormPopUp(), three immediately after.
                     *
                     * The values genuinely do need clearing (see above — a stale
                     * prefill makes an "add" silently overwrite an edit), so the
                     * fix is to reset the error state that clearing produced,
                     * not to stop clearing. `params.invalid` goes with it, since
                     * saveNewAddress() reads that flag to decide whether to
                     * submit and a stale true would block the first save.
                     */
                    registry.filter(function (component) {
                        return component &&
                            component.dataScope &&
                            String(component.dataScope).indexOf('shippingAddress') === 0 &&
                            typeof component.error === 'function';
                    }).forEach(function (component) {
                        component.error(false);
                    });

                    self.source.set('params.invalid', false);
                });
            },

            /**
             * Save the form - creating or updating, depending on the id.
             *
             * @return {void}
             */
            saveNewAddress: function () {
                var self = this,
                    addressId = this.spartrakEditingAddressId(),
                    formData,
                    wasSelected;

                // Guests keep core's behaviour, exactly. See the class header.
                if (!customer.isLoggedIn()) {
                    this._super();

                    return;
                }

                this.source.set('params.invalid', false);
                this.triggerShippingDataValidateEvent();

                if (this.source.get('params.invalid')) {
                    // Core's own field-level messages are already on screen.
                    return;
                }

                formData = this.source.get('shippingAddress');
                // Asked BEFORE the save: if the shopper is editing the address
                // they are currently shipping to, the quote has to be pointed at
                // the new values afterwards. If they are editing a different
                // one, silently switching them onto it would be worse than
                // doing nothing.
                wasSelected = !addressId || addressBook.isSelected(addressId);

                this.spartrakSaveError(null);
                fullScreenLoader.startLoader();

                addressBook.save(formData, addressId, this.spartrakIsDefaultShipping())
                    .done(function (response) {
                        if (!response || !response.success) {
                            self.spartrakSaveError(
                                (response && response.message) || addressBook.genericErrorMessage()
                            );

                            return;
                        }

                        self.spartrakApplySaved(response, wasSelected);
                    })
                    .fail(function (xhr) {
                        var payload = xhr && xhr.responseJSON;

                        self.spartrakSaveError(
                            (payload && payload.message) || addressBook.genericErrorMessage()
                        );
                    })
                    .always(function () {
                        fullScreenLoader.stopLoader();
                    });
            },

            /**
             * Put the server's answer back into the checkout.
             *
             * @param {Object} response
             * @param {Boolean} shouldSelect
             */
            spartrakApplySaved: function (response, shouldSelect) {
                var items = addressBook.refresh(response.addresses),
                    saved = addressBook.find(items, response['address_id']);

                if (shouldSelect && saved) {
                    addressBook.reselect(saved);
                }

                // Core keeps its own flag for whether a new address exists.
                //
                // `isFormInline` is NOT recomputed here any more: it is pinned
                // to false for the life of the component (see initialize), so
                // recomputing it from the list would be the one place that
                // could put the inline form back - on the very request that
                // added the first address.
                this.isNewAddressAdded(true);
                this.spartrakRevealList();

                this.spartrakEditingAddressId(null);
                this.spartrakIsDefaultShipping(false);
                this.getPopUp().closeModal();
            },

            /**
             * Make sure the address list is visible once there is one.
             *
             * ===================================================================
             * THE FIRST ADDRESS IS A SPECIAL CASE
             * ===================================================================
             * Core's list component sets `visible: addressList().length > 0` in
             * its DEFAULTS - evaluated once, when the component is constructed.
             * On a checkout that opened with an empty address book that value is
             * `false`, and its own subscription only ever adds renderers; it
             * never revisits whether the list should be shown at all.
             *
             * So the very first address a shopper adds would save, appear in the
             * data, and render into a container that is still hidden. Every
             * subsequent address would work, which is exactly the kind of bug
             * that survives testing.
             *
             * The theme's own empty-state switch is driven separately, off
             * `spartrakHasAddresses`, and is already correct.
             */
            spartrakRevealList: function () {
                if (!addressList().length) {
                    return;
                }

                registry.async('checkout.steps.shipping-step.shippingAddress.address-list')(function (list) {
                    if (!list) {
                        return;
                    }

                    if (ko.isObservable(list.visible)) {
                        list.visible(true);
                    } else {
                        list.visible = true;
                    }
                });
            },

            /**
             * The modal's title changes with what it is doing - Figma's
             * `اضافة عنوان شحن جديد` when adding, and the edit equivalent when
             * amending an existing address.
             *
             * @return {String}
             */
            spartrakFormTitle: function () {
                return this.spartrakEditingAddressId()
                    ? $t('Edit shipping address')
                    : $t('Add a new shipping address');
            }
        });
    };
});
