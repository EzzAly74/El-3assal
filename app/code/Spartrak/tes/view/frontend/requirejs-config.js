/**
 * Spartrak_Catalog — maps widget names used in data-mage-init attributes to
 * their real module paths, same mechanism as each theme's own
 * requirejs-config.js (see that file's comment). Declared here, at the
 * module level, so it's collected for BOTH spartrak and spartrak_rtl
 * automatically (Magento\Framework\RequireJs\Config\File\Collector\
 * Aggregated collects every module's own requirejs-config.js for every
 * theme, independent of theme parentage) — no per-theme duplication needed
 * for JS, unlike LESS/PHTML/layout XML (10-THEME-ARCHITECTURE.md).
 */
var config = {
    map: {
        '*': {
            spartrakHeroCarousel: 'Spartrak_Catalog/js/hero-carousel'
        }
    }
};
