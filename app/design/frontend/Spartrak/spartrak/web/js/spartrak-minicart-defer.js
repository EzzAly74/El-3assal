/**
 * Spartrak — boots the minicart drawer on first cart interaction, not on load.
 *
 * ===========================================================================
 * WHAT IT KEEPS OFF THE CRITICAL PATH, MEASURED
 * ===========================================================================
 * The header minicart had two independent eager requirers on every storefront
 * page, and between them they owned the largest single block of JavaScript
 * left on the homepage:
 *
 *   1. <script type="text/x-magento-init"> booting Magento_Ui/js/core/app for
 *      `minicart_content` -> Magento_Checkout/js/view/minicart, which declares
 *      `sidebar` and `mage/dropdown` at DEFINE time. mage/dropdown extends
 *      $.ui.dialog, so it drags in the whole jQuery UI dialog tree.
 *   2. data-mage-init='{"dropdownDialog": ...}' on the drawer. mage/apply/main
 *      resolves that at runtime, so it re-fetches mage/dropdown on its own even
 *      when no component asks for it.
 *
 * Measured on the live Arabic homepage (static version 1788943780) by building
 * the real define-time dependency graph from the deployed .min.js files and
 * taking the closure of the minicart's roots MINUS the closure of every other
 * init root on the page (wishlist, messages, storage-manager, customer-data,
 * section-config, invalidation-processor, form-key-provider, pageCache,
 * cookieStatus, mage/cookies, widget-initializer, wishlist-toggle, toast, and
 * the ten spartrak* data-mage-init widgets). 27 modules are reachable ONLY
 * from the minicart:
 *
 *     jquery/ui-modules/widgets/resizable          22,435
 *     jquery/ui-modules/widgets/draggable          22,190
 *     jquery/ui-modules/widgets/dialog             15,647
 *     jquery/ui-modules/effect                     15,117
 *     jquery/ui-modules/vendor/.../jquery.color    10,621
 *     jquery/ui-modules/position                   10,587
 *     mage/collapsible                              9,334
 *     jquery/ui-modules/widgets/button              7,446
 *     jquery/ui-modules/widgets/controlgroup        5,640
 *     jquery/ui-modules/widgets/checkboxradio       4,996
 *     Magento_Checkout/js/sidebar                   4,820
 *     jquery/ui-modules/widgets/mouse               3,957
 *     mage/dropdown                                 3,718
 *     Magento_Checkout/js/view/minicart              3,123
 *     mage/decorate                                 1,792
 *     jquery/ui-modules/form-reset-mixin            1,203
 *     Magento_Ui/js/modal/confirm                   1,109
 *     jquery/ui-modules/effects/effect-fade           599
 *     Magento_Ui/js/modal/alert                       593
 *     Magento_Msrp/js/view/.../subtotal/totals        500
 *     Magento_Customer/js/model/authentication-popup   459
 *     Magento_Tax/js/view/.../subtotal/totals         252
 *     Magento_Checkout/js/view/cart-item-renderer      232
 *     Magento_Catalog/js/view/image                    183
 *     jquery/ui-modules/jquery-var-for-color           181
 *     PayPal_Braintree/js/messages/mini-cart             0  (disabled)
 *     -----------------------------------------------------------------
 *     146,734 bytes over 26 requests, across three SERIAL waterfall layers
 *
 * (Magento_Ui/js/block-loader, 1,753 B, is the 27th and is deliberately LEFT
 * eager — see below. The two theme mixins on Magento_Checkout/js/sidebar and
 * view/cart-item-renderer travel with their targets, so they defer too and are
 * not counted here.)
 *
 * ===========================================================================
 * WHAT THIS DOES *NOT* CLAIM
 * ===========================================================================
 * It does not remove Knockout. knockout.min.js (164,810 B) stays on every
 * page, because three OTHER x-magento-init blocks boot Magento_Ui/js/core/app
 * independently of the minicart: Magento_Wishlist/js/view/wishlist,
 * Magento_Theme/js/view/messages and Magento_Catalog/js/storage-manager. The
 * measurement above already excludes everything those three reach, so the
 * 146,734 bytes are the minicart's own and nothing is double-counted.
 *
 * It does not delete those bytes either — a shopper who opens the cart still
 * downloads them. It moves them off page load and onto an interaction the
 * shopper initiated, which is the whole of the win.
 *
 * ===========================================================================
 * WHY THE BADGE IS NOT DEFERRED WITH IT
 * ===========================================================================
 * The count in the header has to be right the moment the page paints, so it
 * keeps its own eagerly-booted component: `minicart_counter` ->
 * Spartrak_Checkout/js/view/minicart-counter, ~1 KB, whose only dependencies
 * (uiComponent, ko, customer-data) are already on the page for the three
 * boots named above. That component was written for checkout, where the drawer
 * is suppressed outright; this change makes it the badge's component
 * everywhere, which is why checkout_index_index.xml no longer needs its own
 * copy of the wiring.
 *
 * Magento_Ui/js/block-loader stays eager for the same reason: the badge binds
 * `blockLoader: isLoading` and that module is what registers the binding.
 *
 * ===========================================================================
 * WHEN THE FETCH STARTS — AND WHY NOT ONLY ON THE TRIGGER
 * ===========================================================================
 * The first version of this file warmed on `mouseenter` / `focusin` /
 * `touchstart` on the cart icon alone, reasoning that all three precede
 * `click` in a real interaction. That was measured on the live Arabic
 * homepage and it was WRONG: a cold click took 6,036 ms to open the drawer.
 * The 26 modules arrive over three SERIAL waterfall layers, so the wait is
 * three origin round-trips deep however fast the link is, and hovering the
 * icon only buys the 200-500 ms between hover and click.
 *
 * So there are two warming paths, in the order they fire:
 *
 *   ENGAGEMENT  the first pointerdown / pointermove / keydown / touchstart /
 *               scroll anywhere on the document — armed only AFTER `load` and
 *               dispatched through requestIdleCallback. By the time a shopper
 *               reaches for the cart, this has long finished.
 *   TRIGGER     mouseenter / focusin / touchstart on the icon itself, which
 *               still covers a shopper whose very first action is the cart.
 *
 * A bare `click` with nothing warmed is handled too: the anchor's default is
 * prevented, the icon takes the busy state, and the drawer opens when ready.
 *
 * WHAT THIS GIVES UP, STATED PLAINLY. A visitor who interacts with the page at
 * all now downloads the 146,734 bytes — close to what plain idle prefetching
 * would have done, and the honest price of not shipping a six-second cart.
 * What is kept, and it is the part that matters, is that none of it is on the
 * CRITICAL PATH: nothing here can run before `load`, so LCP, FCP and total
 * blocking time are untouched. A visitor who loads a page and never touches it
 * still pays nothing.
 *
 * ===========================================================================
 * FALLBACK
 * ===========================================================================
 * The trigger is a real <a href> to the cart page. Every early return here
 * leaves that link untouched, so a shopper always has a working route to their
 * cart: if the drawer markup is absent (checkout suppresses it), if either
 * inert payload fails to parse, or if RequireJS cannot fetch the dialog tree,
 * the icon behaves as the plain link it already is. That is a fallback, not a
 * swallowed error — nothing here catches an exception and carries on as though
 * it had not happened.
 */
