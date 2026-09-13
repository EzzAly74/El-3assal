/**
 * Spartrak mega nav — the browse panel, the category flyouts' scrim, and the
 * category row's drag gesture.
 *
 * QA FIX (2026-08-25): the first pass attached one flyout per L1 nav item,
 * opened by hovering that item. Live testing showed two problems: the panel
 * should open from the "تسوق حسب الفئات" CTA, not from hovering an L1 item,
 * and the old layout had a real hover dead-zone (a 16px gap between trigger
 * and panel) that dropped the panel before the pointer could reach it. Both
 * are fixed structurally in the markup/CSS (topmenu.phtml and
 * _mega-nav.less) — the trigger and panel are now DOM siblings inside one
 * `.spartrak-mega-nav__browse` wrapper, and `:hover`/`:focus-within` on that
 * wrapper is what shows the panel, with no gap to lose hover in. This widget
 * only handles what CSS still can't:
 *
 *  1. L1 TAB SWITCHING inside the panel — which L1's group of L2s is shown.
 *  2. THE SCRIM — dim the rest of the page while a panel is open. Needs to
 *     react to hover/focus entering a trigger, which a plain CSS sibling
 *     selector can't do without `:has()`.
 *  3. Escape-to-close, moving focus back to the trigger.
 *  4. CLICK-AND-DRAG on the category row (added 2026-09-10).
 *
 * Every listener is bound within this.element (the nav root), never
 * document-level (10-THEME-ARCHITECTURE.md JS architecture rule 2).
 *
 * ===========================================================================
 * (1) THE SCRIM NOW COVERS THE CATEGORY FLYOUTS TOO (2026-09-10)
 * ===========================================================================
 * It was bound to `.spartrak-mega-nav__browse` alone, so the page dimmed behind
 * the "تسوق حسب الفئات" panel and did NOT dim behind an individual category's
 * panel — two surfaces that are the same component, opening the same way, with
 * different backdrops. Reported as "when I hover shop-by-categories there is an
 * overlay; make it exactly the same on the categories".
 *
 * `.spartrak-mega-nav__item--has-flyout` now gets the same treatment. What is
 * NOT shared is `aria-expanded`: that belongs to the CTA button, which is the
 * only one of the two that is a button with a panel it owns. A category item is
 * a link whose flyout is a hover affordance, and stamping `aria-expanded` on the
 * CTA because a category was hovered would be a lie to assistive tech.
 *
 * ===========================================================================
 * (2) WHY `focusout` IS BOUND ON THE ROOT, NOT PER TRIGGER
 * ===========================================================================
 * `mouseleave` is the mouse's way out and is bound per trigger. A KEYBOARD user
 * has no mouseleave: tabbing off the last category would leave the scrim up for
 * good. `focusout` on the nav root with a `relatedTarget` test is the one
 * listener that answers "has focus left the nav entirely?", which is the real
 * question — a `focusout` per trigger would fire while focus was merely moving
 * from a trigger INTO its own panel and close the scrim underneath it.
 *
 * ===========================================================================
 * (3) THE ROW IS DRAGGABLE, AND DRAGGING MUST NOT OPEN PANELS
 * ===========================================================================
 * The row scrolls (see _mega-nav.less: the card is capped and the list absorbs
 * the overflow), but a hidden scrollbar and a cut-off edge were the only hints,
 * which shoppers were missing. It now takes the same click-and-drag gesture as
 * the homepage rails, from the same module — js/spartrak-drag-scroll.js — and
 * the same grab cursor, from the same LESS mixin.
 *
 * The interaction that needs care: while a shopper drags the row, the pointer
 * sweeps ACROSS category items with the button held. Hover would fire on each
 * one, so panels would flash open under the moving cursor and the scrim would
 * pulse. Suppressed on both sides:
 *
 *   the panels  by CSS, on `.is-dragging` (the class this module's drag helper
 *               toggles) — see _mega-nav.less
 *   the scrim   here, by asking the drag helper whether a gesture is running
 *
 * One source of truth for "is a drag in progress" — the helper's own state —
 * rather than a second flag on this widget that could disagree with it.
 */
