/**
 * Click-and-drag scrolling for a horizontal scroll container.
 *
 * ===========================================================================
 * WHY THIS EXISTS AS ITS OWN MODULE
 * ===========================================================================
 * EXTRACTED FROM js/spartrak-home-carousel.js, 2026-09-10, when the mega nav's
 * category row needed the same gesture. It was `_enableDrag` on that widget,
 * coupled to `this.trackEl`, `this.suppressClick` and `this._stopAutoplay()`.
 * Copying it would have been the same 60 lines of pointer bookkeeping in two
 * places, free to drift apart — the duplication CLAUDE.md section 9 rules out —
 * so it moved here and both callers now share one implementation.
 *
 * Consumers:
 *   js/spartrak-home-carousel.js   the banner, product, tiles and brand rails
 *   js/spartrak-mega-nav.js        the header's scrolling category row
 *
 * ===========================================================================
 * WHY THE GESTURE NEEDS JS WHEN THE REST OF A RAIL DOES NOT
 * ===========================================================================
 * `overflow-x: auto` already gives touch swipe, trackpad scroll, keyboard and
 * momentum for free — which is why these rails work with no JS at all. The one
 * gesture browsers do NOT provide is dragging with a held mouse button, and on
 * desktop a shopper expects to grab a row and pull it. That gesture, and only
 * that gesture, is added here.
 *
 * Deliberately limited to a real mouse (`pointerType === 'mouse'`, primary
 * button). Touch and pen already scroll natively, and intercepting them would
 * replace a smooth, momentum-carrying native gesture with a worse hand-rolled
 * one.
 *
 * ===========================================================================
 * FIVE THINGS THAT WOULD OTHERWISE BREAK, HANDLED
 * ===========================================================================
 *   1. SCROLL-SNAP FIGHTS A DRAG — it keeps yanking the container back to the
 *      nearest snap point mid-gesture. Snapping is switched off for the
 *      duration and the element's own inline value restored on release, so a
 *      rail still settles onto a card afterwards and a container that never
 *      had snapping is left exactly as it was.
 *
 *   2. A DRAG THAT ENDS OVER A LINK WOULD FOLLOW IT. One click is suppressed,
 *      and only when the pointer actually travelled past THRESHOLD, so an
 *      ordinary click still works.
 *
 *      The flag is cleared on the next `pointerdown` as well as on the click it
 *      suppresses. That matters here in a way it did not inside the carousel:
 *      the mega nav's flyout panels are DOM DESCENDANTS of the scrolling row,
 *      so a click inside an open panel bubbles through this element. Clearing
 *      only on the suppressed click would let a drag that ended without any
 *      click at all (the pointer left the row) swallow the shopper's next
 *      click on a panel link instead. A press always precedes a click, so
 *      clearing on press makes the suppression exact.
 *
 *   3. THE BROWSER'S OWN IMAGE/TEXT DRAG would take over. Suppressed on this
 *      element only, and only while a drag is actually in progress.
 *
 *   4. A POINTER THAT LEAVES THE ELEMENT mid-drag must not leave it stuck in
 *      the dragging state — `pointercancel` and `pointerleave` end the gesture
 *      alongside `pointerup`.
 *
 *   5. A PRESS THAT DID NOT MEAN THE SCROLLER, via `options.ignore`. The mega
 *      nav's flyout panels are absolutely positioned DOM DESCENDANTS of the
 *      scrolling row, so a press inside an open 1003x540 panel bubbles to the
 *      row and would otherwise drag the category strip underneath it — and
 *      suppress the shopper's click on the way out. The carousel passes no
 *      `ignore` and behaves exactly as it did.
 *
 * ===========================================================================
 * PERFORMANCE
 * ===========================================================================
 * No library, no polyfill, no per-frame work: the listeners are idle until a
 * mouse button is held, and a move handler writes `scrollLeft`, which the
 * browser services on its own compositor path. Four listeners plus one
 * capture-phase click per container. `is-dragging` is a class toggle, so the
 * cursor swap is CSS (see foundations/_drag-affordance.less) and this module
 * owns no styling.
 *
 * Not passive: `pointermove` sets `scrollLeft` rather than calling
 * `preventDefault`, so passivity buys nothing, and `dragstart` MUST be able to
 * cancel.
 */
