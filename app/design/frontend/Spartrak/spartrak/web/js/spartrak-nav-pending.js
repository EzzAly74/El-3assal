/**
 * Spartrak — the global loader, shown while a NAVIGATION is in flight.
 *
 * ===========================================================================
 * THE GAP THIS FILLS
 * ===========================================================================
 * The project loader (components/_loader.less, CLAUDE.md section 10) replaces
 * Magento's spinner everywhere Magento raises one — add-to-cart, the minicart,
 * checkout, every customer-data refresh. All of those are AJAX.
 *
 * The category page has none. Sorting is a disclosure of LINKS, the filters
 * are links and the pager is links (see
 * Magento_Catalog/templates/product/list/toolbar.phtml, which records why the
 * sorter is not a <select>), and infinite scroll is deliberately off. So those
 * controls are a full page reload, Magento never raises a loader for one, and a
 * shopper who taps a filter on a phone gets no acknowledgement at all until the
 * new document paints — which on a large catalogue is long enough to tap again.
 *
 * ===========================================================================
 * IT IS SCOPED TO REFINEMENT CONTROLS. IT IS NOT A PAGE-TRANSITION SPINNER.
 * ===========================================================================
 * This module used to raise the overlay for EVERY same-origin anchor on any
 * page that mounted it, with `[data-spartrak-no-nav-loader]` to exclude the
 * exceptions. That was the wrong default and it was reported as such: clicking
 * a product card, a breadcrumb or a menu item threw a full-screen spinner over
 * the category page, so ordinary browsing looked like it was working when
 * nothing was pending but a normal page load the browser already indicates.
 *
 * The distinction that actually matters is not "does this navigate" — nearly
 * everything does — but "is the shopper waiting for THIS PAGE to come back
 * changed". Filtering, sorting, changing the page size and paging all rewrite
 * the grid the shopper is looking at; going somewhere else does not.
 *
 * So the contract is now OPT-IN. Nothing raises the overlay unless it matches
 * SCOPE below, which has two halves and needs both.
 *
 * ONE: an attribute, on markup this theme owns.
 *
 *   Magento_Catalog::product/list/toolbar.phtml   sort, page size, the
 *                                                 active-filter chips
 *   Magento_Catalog::product/list.phtml           the pager's wrapper
 *   Mageplaza_LayeredNavigation::layer/filter.phtml
 *                                                 non-swatch filter options
 *                                                 and the price range form
 *
 * TWO: `#layered-filter-block`, because the filters are NOT all ours to
 * annotate. Measured on the live category page: of its two filters, `price`
 * comes from the theme's own filter.phtml, but `brand` is rendered by CORE as
 * swatches — `Magento_Swatches::product/layered/renderer.phtml` emitting
 * `.swatch-attribute > a[href]`. Those anchors are the ones a shopper actually
 * clicks to filter, and reaching them with an attribute would mean forking a
 * core template for one attribute, which CLAUDE.md section 2 rules out.
 *
 * `#layered-filter-block` is the block that wraps every filter renderer
 * whichever template produced it, and it is already a contract this theme
 * depends on: components/_plp.less keys five rules off the same id, with a
 * note recording that it is the real id the module emits. So this is an
 * existing dependency reused, not a new selector to drift.
 *
 * ONE CONTROL IS STILL OUT: the price SLIDER. Mageplaza's mpLayer navigates
 * from its own JS on release rather than through a link or a submit, so no
 * event reaches this file. Pressing the range form's Apply button does raise
 * the overlay, and so do the range links; the slider drag does not.
 *
 * ===========================================================================
 * IT IS THE SAME LOADER, NOT A SECOND ONE
 * ===========================================================================
 * CLAUDE.md section 10 is explicit that there is ONE loader and that a new
 * surface hooks into it. This file raises no markup and no styling of its own:
 * html/nav-loader.phtml renders Magento's own `.loading-mask > .loader` shape
 * — the exact shape components/_loader.less already draws the Spartrak ring
 * inside — and all this does is put one class on <html>.
 *
 * ===========================================================================
 * WHY A CLASS ON <html> AND NOT `processStart`
 * ===========================================================================
 * `$('body').trigger('processStart')` is the platform's own idiom and was the
 * first choice. It needs `mage.loader` instantiated on the body, and that
 * widget builds its overlay from a template containing
 * `<img src="<%- data.icon %>">`. With no icon configured that renders
 * `src=""`, which a browser resolves to the CURRENT DOCUMENT — a second full
 * request for the page, fired at the exact moment the shopper is already
 * waiting on a navigation. Configuring a real icon instead means fetching a
 * GIF this theme hides in CSS anyway (the residual already recorded at the top
 * of components/_loader.less).
 *
 * A pre-rendered overlay toggled by a class costs no request, no template
 * evaluation and no widget instantiation, and the toggle itself is one
 * classList write on the root element.
 *
 * ===========================================================================
 * WHY THE SHOW IS DEFERRED BY A TASK
 * ===========================================================================
 * Plenty of links on this storefront are NOT navigations: the auth modal's
 * triggers, the contact and sign-out dialogs, the header's own controls. Every
 * one of them calls preventDefault - but several do it from a listener on
 * `document`, exactly like this file, and listeners on the same node run in
 * registration order. This file cannot know whether it registered before or
 * after them.
 *
 * So it does not decide during dispatch. `setTimeout(..., 0)` runs after the
 * whole click has finished propagating, when `event.defaultPrevented` is
 * final - so a click that some other component claimed raises no overlay, and
 * this file needs no list of selectors to exclude and no ordering guarantee.
 *
 * The deferral costs nothing visually: a navigation the browser has already
 * begun still paints the old document until the response arrives, so the
 * overlay appears just as promptly.
 *
 * ===========================================================================
 * AND WHY IT CAN ALWAYS BE DISMISSED
 * ===========================================================================
 * An overlay raised for a navigation that then does not happen would cover the
 * page for good. Three things take it back down: `pageshow` (which is what
 * fires on a back navigation restored from the browser's cache, where no new
 * document is parsed and nothing else would clear it), `popstate`, and a
 * timeout for everything else - a link that turns out to be a download, a 204,
 * a navigation cancelled by the browser.
 */