define([
  "jquery",
  /*
   * The widget factory ALONE, not the jQuery UI aggregate.
   *
   * This asked for 'jquery/ui' and used exactly one thing from it:
   * $.widget. 'jquery/ui' is the whole library — measured on the live
   * homepage it resolved to 54 modules and 105,103 bytes, including
   * datepicker, sortable, resizable, draggable, tabs, dialog, spinner,
   * jquery-color and all seventeen effects, none of which any Spartrak
   * component calls. 'jquery/ui-modules/widget' declares ["jquery",
   * "./version"] and nothing else: 2 files, 4,432 bytes.
   */
  "jquery/ui-modules/widget",
  /*
   * The shared click-and-drag gesture, also used by
   * js/spartrak-home-carousel.js. Required by path rather than through the
   * theme's requirejs-config.js `paths` map, which exists to give WIDGETS the
   * short names data-mage-init needs; this is a plain module and is never
   * named in markup.
   */
  "js/spartrak-drag-scroll",
], function ($, widget, dragScroll) {
  "use strict";

  $.widget("mage.spartrakMegaNav", {
    options: {},

    _create: function () {
      var browse = this.element.find(".spartrak-mega-nav__browse"),
        categories = this.element.find(".spartrak-mega-nav__item--has-flyout"),
        list = this.element.find(".spartrak-mega-nav__list")[0];

      this._on({
        "click [data-flyout-tab]": this._handleTabActivate,
        "keydown .spartrak-mega-nav__shop-by-categories":
          this._handleTriggerKeydown,
      });

      // mouseenter/mouseleave/focusin don't bubble the way a delegated
      // map on this.element can use, so they're bound directly to each
      // trigger via the widget factory's own "element, handlers" _on()
      // form.
      this._on(browse, {
        mouseenter: this._showBrowse,
        focusin: this._showBrowse,
        mouseleave: this._hideBrowse,
      });

      // The same backdrop for a category's own panel. See note (1) for
      // why these do not touch the CTA's aria-expanded.
      this._on(categories, {
        mouseenter: this._showScrim,
        focusin: this._showScrim,
        mouseleave: this._hideScrim,
      });

      // See note (2). focusout DOES bubble, so one delegated listener on
      // the root covers every trigger and every panel inside them.
      this._on({
        focusout: this._handleFocusOut,
      });

      // See note (3). The row is only draggable when it is a scroll
      // container at all, which is every width on this catalogue but is
      // not worth asserting: dragScroll on a non-overflowing element is
      // inert, because scrollLeft has nowhere to go.
      if (list) {
        this.drag = dragScroll(list, {
          // The flyouts are absolutely positioned DOM children of the
          // row's items, so a press inside an open panel bubbles here.
          // Without this, pressing and moving inside a 540px-tall panel
          // would drag the category strip underneath it.
          ignore: ".spartrak-mega-nav__flyout",
        });
      }
    },

    _handleTabActivate: function (event) {
      var tab = $(event.currentTarget),
        flyout = tab.closest(".spartrak-mega-nav__flyout"),
        panelId = tab.attr("aria-controls");

      flyout.find("[data-flyout-tab]").attr("aria-selected", "false");
      tab.attr("aria-selected", "true");

      flyout.find(".spartrak-mega-nav__flyout-l1-panel").prop("hidden", true);
      flyout.find("#" + panelId).prop("hidden", false);
    },

    _handleTriggerKeydown: function (event) {
      if (event.key === "Escape") {
        this._hideBrowse();
        $(event.currentTarget).trigger("blur");
      }
    },

    /**
     * Focus has left the nav entirely — not merely moved from a trigger
     * into its own panel, which is what a per-trigger focusout would have
     * caught. `relatedTarget` is the element GAINING focus; null means
     * focus left the document, which also counts as leaving.
     */
    _handleFocusOut: function (event) {
      var next = event.relatedTarget;

      if (next && $.contains(this.element[0], next)) {
        return;
      }

      this._hideBrowse();
    },

    _showBrowse: function () {
      this.element
        .find(".spartrak-mega-nav__shop-by-categories")
        .attr("aria-expanded", "true");
      this._showScrim();
    },

    _hideBrowse: function () {
      this.element
        .find(".spartrak-mega-nav__shop-by-categories")
        .attr("aria-expanded", "false");
      this._hideScrim();
    },

    _showScrim: function () {
      // See note (3): a pointer sweeping across the row with the button
      // held is dragging, not browsing, and the panels it passes are kept
      // shut by CSS. The backdrop has to agree with them.
      if (this.drag && this.drag.isDragging()) {
        return;
      }

      this.element
        .find(".spartrak-mega-nav__scrim")
        .attr("data-visible", "true");
    },

    _hideScrim: function () {
      this.element.find(".spartrak-mega-nav__scrim").removeAttr("data-visible");
    },
  });

  return $.mage.spartrakMegaNav;
});
