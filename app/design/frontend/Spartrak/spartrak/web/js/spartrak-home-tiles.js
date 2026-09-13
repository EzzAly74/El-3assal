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
 * THE OBSERVER IS THE TRIGGER. THE SCROLL OFFSET IS THE ANSWER.
 * ===========================================================================
 * This used to pick the active card out of the observer's own entries — the
 * intersecting one with the highest `intersectionRatio`. That is wrong, and it
 * produced a reported defect: pressing next on a wide screen took the stage
 * from category 0 straight to category 2, so category 1 could never be seen.
 *
 * The cause is that an IntersectionObserver reports ONLY the targets whose
 * visibility crossed a threshold. Measured on the live desktop rail: it is
 * 716px wide with a 336px pitch, so two cards are fully visible and a third
 * shows 13%. Scrolling one card forward from rest:
 *
 *     card 0   1.00 -> 0      crossed 0.9 and 0.6   REPORTED
 *     card 1   1.00 -> 1.00   crossed nothing       NOT REPORTED
 *     card 2   0.13 -> 1.00   crossed 0.6 and 0.9   REPORTED
 *
 * Card 1 is now at the reading edge and is the one the shopper is looking at,
 * but it is absent from the callback entirely, so the highest ratio among the
 * entries belongs to card 2. No threshold list fixes this: the card that did
 * not change is exactly the card that is never in the list.
 *
 * So the observer is kept for what it is genuinely good at — waking this code
 * only when something actually moved, off the main thread — and the question
 * "which card" is answered from the rail's own scroll offset instead. That is
 * three layout reads per crossing (two offsetLefts and one scrollLeft), a
 * handful per gesture rather than one per scroll event, so the reason this
 * file is not a scroll handler still holds.
 *
 * It is also the SAME arithmetic js/spartrak-home-carousel.js uses for
 * `_currentIndex()`, deliberately: the arrows and the reveal now derive the
 * current card the same way, so an arrow press and the stage cannot disagree
 * about which category is showing. `Math.abs` because scrollLeft is negative
 * in RTL, which is the store's default direction.
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
define(["jquery", "jquery-ui-modules/widget"], function ($) {
  "use strict";

  $.widget("mage.spartrakHomeTiles", {
    options: {
      // How much of a card must be showing before it takes over the
      // stage. 0.6 means "clearly the one being looked at" and stops the
      // visual flickering between two half-visible neighbours.
      threshold: 0.6,
    },

    _create: function () {
      this.stage = this.element[0];
      this.rail = this.element.find("[data-carousel-track]").first();
      this.items = this.element.find("[data-carousel-slide]");
      this.nameEl = this.element.find("[data-tiles-name]").first();
      this.visuals = this.element.find("[data-visual-index]");

      if (!this.rail.length || this.items.length < 2) {
        return;
      }

      // Names are read out of the DOM once, up front, rather than looked
      // up per reveal — the reveal path must not touch the document
      // beyond the three writes described above.
      this.names = this.items
        .map(function (i, item) {
          var name = item.querySelector(".spartrak-home-tiles__card-name");

          return name ? name.textContent.trim() : "";
        })
        .get();

      this.activeIndex = 0;
      this.swapTimer = null;

      if (!("IntersectionObserver" in window)) {
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

      // The entries are deliberately ignored — see the header. All this
      // callback means is "the rail moved enough to matter".
      this.observer = new IntersectionObserver(
        function () {
          self._activate(self._indexAtReadingEdge());
        },
        {
          // The RAIL is the viewport, not the page: what matters is
          // which card is inside the rail's own visible width.
          root: this.rail[0],
          threshold: [this.options.threshold, 0.9],
        },
      );

      this.items.each(function (i, item) {
        self.observer.observe(item);
      });
    },

    /**
     * Distance from the start of one card to the start of the next — width
     * PLUS gap.
     *
     * Read off two offsetLefts rather than computed from a width and a gap,
     * so it stays correct if the stylesheet's 12px gap ever changes and
     * this file never has to know a gap value. Always > 0, so it is safe to
     * divide by.
     *
     * @return {Number}
     */
    _pitch: function () {
      var first = this.items.get(0),
        second = this.items.get(1),
        pitch = 0;

      if (first && second) {
        pitch = Math.abs(second.offsetLeft - first.offsetLeft);
      }

      return pitch || this.rail[0].clientWidth || 1;
    },

    /**
     * Which card is at the rail's reading edge right now.
     *
     * Rounded, so a rail resting a few sub-pixels off a snap point still
     * reports the card it is showing, and clamped so a rail scrolled to its
     * very end cannot name a card that does not exist.
     *
     * @return {Number}
     */
    _indexAtReadingEdge: function () {
      var index = Math.round(Math.abs(this.rail[0].scrollLeft) / this._pitch());

      return Math.min(Math.max(index, 0), this.items.length - 1);
    },

    _activate: function (index) {
      if (index === this.activeIndex) {
        return;
      }

      this.activeIndex = index;
      this.stage.setAttribute("data-active-index", String(index));

      // THE ARTWORK ALWAYS MATCHES THE NAME, EVEN WHEN THERE IS NONE.
      //
      // A visual is rendered per category that HAS an image, and the
      // template skips the ones that do not (see category-tiles.phtml —
      // both the tile photo and the reveal come from the category's own
      // Catalog > Categories > Content image). Measured on the live
      // homepage: five cards, three visuals.
      //
      // THIS USED TO BE GUARDED BY `if (visualIndexes.indexOf(index) !==
      // -1)`, which HELD THE PREVIOUS CATEGORY'S PHOTOGRAPH when the card
      // being read had none. That was recorded here as "the better of the
      // two wrong answers" against a stage with no artwork at all. It is
      // not: QA reported it as NEW2B-5634, "the label is not always match
      // the displayed product", which is precisely what a held image
      // says. A stage showing nothing is missing information; a stage
      // showing the wrong product states something false about the
      // category whose name is printed beside it, and a shopper cannot
      // tell that is what happened.
      //
      // So the guard is gone and the loop below always runs: it reveals
      // the visual for `index` when one exists and leaves every visual
      // hidden when it does not. Between two states the design does not
      // draw, the honest one wins.
      //
      // THE REAL REPAIR IS STILL CATALOGUE DATA — an image on every
      // category in this section, set in Catalog > Categories > Content.
      // This only stops the gap from lying while it is open.
      this.visuals.each(function (i, visual) {
        if (parseInt(visual.getAttribute("data-visual-index"), 10) === index) {
          visual.setAttribute("data-active", "");
        } else {
          visual.removeAttribute("data-active");
        }
      });

      this._swapName(this.names[index] || "");
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
      this.stage.setAttribute("data-swapping", "");

      this.swapTimer = window.setTimeout(function () {
        self.nameEl[0].textContent = name;
        self.stage.removeAttribute("data-swapping");
      }, 180);
    },

    _destroy: function () {
      window.clearTimeout(this.swapTimer);

      if (this.observer) {
        this.observer.disconnect();
      }
    },
  });

  return $.mage.spartrakHomeTiles;
});
