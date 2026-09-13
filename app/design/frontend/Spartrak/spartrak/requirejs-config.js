/**
 * Spartrak Phase 4 — maps the bare widget names used in each component's
 * data-mage-init attribute to their real module paths, so Magento's native
 * mage/apply/main.js dataAttr initializer can require() and instantiate
 * them. No new JS libraries — jQuery + the jQuery UI widget factory only,
 * both already core Magento dependencies (10-THEME-ARCHITECTURE.md JS
 * architecture rule 1).
 */
var config = {
  map: {
    "*": {
      spartrakUtilityHeader: "js/spartrak-utility-header",
      spartrakMegaNav: "js/spartrak-mega-nav",
      spartrakMobileDrawer: "js/spartrak-mobile-drawer",
      spartrakAuth: "js/spartrak-auth",
      spartrakPlpFilterToggle: "js/spartrak-plp-filter-toggle",
      spartrakSearchSuggest: "js/spartrak-search-suggest",
      spartrakHomeCarousel: "js/spartrak-home-carousel",
      spartrakHomeTiles: "js/spartrak-home-tiles",
      spartrakHomeVideo: "js/spartrak-home-video",
      spartrakCascadeSearch: "js/spartrak-cascade-search",
      spartrakQuickSearch: "js/spartrak-quick-search",
      spartrakToast: "js/spartrak-toast",
      // Magento's own validation widget with one option changed — where the
      // error message is inserted. Core puts it after the input, which inside
      // this theme's 48px flex input band made it a flex item beside the
      // value. See js/spartrak-form-validation.js.
      spartrakFormValidation: "js/spartrak-form-validation",
      spartrakCartQty: "js/spartrak-cart-qty",
      spartrakReviewDialog: "js/spartrak-review-dialog",
      // The shared native <dialog> driver — the contact panel and the
      // signed-out panel. See js/spartrak-dialog.js for why it is not
      // folded into spartrakReviewDialog above (that one owns a form and
      // its validation; this one owns a fragment entry point).
      spartrakDialog: "js/spartrak-dialog",
      // Restores the one thing dropped with core's process-reviews module:
      // the rating summary's review count opens the reviews tab. The AJAX
      // review list that module also carried stays gone — see the file.
      spartrakReviewsTabLink: "js/spartrak-reviews-tab-link",
      // Native <details> disclosure + light dismiss. Replaces Magento's
      // dropdownDialog on the language and currency switchers, which pulled
      // the whole jQuery UI dialog tree (10 modules, 97,819 parsed bytes)
      // to open a two-item list. See js/spartrak-disclosure.js.
      spartrakDisclosure: "js/spartrak-disclosure",
      // Raises the PROJECT loader for a full page reload, which is the
      // one loading state Magento has no event for — every control on
      // the category page is a link (see that file's header). Not a
      // second loader: it toggles a class on <html> and the overlay
      // components/_loader.less already styles does the rest.
      spartrakNavPending: "js/spartrak-nav-pending",
      spartrakMinicartDefer: "js/spartrak-minicart-defer",
    },
  },
  /*
   * Both mixins serve one thing Figma's cart drawer asks for and core's
   * minicart does not do: a quantity DROPDOWN with no Update button beside
   * it (820:16477).
   *
   *   view/cart-item-renderer
   *                  builds the option list, and keeps the line's own
   *                  quantity in it when it sits above the configured cap.
   *   js/sidebar     commits on change, instead of revealing a button that
   *                  the design does not draw.
   *
   * The FIRST one is on cart-item-renderer and not on view/minicart, which is
   * a correction. `$parent` inside Magento_Checkout/minicart/item/default is
   * the ITEM RENDERER, not the minicart view: content.html renders each line
   * through `<each args="$parent.getRegion(...)" render="{data: item}">`, so
   * the template's own parent context is the renderer element that `each` is
   * iterating. That is why core's item template calls
   * `$parent.getProductNameUnsanitizedHtml()` — a cart-item-renderer method —
   * while reaching the minicart view as `$parents[1]`.
   *
   * Both extend core in place. Neither replaces a core module, and neither
   * adds a request — a mixin is merged into the same bundle as its target.
   */
  config: {
    mixins: {
      "Magento_Checkout/js/view/cart-item-renderer": {
        "js/spartrak-minicart-qty-options-mixin": true,
      },
      "Magento_Checkout/js/sidebar": {
        "js/spartrak-minicart-qty-mixin": true,
      },
      /*
       * Guest checkout is disabled, so core asks a signed-out shopper to
       * sign in before checkout - and it does that with MAGENTO'S popup,
       * not this theme's modal. Both the minicart drawer
       * (Magento_Checkout/js/sidebar) and the cart page button
       * (proceed-to-checkout) reach it through this one model, so
       * wrapping showModal() here covers every caller at once instead of
       * patching each of them.
       */
      "Magento_Customer/js/model/authentication-popup": {
        "js/spartrak-auth-popup-mixin": true,
      },
      /*
       * PDP product video. A MIXIN and not a widget, because everything
       * it needs is already in the gallery's own configuration —
       * `videoUrl` on each entry of getGalleryImagesJson() — so it needs
       * no block, no template, no layout node and no data-mage-init. It
       * merges into mage/gallery/gallery's bundle, which every PDP
       * already downloads, so it adds no request; a product with no
       * video pays for one array scan and returns.
       *
       * spartrak_rtl adds a SECOND mixin to this same module
       * (js/spartrak-gallery-rtl-mixin). RequireJS merges `config.mixins`
       * down the theme chain rather than replacing it, so both apply on
       * the Arabic store. They cannot conflict: that one reverses the
       * frame array, this one reads whichever frame is active.
       */
      "mage/gallery/gallery": {
        "js/spartrak-pdp-video-mixin": true,
      },
      /*
       * The layered navigation's AJAX filtering, sorting and pagination
       * used Mageplaza's own overlay and its loader-1.gif, so the
       * storefront had two loading treatments depending on whether a
       * click happened to be intercepted for AJAX. CLAUDE.md section 10
       * allows exactly one, and the project loader costs no request.
       *
       * The MODEL and not the action: submit-filter, view/layer's pager,
       * sort and limiter handlers and the mobile drawer all raise the
       * loader through this single object, so replacing its two methods
       * covers every one of them at once.
       */
      "Mageplaza_AjaxLayer/js/model/loader": {
        "js/mageplaza-ajax-loader-mixin": true,
      },
    },
  },
};
