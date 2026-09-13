/**
 * THE PROJECT LOADER'S ON/OFF SWITCH.
 *
 * ===========================================================================
 * WHY THIS EXISTS AS ITS OWN MODULE
 * ===========================================================================
 * EXTRACTED FROM js/spartrak-nav-pending.js, 2026-09-10, when the layered
 * navigation's AJAX filtering needed to raise the SAME overlay. It was a pair
 * of private functions on that file plus the class name and the fallback
 * timer.
 *
 * CLAUDE.md section 10 is explicit: the loading experience is ONE global
 * component, "implemented once, at the appropriate global integration point,
 * and reused everywhere". Two callers now need it, so the switch moved here
 * rather than being written twice — a second copy of `spartrak-navigating`
 * and a second fallback timer would be exactly the drift that rule forbids,
 * and the two could disagree about whether the overlay is up.
 *
 * Consumers:
 *   js/spartrak-nav-pending.js            full page loads from a refinement
 *   js/mageplaza-ajax-loader-mixin.js     AJAX filtering, sort and pagination
 *
 * ===========================================================================
 * WHAT IT DRIVES, AND WHAT IT DOES NOT OWN
 * ===========================================================================
 * One class on <html>. The overlay itself is authored up front by
 * Magento_Theme/templates/html/nav-loader.phtml and revealed by
 * `.spartrak-navigating .spartrak-nav-loader` in components/_loader.less —
 * this file owns no markup and no styling, which is what keeps the spinner's
 * appearance a single decision in the design system.
 *
 * The flag sits on <html> rather than <body> so it is set as early as possible
 * in the document and cannot be disturbed by anything a component does to the
 * body's class list.
 *
 * ===========================================================================
 * PERFORMANCE
 * ===========================================================================
 * No jQuery — this is two classList calls and a timer, and taking a dependency
 * on jQuery to toggle a class would be the more expensive of the two ways to
 * write it. The overlay is already in the document from the first paint and is
 * `display: none` until shown, so raising it costs no request, no insertion
 * and no layout of new nodes.
 */
define([], function () {
  "use strict";

  var SHOWING = "spartrak-navigating",
    /**
     * How long the overlay may stand with nothing having replaced or
     * finished the thing it was raised for. Generous on purpose: it exists
     * to recover from a navigation that never happened or a request that
     * never settled, not to time out a slow one, and hiding it while the
     * work is genuinely still going would be worse than not showing it.
     */
    FALLBACK_MS = 10000,
    root = document.documentElement,
    fallbackTimer = null;

  function hide() {
    if (fallbackTimer) {
      window.clearTimeout(fallbackTimer);
      fallbackTimer = null;
    }

    root.classList.remove(SHOWING);
  }

  function show() {
    if (root.classList.contains(SHOWING)) {
      return;
    }

    root.classList.add(SHOWING);
    fallbackTimer = window.setTimeout(hide, FALLBACK_MS);
  }

  return {
    show: show,
    hide: hide,
  };
});
