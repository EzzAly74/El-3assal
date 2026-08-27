/**
 * Spartrak - mobile PLP filter toggle fix (Phase 6).
 *
 * Porto's toolbar.phtml ships a `.porto-product-filters-toggle` button
 * with no bound click handler (confirmed live in Phase 6 discovery - it
 * is dead markup). The REAL, working mobile-filter toggle is Mageplaza
 * LayeredNavigation's own accordion widget, which adds/removes a
 * `filter-active` class on <body> when its own
 * `#layered-filter-block .filter-title [data-role=title]` element is
 * clicked (also confirmed live). Rather than build a new drawer
 * mechanism, this widget forwards a click on Porto's visible button to
 * Mageplaza's real toggle target - reusing the existing, functioning
 * native infrastructure instead of replacing it.
 *
 * Bound to <body> (via a data-mage-init body attribute added in this
 * theme's catalog_category_view.xml / catalogsearch_result_index.xml -
 * not by overriding Porto's toolbar.phtml) with a delegated click
 * handler, since the toggle button is rendered by Porto's own toolbar
 * template and may not exist yet at widget-init time on every page.
 */
define(['jquery'], function ($) {
    'use strict';

    $.widget('mage.spartrakPlpFilterToggle', {
        options: {},

        _create: function () {
            this._on({ 'click .porto-product-filters-toggle': this._handleClick });
        },

        _handleClick: function (event) {
            event.preventDefault();
            var target = $('#layered-filter-block .filter-title [data-role="title"]').first();
            if (target.length) {
                target.trigger('click');
            }
        }
    });

    return $.mage.spartrakPlpFilterToggle;
});
