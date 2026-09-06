/**
 * Spartrak RTL — the one RequireJS registration that is genuinely direction
 * specific.
 *
 * ===========================================================================
 * WHY THIS FILE EXISTS AT ALL
 * ===========================================================================
 * Almost nothing belongs here. `Spartrak/spartrak_rtl` is a CHILD of
 * `Spartrak/spartrak`, so every widget the parent theme maps is already
 * available on this store — duplicating any of them would be the exact drift
 * this theme's parent was changed to prevent (see theme.xml).
 *
 * The gallery mixin is the exception, and the reason is in its own name: it
 * REVERSES the product gallery's frame order so the carousel advances the way
 * the page reads. That is correct on an Arabic store and wrong on an English
 * one.
 *
 * It used to live in the parent theme and was therefore inherited by both,
 * which meant the English storefront's product gallery was silently reversed
 * as well — contradicting the mixin's own docblock, which claimed it only ever
 * loaded here. Registering it here is what finally makes that true.
 *
 * RequireJS merges `config.mixins` down the theme chain rather than replacing
 * it, so this adds one entry to the parent's three rather than shadowing them.
 */
var config = {
    config: {
        mixins: {
            'mage/gallery/gallery': {
                'js/spartrak-gallery-rtl-mixin': true
            }
        }
    }
};
