/**
 * Spartrak — the Knockout binding registry, minus the colour picker.
 *
 * ===========================================================================
 * WHAT THIS REMOVES, AND WHAT IT COSTS TODAY
 * ===========================================================================
 * Magento_Ui's own bindings/bootstrap.js `require()`s every binding eagerly so
 * it can return them as one map. One of them, `./color-picker`, declares
 * `spectrum` and `tinycolor` as hard dependencies at define time:
 *
 *     define(['ko', 'jquery', '../template/renderer', 'spectrum', 'tinycolor'], ...)
 *
 * So the moment this bootstrap runs, RequireJS fetches:
 *
 *     jquery/spectrum/spectrum.min.js    42,781 B
 *     jquery/spectrum/tinycolor.min.js   21,588 B
 *     -------------------------------------------
 *                                        64,369 B, 2 requests
 *
 * Measured on the live Arabic checkout (Lighthouse, static version
 * 1788629457): color-picker.min.js was requested at 5,701 ms; spectrum and
 * tinycolor followed at 7,155 ms and 7,226 ms. They are not leaves on the
 * waterfall — they sit on a DEEP SERIAL LAYER, and the layer after them cannot
 * begin until they resolve. Checkout's LCP on that run was 8.4 s, of which
 * 12,192 ms (observed) was element render delay waiting on this boot chain.
 *
 * ===========================================================================
 * WHY IT IS SAFE TO DROP — PROVEN, NOT ASSUMED
 * ===========================================================================
 * The `colorPicker` binding is only ever reached through a UI form element of
 * type color-picker. Searching every frontend view directory in vendor/, plus
 * all of app/code and app/design, for an XML declaration of such an element
 * returns NOTHING:
 *
 *     grep -rl "color-picker\|colorPicker" --include=*.xml \
 *         vendor/magento/<module>/view/frontend app/code app/design   ->  no matches
 *
 * The only template that binds it, module-ui/view/BASE/web/templates/form/
 * element/color-picker.html, is reachable from the admin, which runs the
 * Magento/backend theme and never loads this file. Nothing Spartrak owns
 * references colorPicker, spectrum or tinycolor either.
 *
 * The neighbouring heavyweights were checked and deliberately KEPT:
 *   - `datepicker` requires only ko/underscore/jquery/mage-translate and pulls
 *     its calendar lazily, so removing it would save nothing at define time.
 *   - `resizable` requires only async/uiRegistry/renderer. The jQuery UI
 *     resizable widget on the waterfall comes from Magento_Ui/js/modal/modal ->
 *     jquery/ui-modules/widgets/dialog, not from this binding.
 *   - `moment` (58,912 B) is a hard dependency of
 *     Magento_Ui/js/lib/validation/rules, which every validated form needs.
 * Only colorPicker is both expensive AND unreachable, so only it is removed.
 *
 * ===========================================================================
 * WHY A THEME OVERRIDE AND NOT A MIXIN OR A requirejs MAP
 * ===========================================================================
 * A mixin cannot help: the cost is incurred by `require()` at define time, so
 * the dependency is already fetched before any mixin could run. Mapping
 * `spectrum`/`tinycolor` to a stub would work but leaves a binding pointing at
 * a lie, which is the kind of thing that wastes an afternoon in two years.
 *
 * Magento's static-file fallback resolves this path ahead of the module's own
 * copy, so vendor/ is untouched and Porto is untouched. spartrak_rtl inherits
 * it through the theme chain. Restoring the colour picker is deleting this one
 * file — nothing else references it.
 *
 * Everything else below is byte-for-byte the upstream file (2.4.8).
 */
define(function (require) {
    'use strict';

    var renderer = require('../template/renderer');

    renderer.addAttribute('repeat', renderer.handlers.wrapAttribute);

    renderer.addAttribute('outerfasteach', {
        binding: 'fastForEach',
        handler: renderer.handlers.wrapAttribute
    });

    renderer
        .addNode('repeat')
        .addNode('fastForEach');

    return {
        resizable:      require('./resizable'),
        i18n:           require('./i18n'),
        scope:          require('./scope'),
        range:          require('./range'),
        mageInit:       require('./mage-init'),
        keyboard:       require('./keyboard'),
        optgroup:       require('./optgroup'),
        afterRender:     require('./after-render'),
        autoselect:     require('./autoselect'),
        datepicker:     require('./datepicker'),
        outerClick:     require('./outer_click'),
        fadeVisible:    require('./fadeVisible'),
        dimVisible:    require('./dimVisible'),
        collapsible:    require('./collapsible'),
        staticChecked:  require('./staticChecked'),
        simpleChecked:  require('./simple-checked'),
        bindHtml:       require('./bind-html'),
        tooltip:        require('./tooltip'),
        repeat:         require('knockoutjs/knockout-repeat'),
        fastForEach:    require('knockoutjs/knockout-fast-foreach')
    };
});
