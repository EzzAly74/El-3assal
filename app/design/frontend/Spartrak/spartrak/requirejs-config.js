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
            spartrakToast: 'js/spartrak-toast'
        }
    },
    /*
     * Both mixins serve one thing Figma's cart drawer asks for and core's
     * minicart does not do: a quantity DROPDOWN with no Update button beside
     * it (820:16477).
     *
     *   view/minicart  builds the option list, and keeps the line's own
     *                  quantity in it when it sits above the configured cap.
     *   js/sidebar     commits on change, instead of revealing a button that
     *                  the design does not draw.
     *
     * Both extend core in place. Neither replaces a core module, and neither
     * adds a request — a mixin is merged into the same bundle as its target.
     */
    config: {
        mixins: {
            'Magento_Checkout/js/view/minicart': {
                'js/spartrak-minicart-view-mixin': true
            },
            'Magento_Checkout/js/sidebar': {
                'js/spartrak-minicart-qty-mixin': true
            }
        }
    }
};
