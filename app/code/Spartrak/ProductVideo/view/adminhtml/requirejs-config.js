/**
 * Spartrak Product Video — admin.
 *
 * A single mixin on Magento's video dialog. No new library, no new bundle, and
 * nothing added to any admin page other than the product form — a mixin is
 * merged into the module it extends, so this costs no extra request even
 * there.
 *
 * Declared at module level rather than in a theme because the admin has one
 * theme and this is module behaviour, not styling.
 */
var config = {
    config: {
        mixins: {
            'Magento_ProductVideo/js/new-video-dialog': {
                'Spartrak_ProductVideo/js/new-video-dialog-mixin': true
            }
        }
    }
};
