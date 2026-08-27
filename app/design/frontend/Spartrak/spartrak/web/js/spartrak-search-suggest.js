/**
 * Spartrak Search Suggestions.
 *
 * Drives the panel Figma specifies at desktop node 864:8879 / mobile node
 * 647:53929: fetches the rendered fragment from Spartrak_Search's endpoint as
 * the shopper types, injects it under the search field, and wires the keyboard
 * and dismissal behaviour a combobox needs.
 *
 * ===========================================================================
 * WHY THIS REPLACES core's `quickSearch`
 * ===========================================================================
 * Magento_Search/js/form-mini renders its own <ul> of {title, num_results}
 * into the destination element. Figma's panel is not that list — it leads with
 * a result count and a rail of product cards, neither of which core's endpoint
 * returns (see the Spartrak_Search README). Running both widgets on one input
 * would put two competing renderers in the same container, so this takes over
 * the suggestion role entirely.
 *
 * Nothing functional is lost:
 *   - SUBMISSION is untouched. The form is a real <form action> with a real
 *     submit button, so pressing Enter or clicking Search works exactly as
 *     before — and still works with JavaScript disabled, which is more than
 *     quickSearch offered.
 *   - The `q` parameter name, the results route and the store's min/max query
 *     length all still come from Magento (the endpoint enforces the same
 *     limits server-side).
 *
 * ===========================================================================
 * PERFORMANCE (CLAUDE.md §4/§13)
 * ===========================================================================
 *   - Nothing is requested until the shopper has typed at least `minLength`
 *     characters, so a single stray keystroke costs no request at all.
 *   - Input is debounced, so a burst of typing produces ONE request, not one
 *     per character.
 *   - Every new request aborts the one in flight, so a slow response for an
 *     abandoned prefix can never overwrite a fast one for the current query
 *     (the classic out-of-order autocomplete bug) and never occupies a
 *     connection it no longer needs.
 *   - Responses are memoised per query for the life of the page, so
 *     backspacing over a term re-renders from memory with no network at all.
 *   - The server returns HTML, so there is no client-side templating step and
 *     nothing to parse beyond assigning a string.
 *
 * Dependencies are jQuery and the jQuery UI widget factory, both already on
 * the page. No new library.
 */
