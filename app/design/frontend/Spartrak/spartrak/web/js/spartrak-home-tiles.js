/**
 * Spartrak — "الفئات الأكثر بحثا" reveal (Figma 595:15067).
 *
 * Scrolling the category rail reveals the matching large visual and swaps the
 * 70px category name.
 *
 * ===========================================================================
 * WHY IntersectionObserver AND NOT A SCROLL HANDLER
 * ===========================================================================
 * A scroll handler would have to measure every card on every scroll event to
 * work out which one is centred — a forced layout read per event, on the
 * element that is actively scrolling, which is the textbook way to produce
 * scroll jank.
 *
 * IntersectionObserver does that work off the main thread and calls back only
 * when a card's visibility actually CROSSES a threshold. Between crossings it
 * costs nothing at all, however fast the shopper flicks.
 *
 * ===========================================================================
 * WHY THE DOM BARELY CHANGES
 * ===========================================================================
 * Every visual is already in the document, absolutely positioned in the same
 * box, at opacity 0. A reveal is:
 *
 *     remove `data-active` from one <img>, add it to another
 *     write one attribute on the stage
 *     write the name's textContent
 *
 * No node is created, moved or destroyed; no element enters or leaves the
 * flow. So the reveal cannot shift layout (CLS is structurally zero, not
 * merely small), and the crossfade itself is pure opacity+transform, which
 * the compositor runs without the main thread.
 *
 * ===========================================================================
 * PROGRESSIVE ENHANCEMENT
 * ===========================================================================
 * The server already rendered the first category's visual and name, so the
 * section is correct before this file loads and stays correct if it never
 * does — a shopper would simply scroll the rail without the backdrop
 * following along.
 */
define(['jquery', 'jquery-ui-modules/widget'], function ($) {
    'use strict';

    $.widget('mage.spartrakHomeTiles', {
        options: {
            // How much of a card must be showing before it takes over the
            // stage. 0.6 means "clearly the one being looked at" and stops the
            // visual flickering between two half-visible neighbours.
            threshold: 0.6
        },

        _create: function () {
            this.stage = this.element[0];
            this.rail = this.element.find('[data-carousel-track]').first();
            this.items = this.element.find('[data-carousel-slide]');
            this.nameEl = this.element.find('[data-tiles-name]').first();
            this.visuals = this.element.find('[data-visual-index]');

            if (!this.rail.length || this.items.length < 2) {
                return;
            }

            // Names are read out of the DOM once, up front, rather than looked
            // up per reveal — the reveal path must not touch the document
            // beyond the three writes described above.
            this.names = this.items.map(function (i, item) {
                var name = item.querySelector('.spartrak-home-tiles__card-name');

                return name ? name.textContent.trim() : '';
            }).get();

            this.activeIndex = 0;
            this.swapTimer = null;

            if (!('IntersectionObserver' in window)) {
                // No observer: the server-rendered first visual stands, and
                // the rail still scrolls. Nothing is broken, the stage just
                // does not follow. Cheaper than shipping a polyfill for a
                // decorative enhancement.
                return;
            }

            this._observe();
        },

        _observe: function () {
            var self = this;

            this.observer = new IntersectionObserver(function (entries) {
                var best = null;

                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) {
                        return;
                    }

                    if (!best || entry.intersectionRatio > best.intersectionRatio) {
                        best = entry;
                    }
                });

                if (best) {
                    self._activate(parseInt(best.target.getAttribute('data-index'), 10) || 0);
                }
            }, {
                // The RAIL is the viewport, not the page: what matters is
                // which card is inside the rail's own visible width.
                root: this.rail[0],
                threshold: [this.options.threshold, 0.9]
            });

            this.items.each(function (i, item) {
                self.observer.observe(item);
            });
        },

        _activate: function (index) {
            if (index === this.activeIndex) {
                return;
            }

            this.activeIndex = index;
            this.stage.setAttribute('data-active-index', String(index));

            this.visuals.each(function (i, visual) {
                if (parseInt(visual.getAttribute('data-visual-index'), 10) === index) {
                    visual.setAttribute('data-active', '');
                } else {
                    visual.removeAttribute('data-active');
                }
            });

            this._swapName(this.names[index] || '');
        },

        /**
         * Fades the name out, replaces the text, fades it back in.
         *
         * The text is written while the element is at opacity 0 so the swap
         * is never visible mid-change. `data-swapping` drives both halves from
         * CSS — this function sets an attribute and a string, nothing more.
         */
        _swapName: function (name) {
            if (!this.nameEl.length) {
                return;
            }

            var self = this;

            window.clearTimeout(this.swapTimer);
            this.stage.setAttribute('data-swapping', '');

            this.swapTimer = window.setTimeout(function () {
                self.nameEl[0].textContent = name;
                self.stage.removeAttribute('data-swapping');
            }, 180);
        },

        _destroy: function () {
            window.clearTimeout(this.swapTimer);

            if (this.observer) {
                this.observer.disconnect();
            }
        }
    });

    return $.mage.spartrakHomeTiles;
});
