/**
 * Spartrak_Checkout — mixins only. Every one extends a core component in
 * place; none replaces a core module, and none adds a request, because a mixin
 * is merged into the same bundle as its target.
 *
 *   NOT HERE: a confirm for the cart's `مسح الكل` button. One was added and
 *       then removed, because Magento_Checkout/js/shopping-cart ALREADY asks —
 *       `_create` binds the empty-cart button straight to `_confirmClearCart()`,
 *       which opens Magento_Ui/js/modal/confirm. The mixin produced a second
 *       dialog behind the first. The only thing that was actually missing was
 *       an ar_EG translation for core's own sentence; it is in the theme's
 *       i18n/ar_EG.csv.
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
 *       REGISTERED AGAINST TWO TARGETS, AND BOTH ARE NEEDED. Magento_Tax
 *       replaces the checkout's subtotal component with its own, and that one
 *       extends `summary/abstract-total` DIRECTLY — it is a sibling of
 *       Magento_Checkout's subtotal, not a subclass of it. So a mixin on the
 *       Magento_Checkout module alone never reached the component the page
 *       actually instantiates, and `spartrakItemCount` was undefined: the label
 *       rendered as a bare `المجموع الفرعي` with no count. Naming both keeps
 *       the count whether or not Magento_Tax is enabled, and the two are
 *       different modules so neither is extended twice.
 *
 *   view/summary/shipping
 *       exposes whether the shipping charge is actually zero, so the row can be
 *       painted green. It asks the total, not the carrier — see the mixin.
 *
 *       ONE target is enough here, unlike the subtotal above:
 *       Magento_Tax's shipping component extends Magento_Checkout's, so it
 *       inherits the mixin. Listing it a second time would apply the extend
 *       twice for no gain.
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
            'Magento_Tax/js/view/checkout/summary/subtotal': {
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
