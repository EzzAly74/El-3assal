/**
 * Spartrak_Payment - one mixin, on the component every payment renderer
 * extends. See js/view/payment/method-row-mixin.js for why that is the right
 * seam and what the escape hatch is.
 */
var config = {
    config: {
        mixins: {
            'Magento_Checkout/js/view/payment/default': {
                'Spartrak_Payment/js/view/payment/method-row-mixin': true
            }
        }
    }
};
