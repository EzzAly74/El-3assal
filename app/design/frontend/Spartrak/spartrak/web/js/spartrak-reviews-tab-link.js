/**
 * Spartrak — the rating summary's review count opens the reviews tab.
 *
 * ===========================================================================
 * WHAT WAS BROKEN
 * ===========================================================================
 * Magento's rating summary in the product-info column renders
 *
 *     <a class="action view" href="<product url>#reviews">12 Reviews</a>
 *
 * (module-review/view/frontend/templates/helper/summary.phtml). `#reviews` is
 * the reviews tab PANEL, and Magento's tabs widget hides an inactive panel
 * with `display: none` — so the browser's own anchor jump has nothing to
 * scroll to and the click looks like it does nothing at all. It only appeared
 * to work when the reviews tab happened to already be the open one.
 *
 * ===========================================================================
 * WHY IT IS NOT FIXED BY PUTTING CORE'S MODULE BACK
 * ===========================================================================
 * Core owns this behaviour in Magento_Review/js/process-reviews, and this
 * theme's Magento_Review/templates/review.phtml deliberately dropped it. That
 * module's actual job is to FETCH THE REVIEW LIST over AJAX after paint; the
 * tab activation is a few lines at the bottom of it. This panel is
 * server-rendered aggregates with no list to fetch, so re-adding the module to
 * recover the tab behaviour would also re-add the request, the post-paint
 * layout shift and the JavaScript that the panel was rewritten to avoid
 * (CLAUDE.md sections 4 and 13).
 *
 * So the part that was worth keeping lives here, and the request stays gone.
 *
 * ===========================================================================
 * DEEP LINKS ALREADY WORK. THIS DOES NOT TOUCH THEM.
 * ===========================================================================
 * Landing on `<product url>#reviews` opens the tab on its own: mage/collapsible
 * `_processState()` reads window.location.hash when it initialises and
 * activates the panel whose id matches. That path was never broken. The gap is
 * only the click AFTER the page has loaded, where nothing re-runs — which is
 * also why this file does not write to the URL: a hash the shopper can already
 * arrive on is a separate, deliberate decision about shareable links.
 *
 * ===========================================================================
 * IT ASKS THE WIDGET FOR ITS STATE, IT DOES NOT READ THE MARKUP
 * ===========================================================================
 * `collapsible('option', 'active')` is the widget's own flag, kept in sync by
 * its _open()/_close(). The alternative — testing for the `active` class —
 * would hard-code a value that Porto passes IN
 * (`data-mage-init='{"tabs":{"openedState":"active"}}'` in its details.phtml)
 * and could change without this file knowing.
 *
 * The guard is not decoration: collapsible's activate() has no already-open
 * check and re-fires `beforeOpen`, which the tabs widget listens to in order
 * to force-deactivate every other panel. Calling it on the open tab is not
 * free.
 *
 * ===========================================================================
 * WHAT IT DELIBERATELY DOES NOT BIND
 * ===========================================================================
 * Only `.action.view`. Its sibling `.action.add` ("Add Your Review") already
 * belongs to js/spartrak-review-dialog.js, through the `externalTriggers`
 * option set in Magento_Review/templates/form.phtml. Binding both here would
 * give one link two owners.
 *
 * And only inside `.product-info-main`. The related-products rail renders the
 * same summary markup for OTHER products; hijacking those clicks would keep a
 * shopper on this page instead of following the link they pressed.
 */
define(['jquery', 'collapsible'], function ($) {
    'use strict';

    var NAMESPACE = '.spartrakReviewsTabLink';

    return function (config) {
        var settings = $.extend({
            link: '.product-info-main .reviews-actions .action.view',
            tabLabel: '#tab-label-reviews',
            // Measured, not assumed — see scrollTo() below.
            stickyHeader: '.spartrak-header'
        }, config || {});

        /**
         * How much of the viewport's top edge is covered by something pinned.
         *
         * The header is `position: sticky` on MOBILE ONLY (see
         * _utility-header.less), so on desktop this reads `static` and returns
         * zero without any breakpoint knowledge of its own. Measuring the live
         * element also means the header's height can change — a row added, the
         * search bar restyled — without this file drifting out of step, which
         * a hard-coded offset (core uses a literal 50) would not survive.
         */
        function stickyOffset() {
            var header = document.querySelector(settings.stickyHeader);

            if (!header || window.getComputedStyle(header).position !== 'sticky') {
                return 0;
            }

            return header.getBoundingClientRect().height;
        }

        $(document)
            .off('click' + NAMESPACE, settings.link)
            .on('click' + NAMESPACE, settings.link, function (event) {
                var $label = $(settings.tabLabel),
                    label = $label[0],
                    reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

                // Nothing to take over: no reviews tab on this page, or the
                // tabs widget has not initialised yet. Leave the click to the
                // browser rather than preventing a default we cannot replace.
                if (!label || !$label.data('mageCollapsible')) {
                    return;
                }

                event.preventDefault();

                if (!$label.collapsible('option', 'active')) {
                    $label.collapsible('activate');
                }

                // The tab LABEL, not the panel. The shopper has just changed
                // which tab is open and needs to see that happen; scrolling to
                // the panel alone puts the row that proves it above the fold.
                window.scrollTo({
                    top: window.scrollY + label.getBoundingClientRect().top - stickyOffset(),
                    behavior: reduceMotion ? 'auto' : 'smooth'
                });
            });
    };
});