define([
    'jquery',
    'jquery/ui'
], function ($) {
    'use strict';

    $.widget('mage.spartrakSearchSuggest', {
        options: {
            /** Endpoint URL — supplied by the template from Magento's routing. */
            url: '',
            /**
             * Store's own minimum query length. Supplied by the template from
             * Magento\Search\Helper\Data so the client and the endpoint agree
             * on when a query is worth running.
             */
            minLength: 3,
            /**
             * Long enough that ordinary typing produces one request per word
             * rather than per letter; short enough to still feel immediate.
             */
            delay: 250,
            panelSelector: '#search_autocomplete'
        },

        /**
         * @private
         */
        _create: function () {
            this.input = this.element;
            this.panel = $(this.options.panelSelector);

            if (!this.panel.length || !this.options.url) {
                return;
            }

            /** Query -> rendered HTML, for the life of the page. */
            this._cache = {};
            this._request = null;
            this._timer = null;
            this._open = false;

            this._on({
                input: this._onInput,
                keydown: this._onKeydown,
                focus: this._onFocus
            });

            // The panel is a sibling of the input, not a descendant, so its own
            // clicks would otherwise register as "outside" and close it before
            // the click resolved. Bound here rather than at the document so the
            // handler exists only while this widget does.
            this._on(this.panel, {
                mousedown: function (event) {
                    // Keeps focus in the input so blur-driven closing does not
                    // race the link's own navigation.
                    event.preventDefault();
                },
                'click [data-suggest-term]': this._onTermClick
            });

            this._outside = $.proxy(this._onOutside, this);
            $(document).on('click.spartrakSuggest', this._outside);
        },

        /**
         * @private
         */
        _destroy: function () {
            $(document).off('click.spartrakSuggest', this._outside);
            this._abort();
            clearTimeout(this._timer);
            this._close();
        },

        /**
         * @private
         */
        _onInput: function () {
            var self = this;

            clearTimeout(this._timer);

            this._timer = setTimeout(function () {
                self._search(self._query());
            }, this.options.delay);
        },

        /**
         * Re-open on focus when there is already a usable query — a shopper
         * clicking back into a field they have typed in expects the panel back
         * without having to edit the text.
         *
         * @private
         */
        _onFocus: function () {
            var query = this._query();

            if (query.length >= this.options.minLength) {
                this._search(query);
            }
        },

        /**
         * @private
         * @return {String}
         */
        _query: function () {
            return $.trim(String(this.input.val() || ''));
        },

        /**
         * @private
         * @param {String} query
         */
        _search: function (query) {
            var self = this;

            if (query.length < this.options.minLength) {
                this._abort();
                this._close();

                return;
            }

            if (Object.prototype.hasOwnProperty.call(this._cache, query)) {
                this._render(this._cache[query]);

                return;
            }

            this._abort();

            this._request = $.ajax({
                url: this.options.url,
                type: 'GET',
                dataType: 'html',
                cache: true,
                data: {q: query}
            }).done(function (html) {
                self._cache[query] = html;

                // Guard against a response that arrived after the shopper
                // moved on: only paint if it still matches the box.
                if (self._query() === query) {
                    self._render(html);
                }
            }).fail(function (jqXHR, textStatus) {
                // An aborted request is this widget superseding itself, not a
                // failure — leave whatever is on screen alone.
                if (textStatus === 'abort') {
                    return;
                }

                // Anything else means the panel's content is unknown; closing
                // is the honest state. Submission still works.
                self._close();
            }).always(function () {
                self._request = null;
            });
        },

        /**
         * @private
         */
        _abort: function () {
            if (this._request) {
                this._request.abort();
                this._request = null;
            }
        },

        /**
         * An empty body is the endpoint's "nothing to suggest" — see the
         * controller. Treated as a close rather than an empty panel.
         *
         * @private
         * @param {String} html
         */
        _render: function (html) {
            if (!$.trim(html)) {
                this._close();

                return;
            }

            this.panel.html(html);
            this._openPanel();
        },

        /**
         * @private
         */
        _openPanel: function () {
            this.panel.attr('hidden', null);
            this.input.attr('aria-expanded', 'true');
            this._open = true;
        },

        /**
         * @private
         */
        _close: function () {
            if (!this._open && !this.panel.children().length) {
                return;
            }

            this.panel.empty().attr('hidden', 'hidden');
            this.input.attr('aria-expanded', 'false');
            this._open = false;
        },

        /**
         * Escape closes; Down moves into the panel so the suggestions are
         * reachable without a mouse. Everything else — including Enter — is
         * left to the browser, so Enter still submits the form.
         *
         * @private
         * @param {Object} event
         */
        _onKeydown: function (event) {
            if (event.key === 'Escape') {
                this._abort();
                this._close();

                return;
            }

            if (event.key === 'ArrowDown' && this._open) {
                event.preventDefault();
                this.panel.find('a').first().trigger('focus');
            }
        },

        /**
         * Clicking a term puts it in the box before following the link, so the
         * results page and the search field agree on what was searched for.
         *
         * @private
         * @param {Object} event
         */
        _onTermClick: function (event) {
            var term = $(event.currentTarget).data('suggestTerm');

            if (term) {
                this.input.val(term);
            }
        },

        /**
         * @private
         * @param {Object} event
         */
        _onOutside: function (event) {
            if ($(event.target).closest(this.panel).length ||
                $(event.target).closest(this.input).length) {
                return;
            }

            this._close();
        }
    });

    return $.mage.spartrakSearchSuggest;
});