define([
  "jquery",
  /*
   * The overlay's on/off switch, shared with the layered navigation's AJAX
   * filtering (js/mageplaza-ajax-loader-mixin). It used to be a pair of
   * private functions here; it moved out when a second caller needed the
   * same overlay, so there is one implementation of "the project loader is
   * up" rather than two that could disagree. CLAUDE.md section 10.
   */
  "js/spartrak-page-loader",
], function ($, pageLoader) {
  "use strict";

  var /**
     * THE WHOLE OPT-IN. A click or submit raises the overlay only if it
     * happened inside something matching this; everything else on the page
     * is left to the browser's own loading indicator.
     *
     * The attribute covers markup this theme owns. The id covers the
     * filter block, whose swatch filters are rendered by a core template
     * that is not ours to annotate. The header explains both, and why the
     * price slider is still outside.
     */
    SCOPE = "[data-spartrak-nav-loader],#layered-filter-block",
    started = false;

  /**
   * Has anything cancelled this event — INCLUDING after dispatch?
   *
   * It has to read the NATIVE event, and that is not a stylistic
   * preference. jQuery decides `isDefaultPrevented()` when it CONSTRUCTS its
   * event wrapper (`jQuery.Event` snapshots `src.defaultPrevented` into a
   * returnTrue/returnFalse function), so the wrapper's answer is frozen at
   * the moment the handler chain started. This file asks the question one
   * task LATER, on purpose — see the header — so the wrapper would tell it
   * what was true before every other handler ran, which is exactly the
   * information it is trying not to use.
   *
   * `originalEvent.defaultPrevented` is the live browser flag, and it is set
   * by jQuery's own preventDefault as well as by a native listener's — so
   * this covers both kinds of handler.
   *
   * @param {jQuery.Event} event
   * @return {Boolean}
   */
  function cancelled(event) {
    var native = event.originalEvent || event;

    return Boolean(native.defaultPrevented);
  }

  /**
   * Is this click a REFINEMENT that will load a new document?
   *
   * The scope test comes first because it rejects the overwhelming majority
   * of clicks on the page, and it is one `closest()` call. Every test after
   * it excludes something that looks like a navigation and is not.
   * `defaultPrevented` is deliberately NOT among them - it is checked later,
   * once dispatch is over; see the header.
   *
   * @param {HTMLAnchorElement} link
   * @param {MouseEvent} event
   * @return {Boolean}
   */
  function navigates(link, event) {
    var href = link && link.getAttribute("href");

    // Not a refinement control: the browser's own indicator owns it.
    if (!link || !link.closest || !link.closest(SCOPE)) {
      return false;
    }

    // Not the primary button, or a modified click - both of which open a
    // tab or a window and leave THIS document exactly where it is.
    // (Enter on a focused link reports button 0, so the keyboard path is
    // covered by the same test.)
    if (
      event.button !== 0 ||
      event.metaKey ||
      event.ctrlKey ||
      event.shiftKey ||
      event.altKey
    ) {
      return false;
    }

    // No href at all: an <a> used as a control, which is a pattern core
    // and Porto both still ship.
    if (!href) {
      return false;
    }

    // A download replaces nothing, and `target` sends the result
    // elsewhere. `_self` is written out explicitly in some templates and
    // does mean this document.
    if (
      link.hasAttribute("download") ||
      (link.target && link.target !== "_self")
    ) {
      return false;
    }

    // javascript:, mailto:, tel:, sms: - none of them unload the page.
    // Tested on the ATTRIBUTE rather than on link.protocol, because a
    // bare '#' resolves to the document's own http(s) protocol.
    if (
      href.charAt(0) === "#" ||
      (/^[a-z][a-z0-9+.-]*:/i.test(href) && !/^https?:/i.test(link.protocol))
    ) {
      return false;
    }

    // Another origin still navigates, but it is not THIS store loading -
    // and the overlay would be left standing over a page the shopper may
    // come straight back to.
    if (
      link.protocol + "//" + link.host !==
      window.location.protocol + "//" + window.location.host
    ) {
      return false;
    }

    // Same document, different fragment: an in-page jump, not a load.
    if (
      link.hash &&
      link.pathname === window.location.pathname &&
      link.search === window.location.search
    ) {
      return false;
    }

    return true;
  }

  return function () {
    if (started) {
      return;
    }

    started = true;

    $(document).on("click", "a[href]", function (event) {
      var link = event.currentTarget;

      if (!navigates(link, event)) {
        return;
      }

      // Deferred to the next task, so every handler on this click - on
      // the element and on `document` alike - has already had its say
      // and `defaultPrevented` is final. See the header.
      window.setTimeout(function () {
        if (cancelled(event)) {
          return;
        }

        pageLoader.show();
      }, 0);
    });

    /**
     * A GET form that refines the grid - the price-range inputs, the only
     * one in scope today. Same deferral and the same reason as the click
     * path: a form whose submit some component handles over AJAX cancels
     * the event, and that is only knowable afterwards.
     *
     * Scoped identically. The header's search submit used to land here and
     * no longer does - searching is going somewhere, not waiting on this
     * page (see the header).
     */
    $(document).on("submit", "form", function (event) {
      var form = event.currentTarget;

      if (form.target && form.target !== "_self") {
        return;
      }

      if (!form.closest || !form.closest(SCOPE)) {
        return;
      }

      window.setTimeout(function () {
        if (cancelled(event)) {
          return;
        }

        pageLoader.show();
      }, 0);
    });

    /**
     * `pageshow` and not `load`: a BACK navigation served from the
     * browser's back/forward cache parses no new document and fires no
     * `load`, so `load` alone would leave the overlay standing over a
     * restored page. `pageshow` fires in both cases.
     */
    window.addEventListener("pageshow", pageLoader.hide);
    window.addEventListener("popstate", pageLoader.hide);
  };
});
