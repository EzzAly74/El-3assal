/**
 * Spartrak_Checkout — two mixins, both extending core in place.
 *
 * Neither replaces a core module and neither adds a request: a mixin is merged
 * into the same bundle as its target.
 *
 *   view/shipping
 *       gains `spartrakMode`, so the theme's shipping.html can show the address
 *       book on the delivery segment and the location list on the pickup ones.
 *
 *   shipping-save-processor/payload-extender
 *       adds the chosen pickup location to the shipping-information request.
 *       This is the hook core provides for exactly this - its own
 *       implementation does nothing but create the empty extension_attributes
 *       object that modules are meant to fill.
 */
var config = {
    config: {
        mixins: {
            'Magento_Checkout/js/view/shipping': {
                'Spartrak_Checkout/js/view/shipping-mixin': true
            },
            'Magento_Checkout/js/model/shipping-save-processor/payload-extender': {
                'Spartrak_Checkout/js/model/payload-extender-mixin': true
            }
        }
    }
};
