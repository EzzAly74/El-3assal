/**
 * Spartrak — "بتدور علي ايه؟" cascading finder (Figma 595:15843).
 *
 * ===========================================================================
 * WHAT THIS ADDS, AND WHAT WORKS WITHOUT IT
 * ===========================================================================
 * The form is server-rendered with real, populated controls: the brand select
 * and the first category level both arrive filled. So before this file loads —
 * or if it never does — a shopper can still choose a brand and a type and
 * press the button, and the form still navigates. This widget adds the deeper
 * levels and the destination logic on top of a page that already works.
 *
 * ===========================================================================
 * THE CASCADE
 * ===========================================================================
 * Each level asks this module's own endpoint for the children of whatever was
 * chosen above it, then fills the next select. Two rules keep it honest:
 *
 *   - a level with NO children stays hidden, so the shopper is never handed an
 *     empty dropdown to open;
 *   - changing a level always clears every level below it, so the form can
 *     never submit a stale combination like "Engine > <part of a different
 *     type>".
 *
 * In-flight requests are aborted when the selection changes again, and every
 * answer is memoised per parent id — flipping back and forth between two types
 * costs one request each, not one per flip.
 *
 * ===========================================================================
 * WHERE SUBMIT GOES
 * ===========================================================================
 * The DEEPEST selected category's own URL, plus `?brand=<id>` when a brand is
 * chosen (brand is a real filterable attribute, so the category page's layered
 * navigation applies it). Brand alone falls back to the same URL the header's
 * brand tiles use, handed over by the template. Nothing is synthesised.
 */
define(['jquery', 'jquery-ui-modules/widget'], function ($) {
    'use strict';

    $.widget('mage.spartrakCascadeSearch', {
        options: {
            optionsUrl: '',
            brandUrls: {}
        },

        _create: function () {
            this.form = this.element.find('[data-finder-form]').first();

            if (!this.form.length) {
                return;
            }

            this.cache = {};
            this.request = null;

            this._on({
                'change [data-finder-level]': this._onLevelChange,
                'submit [data-finder-form]': this._onSubmit
            });
        },

        _select: function (level) {
            return this.form.find('[data-finder-level="' + level + '"]');
        },

        _field: function (level) {
            return this.form.find('[data-finder-field="' + level + '"]');
        },

        _onLevelChange: function (event) {
            var select = $(event.currentTarget);
            var level = parseInt(select.attr('data-finder-level'), 10) || 0;
            var value = select.val();

            // Everything below this level is now meaningless.
            this._resetFrom(level + 1);

            if (!value) {
                return;
            }

            this._loadChildren(value, level + 1);
        },

        /**
         * Empties, disables and hides every level from `level` downwards.
         */
        _resetFrom: function (level) {
            for (var i = level; i <= 3; i++) {
                var select = this._select(i);

                if (!select.length) {
                    continue;
                }

                // Keep the placeholder option, drop everything after it.
                select.find('option').slice(1).remove();
                select.val('');
                select.prop('disabled', true);
                this._field(i).attr('hidden', 'hidden');
            }
        },

        _loadChildren: function (parentId, level) {
            var select = this._select(level);

            if (!select.length || !this.options.optionsUrl) {
                return;
            }

            if (this.cache[parentId]) {
                this._fill(level, this.cache[parentId]);

                return;
            }

            if (this.request) {
                this.request.abort();
            }

            var self = this;

            this.request = $.ajax({
                url: this.options.optionsUrl,
                data: { parent: parentId },
                type: 'GET',
                dataType: 'json'
            }).done(function (response) {
                var options = response && response.options ? response.options : [];

                self.cache[parentId] = options;
                self._fill(level, options);
            }).fail(function (jqXhr, status) {
                if (status === 'abort') {
                    return;
                }

                // A failed lookup leaves the finder usable at the levels that
                // did resolve rather than blocking the whole form.
                self._resetFrom(level);
            }).always(function () {
                self.request = null;
            });
        },

        _fill: function (level, options) {
            var select = this._select(level);
            var field = this._field(level);

            if (!options.length) {
                // Nothing below this point — the chosen category is a leaf.
                // Leaving the field hidden is the correct outcome, not an
                // error: the shopper has already picked something specific
                // enough to search on.
                return;
            }

            var fragment = document.createDocumentFragment();

            options.forEach(function (option) {
                var el = document.createElement('option');

                el.value = option.value;
                el.textContent = option.label;

                if (option.url) {
                    el.setAttribute('data-url', option.url);
                }

                fragment.appendChild(el);
            });

            // One append, not one per option — the select is touched once.
            select[0].appendChild(fragment);
            select.prop('disabled', false);
            field.removeAttr('hidden');
        },

        /**
         * The deepest level that actually has a selection.
         */
        _deepestSelected: function () {
            for (var i = 3; i >= 1; i--) {
                var select = this._select(i);

                if (select.length && select.val()) {
                    return select.find('option:selected');
                }
            }

            return null;
        },

        _onSubmit: function (event) {
            event.preventDefault();

            var selected = this._deepestSelected();
            var brand = this.form.find('[data-finder-brand]').val();
            var destination = '';

            if (selected && selected.attr('data-url')) {
                destination = selected.attr('data-url');

                if (brand) {
                    destination += (destination.indexOf('?') === -1 ? '?' : '&')
                        + 'brand=' + encodeURIComponent(brand);
                }
            } else if (brand && this.options.brandUrls[brand]) {
                // No category chosen: go exactly where the header's tile for
                // this brand goes.
                destination = this.options.brandUrls[brand];
            }

            if (!destination) {
                // Nothing chosen at all — put the shopper in the first control
                // rather than navigating somewhere arbitrary.
                this.form.find('select').filter(':enabled').first().trigger('focus');

                return;
            }

            window.location.assign(destination);
        },

        _destroy: function () {
            if (this.request) {
                this.request.abort();
            }
        }
    });

    return $.mage.spartrakCascadeSearch;
});
