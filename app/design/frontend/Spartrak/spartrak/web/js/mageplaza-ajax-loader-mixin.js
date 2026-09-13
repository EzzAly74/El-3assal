/**
 * Makes the layered navigation's AJAX filtering, sorting and pagination raise
 * THE PROJECT LOADER instead of Mageplaza's own spinner.
 *
 * Target: Mageplaza_AjaxLayer/js/model/loader
 *
 * ===========================================================================
 * WHY
 * ===========================================================================
 * CLAUDE.md section 10: the loading experience is ONE global Spartrak
 * component, Magento's default indicator is replaced "consistently across the
 * entire project", and a new surface HOOKS INTO the existing system rather
 * than bringing its own.
 *
 * Filtering was the one refinement surface still outside it, and not because
 * it had been missed. js/spartrak-nav-pending.js already covers the category
 * page's refinement controls — but it covers them by watching for a NAVIGATION,
 * and it deliberately stands down when something has called preventDefault
 * (see that file on why the decision is deferred by a task). Mageplaza's
 * mpLayer calls preventDefault on every filter, sort, page-size and pager
 * click precisely because it is handling them over AJAX, so nav-pending
 * correctly does nothing and Mageplaza's own loader took over instead:
 *
 *     startLoader: $('.ln_overlay').show()
 *
 * That overlay is Mageplaza's markup, its styling and its spinner — a white
 * `height: 300%; width: 500%` layer at `opacity: 0.5` wrapping
 * `<img src=".../images/loader-1.gif">`. So the storefront had two different
 * loading treatments depending on whether the shopper's click happened to be
 * intercepted, which is the exact outcome section 10 exists to prevent.
 *
 * ===========================================================================
 * IT IS ALSO CHEAPER, WHICH MATTERS MORE THAN THE CONSISTENCY HERE
 * ===========================================================================
 * PERFORMANCE IS THIS PROJECT'S FIRST PRIORITY (section 4), and this is a
 * straight reduction:
 *
 *   ONE FEWER REQUEST. loader-1.gif is an animated GIF fetched from the theme's
 *   static tree the first time a shopper filters. The project loader is a
 *   pure-CSS ring (foundations/_spinner.less) on an overlay already in the
 *   document from the first paint, so it fetches nothing — and section 10
 *   requires a lightweight, pure-CSS implementation with no library.
 *
 *   LESS PAINT. A 300%-by-500% translucent white box is a very large composited
 *   layer for a spinner; the project overlay is a viewport-sized scrim that the
 *   browser already has laid out and merely un-hides.
 *
 *   NO NEW JS ON THE PAGE. A mixin is merged into the same bundle as its
 *   target, so this adds no request (the same reasoning the theme's other
 *   mixins record in requirejs-config.js), and it replaces two function
 *   bodies rather than adding a component.
 *
 * ===========================================================================
 * WHY A MIXIN AND NOT AN EDIT, OR A MAP
 * ===========================================================================
 * Mageplaza_AjaxLayer lives in app/code but it is still third-party code whose
 * own header asks not to be edited if it is ever to be upgraded. A `map` to a
 * replacement module would mean owning a copy of a file we do not maintain.
 *
 * A mixin replaces exactly the two methods whose behaviour is wrong for this
 * project and leaves everything else — including the swatch-tooltip dismissal
 * below — as the extension wrote it. Every caller is covered at once because
 * they all go through this one model: Mageplaza_AjaxLayer/js/action/
 * submit-filter calls startLoader/stopLoader, and view/layer.js routes the
 * filters, the sort links, the limiter and `#layer-product-list .pages a`
 * through that same action.
 */
define(["js/spartrak-page-loader"], function (pageLoader) {
  "use strict";

  return function (mageplazaLoader) {
    /**
     * @param {Object} loader Mageplaza's own model, mutated in place.
     * @return {Object}
     */
    mageplazaLoader.startLoader = function () {
      pageLoader.show();
    };

    mageplazaLoader.stopLoader = function () {
      pageLoader.hide();

      /*
       * KEPT FROM MAGEPLAZA'S OWN stopLoader, and not because it is
       * related to loading. A swatch filter's tooltip is positioned
       * against an element the AJAX response is about to replace, so a
       * tooltip left standing would hang over the new grid pointing at
       * nothing. Dropping it while replacing this method would have been
       * a silent behaviour change in code that is not ours.
       *
       * Plain DOM rather than jQuery: this module has no other need for
       * it, and taking the dependency to hide a node would cost more than
       * the loop. `style.display` matches what jQuery's .hide() wrote, so
       * the extension's own show() still undoes it.
       */
      var tooltips = document.querySelectorAll(".swatch-option-tooltip"),
        i;

      for (i = 0; i < tooltips.length; i++) {
        tooltips[i].style.display = "none";
      }
    };

    return mageplazaLoader;
  };
});
