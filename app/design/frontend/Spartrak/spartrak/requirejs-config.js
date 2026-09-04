/**
 * Spartrak Phase 4 — maps the bare widget names used in each component's
 * data-mage-init attribute to their real module paths, so Magento's native
 * mage/apply/main.js dataAttr initializer can require() and instantiate
 * them. No new JS libraries — jQuery + the jQuery UI widget factory only,
 * both already core Magento dependencies (10-THEME-ARCHITECTURE.md JS
 * architecture rule 1).
 */
var config = {
    map: {
        '*': {
            spartrakUtilityHeader: 'js/spartrak-utility-header',
            spartrakMegaNav: 'js/spartrak-mega-nav',
            spartrakMobileDrawer: 'js/spartrak-mobile-drawer',
            spartrakAuth: 'js/spartrak-auth',
            spartrakPlpFilterToggle: 'js/spartrak-plp-filter-toggle',
            spartrakSearchSuggest: 'js/spartrak-search-suggest',
            spartrakHomeCarousel: 'js/spartrak-home-carousel',
            spartrakHomeTiles: 'js/spartrak-home-tiles',
            spartrakHomeVideo: 'js/spartrak-home-video',
            spartrakCascadeSearch: 'js/spartrak-cascade-search',
            spartrakQuickSearch: 'js/spartrak-quick-search',
            spartrakToast: 'js/spartrak-toast',
            spartrakCartQty: 'js/spartrak-cart-qty',
            spartrakReviewDialog: 'js/spartrak-review-dialog'
        }
    },
    /*
     * Both mixins serve one thing Figma's cart drawer asks for and core's
     * minicart does not do: a quantity DROPDOWN with no Update button beside
     * it (820:16477).
     *
     *   view/cart-item-renderer
     *                  builds the option list, and keeps the line's own
     *                  quantity in it when it sits above the configured cap.
     *   js/sidebar     commits on change, instead of revealing a button that
     *                  the design does not draw.
     *
     * The FIRST one is on cart-item-renderer and not on view/minicart, which is
     * a correction. `$parent` inside Magento_Checkout/minicart/item/default is
     * the ITEM RENDERER, not the minicart view: content.html renders each line
     * through `<each args="$parent.getRegion(...)" render="{data: item}">`, so
     * the template's own parent context is the renderer element that `each` is
     * iterating. That is why core's item template calls
     * `$parent.getProductNameUnsanitizedHtml()` — a cart-item-renderer method —
     * while reaching the minicart view as `$parents[1]`.
     *
     * Both extend core in place. Neither replaces a core module, and neither
     * adds a request — a mixin is merged into the same bundle as its target.
     */
    config: {
        mixins: {
            'Magento_Checkout/js/view/cart-item-renderer': {
                'js/spartrak-minicart-qty-options-mixin': true
            },
            'Magento_Checkout/js/sidebar': {
                'js/spartrak-minicart-qty-mixin': true
            },
            /*
             * Guest checkout is disabled, so core asks a signed-out shopper to
             * sign in before checkout - and it does that with MAGENTO'S popup,
             * not this theme's modal. Both the minicart drawer
             * (Magento_Checkout/js/sidebar) and the cart page button
             * (proceed-to-checkout) reach it through this one model, so
             * wrapping showModal() here covers every caller at once instead of
             * patching each of them.
             */
            'Magento_Customer/js/model/authentication-popup': {
                'js/spartrak-auth-popup-mixin': true
            },
            'mage/gallery/gallery': {
                'js/spartrak-gallery-rtl-mixin': true
            }
        }
    }
};