define([], function () {
  "use strict";

  var LAYOUT_SELECTOR =
      'script[type="application/json"][data-spartrak-minicart-layout]',
    DRAWER_SELECTOR = '[data-role="dropdownDialog"]',
    TRIGGER_SELECTOR = ".showcart",
    /**
     * Every one of these precedes `click` in a real interaction, and each
     * is bound `once` — the first to fire starts the download.
     */
    WARM_EVENTS = ["mouseenter", "focusin", "touchstart"],
    /**
     * First-interaction signals on the DOCUMENT. See ENGAGEMENT WARMING
     * below for why the trigger's own events are not sufficient.
     */
    ENGAGE_EVENTS = [
      "pointerdown",
      "pointermove",
      "keydown",
      "touchstart",
      "scroll",
    ];

  /**
   * @param {String} text
   * @returns {Object|null} null when `text` is not an object literal
   */
  function parseJson(text) {
    var value;

    if (typeof text !== "string" || !text.length) {
      return null;
    }

    try {
      value = JSON.parse(text);
    } catch (e) {
      return null;
    }

    return value && typeof value === "object" ? value : null;
  }

  /**
   * `mage/apply/main` calls a module that exports a function as
   * `fn(config, element)`.
   *
   * @param {Object} config
   * @param {HTMLElement} element The [data-block=minicart] wrapper
   */
  return function (config, element) {
    var drawer,
      trigger,
      layoutNode,
      layout,
      dialogConfig,
      booting = false,
      initialised = false,
      openWhenReady = false,
      componentReady = false,
      jq = null;

    if (!element) {
      return;
    }

    drawer = element.querySelector(DRAWER_SELECTOR);
    trigger = element.querySelector(TRIGGER_SELECTOR);
    layoutNode = element.querySelector(LAYOUT_SELECTOR);

    // No drawer means the page suppressed it (checkout). Nothing to defer,
    // and the badge's own component is already booted by the template.
    if (!drawer || !trigger || !layoutNode) {
      return;
    }

    layout = parseJson(layoutNode.textContent);
    dialogConfig = parseJson(drawer.getAttribute("data-spartrak-dropdown"));

    if (!layout || !dialogConfig) {
      return;
    }

    /**
     * Attach the widget once BOTH halves have arrived, and only then.
     *
     * The order matters and is not interchangeable. Magento's own
     * view/minicart binds `dropdowndialogopen` -> initSidebar() in its
     * module scope, i.e. as it is defined. Attaching the dialog before that
     * module exists would let the first open happen with no sidebar bound,
     * so the remove-item and quantity controls inside the drawer would be
     * dead until the shopper closed and reopened it. Waiting on
     * `minicart_content` in the registry is what rules that out.
     */
    function attach() {
      if (initialised || !componentReady || !jq) {
        return;
      }

      initialised = true;
      clearBusy();
      jq(drawer).dropdownDialog(dialogConfig);

      if (openWhenReady) {
        jq(drawer).dropdownDialog("open");
      }
    }

    /**
     * The waiting state, raised only when the shopper has actually clicked
     * — warming on hover must stay invisible.
     *
     * It is an attribute and not markup: components/_utility-header.less
     * pulses the cart icon off it with the keyframes
     * foundations/_spinner.less already emits, so the whole busy state
     * costs no DOM nodes, no document bytes and no second loader
     * implementation (CLAUDE.md section 10).
     */
    function setBusy() {
      element.setAttribute("data-spartrak-minicart-busy", "");
      trigger.setAttribute("aria-busy", "true");
    }

    function clearBusy() {
      element.removeAttribute("data-spartrak-minicart-busy");
      trigger.removeAttribute("aria-busy");
    }

    /**
     * The shopper is waiting on a drawer that will never arrive, so send
     * them to the page the trigger already points at.
     */
    function fallBackToCartPage() {
      if (openWhenReady) {
        window.location.assign(trigger.href);
      } else {
        clearBusy();
      }
    }

    function boot() {
      if (booting) {
        return;
      }

      booting = true;

      // Two require calls rather than one, so the Knockout tree and the
      // jQuery UI dialog tree download in PARALLEL. Bundled together,
      // app(layout) would sit behind mage/dropdown's three serial layers.
      require(["Magento_Ui/js/core/app", "uiRegistry"], function (
        app,
        registry,
      ) {
        app(layout);

        registry.get("minicart_content", function () {
          componentReady = true;
          attach();
        });
      }, fallBackToCartPage);

      require(["jquery", "mage/dropdown"], function ($) {
        jq = $;
        attach();
      }, fallBackToCartPage);
    }

    WARM_EVENTS.forEach(function (name) {
      trigger.addEventListener(name, boot, {
        passive: true,
        once: true,
      });
    });

    /**
     * ENGAGEMENT WARMING — and it is not optional.
     *
     * Warming on the trigger alone was measured and was not enough. A cold
     * click on the live Arabic homepage took 6,036 ms to open the drawer:
     * the 26 deferred modules arrive over THREE SERIAL waterfall layers, so
     * the wait is three origin round-trips deep no matter how fast the
     * connection is, and hovering the icon only buys the 200-500 ms
     * between hover and click. A six-second cart is a worse regression
     * than the load-time saving is a win.
     *
     * So the modules are fetched on the shopper's FIRST interaction with
     * the page — any of these events, once — by which time they are many
     * seconds ahead of a cart click.
     *
     * Two constraints make this safe rather than a way of quietly
     * un-deferring everything:
     *
     *   - It is armed only after `load`. Before that, pulling 145 KB could
     *     compete with the LCP image for connection and main thread, which
     *     is the exact thing this file exists to prevent.
     *   - It goes through requestIdleCallback, so it yields to anything
     *     still painting. The 2s timeout is the backstop for a page that
     *     never goes idle.
     *
     * A visitor who loads a page and never touches it still pays nothing,
     * which is the case ordinary idle-prefetching gets wrong.
     */
    function warmWhenIdle() {
      if (typeof window.requestIdleCallback === "function") {
        window.requestIdleCallback(boot, {
          timeout: 2000,
        });
      } else {
        window.setTimeout(boot, 200);
      }
    }

    function armEngagementWarming() {
      ENGAGE_EVENTS.forEach(function (name) {
        document.addEventListener(name, warmWhenIdle, {
          passive: true,
          once: true,
        });
      });
    }

    if (document.readyState === "complete") {
      armEngagementWarming();
    } else {
      window.addEventListener("load", armEngagementWarming, {
        once: true,
      });
    }

    trigger.addEventListener("click", function (event) {
      // Once the widget is attached its own handler owns the click —
      // dropdownDialog was configured with triggerTarget '.showcart'.
      if (initialised) {
        return;
      }

      event.preventDefault();
      openWhenReady = true;
      setBusy();
      boot();

      // Covers the case where warming already resolved both halves and
      // attach() was waiting on nothing but this flag.
      attach();
    });
  };
});
