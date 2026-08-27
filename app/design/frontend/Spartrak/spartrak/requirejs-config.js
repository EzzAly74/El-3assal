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
            spartrakForkRow: 'js/spartrak-fork-row',
            spartrakPlpFilterToggle: 'js/spartrak-plp-filter-toggle',
            spartrakSearchSuggest: 'js/spartrak-search-suggest',
            spartrakHomeCarousel: 'js/spartrak-home-carousel',
            spartrakHomeTiles: 'js/spartrak-home-tiles',
            spartrakHomeVideo: 'js/spartrak-home-video'
        }
    }
};