define([], function () {
    'use strict';

    /**
     * Travel, in CSS pixels, below which a press-and-release is a click rather
     * than a drag. Roughly the slop a mouse picks up between button-down and
     * button-up on an ordinary click.
     */
    var THRESHOLD = 5;

    /**
     * @param {HTMLElement} element  the scroll container itself
     * @param {Object}      [options]
     * @param {String}      [options.ignore]  CSS selector; a press whose target
     *                      is inside a match never starts a drag. See note 5.
     * @param {Function}    [options.onDragEnd]  called once per completed drag,
     *                      after the gesture is over and only when it travelled
     *                      past THRESHOLD. The carousel uses it to stop
     *                      autoplay — a drag is a deliberate slide change.
     * @returns {{isDragging: Function}} so a caller can ask whether a gesture
     *                      is in progress. The mega nav uses it to keep its
     *                      flyouts and scrim shut while the row is being
     *                      dragged past them.
     */
    return function dragScroll(element, options) {
        var opts = options || {},
            down = false,
            moved = 0,
            startX = 0,
            startScroll = 0,
            snap = '',
            suppressClick = false;

        /**
         * Is this press inside a region the caller has excluded? `closest` is
         * feature-tested because an event target is not always an Element.
         */
        function ignored(target) {
            return !!(opts.ignore && target && target.closest && target.closest(opts.ignore));
        }

        element.addEventListener('pointerdown', function (event) {
            // See note 2. FIRST, and unconditionally: any new press means the
            // previous gesture is over, whoever the press belongs to. A click is
            // always preceded by its own pointerdown, so clearing here is what
            // guarantees a finished drag can never swallow a later, unrelated
            // click — including one inside an ignored panel.
            suppressClick = false;

            if (event.pointerType !== 'mouse' || event.button !== 0) {
                return;
            }

            // See note 5.
            if (ignored(event.target)) {
                return;
            }

            down = true;
            moved = 0;
            startX = event.clientX;
            startScroll = element.scrollLeft;

            snap = element.style.scrollSnapType;
            element.style.scrollSnapType = 'none';
            element.classList.add('is-dragging');
        });

        element.addEventListener('pointermove', function (event) {
            if (!down) {
                return;
            }

            var delta = event.clientX - startX;

            moved = Math.max(moved, Math.abs(delta));

            // Physical movement, so it reads correctly in both directions
            // without a sign flip: the content follows the hand. RTL reports a
            // negative scrollLeft and this arithmetic is agnostic to that.
            element.scrollLeft = startScroll - delta;
        });

        ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (type) {
            element.addEventListener(type, function () {
                if (!down) {
                    return;
                }

                down = false;
                element.style.scrollSnapType = snap;
                element.classList.remove('is-dragging');

                if (moved > THRESHOLD) {
                    suppressClick = true;

                    if (typeof opts.onDragEnd === 'function') {
                        opts.onDragEnd();
                    }
                }
            });
        });

        // Capture phase: the suppression has to run before the link's own
        // handler, not after it has already navigated.
        element.addEventListener('click', function (event) {
            if (!suppressClick) {
                return;
            }

            suppressClick = false;

            // Belt and braces: with the flag cleared on every press this is not
            // currently reachable with an ignored target, but a click inside an
            // excluded panel is never this module's to cancel.
            if (ignored(event.target)) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
        }, true);

        element.addEventListener('dragstart', function (event) {
            if (down) {
                event.preventDefault();
            }
        });

        return {
            isDragging: function () {
                return down;
            }
        };
    };
});
