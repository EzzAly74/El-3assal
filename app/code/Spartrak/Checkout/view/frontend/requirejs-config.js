/**
 * Spartrak_Checkout — mixins only. Every one extends a core component in
 * place; none replaces a core module, and none adds a request, because a mixin
 * is merged into the same bundle as its target.
 *
 *   view/shipping  (TWO mixins, deliberately)
 *       `shipping-mixin` gains `spartrakMode` and `spartrakHasAddresses`, so
 *       the theme's shipping.html can pick between the address book, the empty
 *       state and the pickup lists.
 *
 *       `shipping-address-mixin` makes the address form save to the customer's
 *       ADDRESS BOOK and makes `تعديل` edit the address it belongs to instead
 *       of creating a duplicate.
 *
 *       They are separate files because they are separate concerns with
 *       separate reasons to change: one is about which panel is on screen, the
 *       other about how an address is persisted. RequireJS applies both, in the
 *       order listed.
 *
 *   shipping-save-processor/payload-extender
 *       adds the chosen pickup location to the shipping-information request.
 *       This is the hook core provides for exactly this — its own
 *       implementation does nothing but create the empty extension_attributes
 *       object that modules are meant to fill.
 *
 *   view/summary/subtotal
 *       puts the item count inside the subtotal label, as Figma writes it
 *       (`المجموع الفرعي (3 منتجات)`).
 *
 *   view/summary/shipping
 *       exposes whether the shipping charge is actually zero, so the row can be
 *       painted green. It asks the total, not the carrier — see the mixin.
 */
var config = {
    config: {
        mixins: {
            'Magento_Checkout/js/view/shipping': {
                'Spartrak_Checkout/js/view/shipping-mixin': true,
                'Spartrak_Checkout/js/view/shipping-address-mixin': true
            },
            'Magento_Checkout/js/model/shipping-save-processor/payload-extender': {
                'Spartrak_Checkout/js/model/payload-extender-mixin': true
            },
            'Magento_Checkout/js/view/summary/subtotal': {
                'Spartrak_Checkout/js/view/summary/subtotal-mixin': true
            },
            'Magento_Checkout/js/view/summary/shipping': {
                'Spartrak_Checkout/js/view/summary/shipping-mixin': true
            },
            'Magento_Checkout/js/view/shipping-address/address-renderer/default': {
                'Spartrak_Checkout/js/view/address-renderer-mixin': true
            }
        }
    }
};
