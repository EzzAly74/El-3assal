/**
 * Spartrak — the checkout progress bar (Figma 817:22383).
 *
 * ===========================================================================
 * WHY THIS EXTENDS CORE'S COMPONENT INSTEAD OF REPLACING IT
 * ===========================================================================
 * Core's progress bar renders exactly `stepNavigator.steps()`, and that
 * collection contains only the steps registered INSIDE checkout — `shipping`
 * and `payment`. Figma draws three markers, and the first is **عربة التسوق**
 * (Cart): the page a shopper came FROM, always complete, and a link back to it.
 *
 * That marker can never come from the step navigator, because the cart is not a
 * checkout step — nothing registers it, it has no `isVisible` observable, and
 * navigating "to" it means leaving checkout entirely. Restyling core's template
 * could not add it, so the component is extended and the template swapped.
 *
 * EXTENDED, NOT SWAPPED WHOLESALE, AND THAT DISTINCTION IS LOAD-BEARING.
 * `Magento_Checkout/js/view/progress-bar` is not only a renderer: its
 * `initialize()` is what binds `hashchange` to the step navigator, seeds the
 * opening hash when a shopper lands on `/checkout` with none, and calls
 * `handleHash()` to put the right step on screen. It is the only thing in
 * checkout that does that. Pointing the layout node at a `uiComponent` of our
 * own would have taken all of it off the page, and the symptom — checkout opens
 * on a blank step, and the browser's Back button stops moving between steps —
 * looks nothing like "the progress bar was changed". Extending keeps every bit
 * of it and adds one marker.
 *
 * The two real markers are still read live from `stepNavigator`, so a module
 * that registers a third checkout step appears in this bar with no change here.
 *
 * ===========================================================================
 * WHAT THIS FILE DELIBERATELY DOES NOT DO
 * ===========================================================================
 * It carries no icon paths and no colours. Each marker is emitted with a
 * modifier class derived from its own step code (`--cart`, `--shipping`,
 * `--payment`), and the theme's stylesheet hangs the Figma glyph off that
 * class. So the design can change which icon a step wears without touching
 * JavaScript — CLAUDE.md §7's content/presentation split applied to a KO
 * component.
 */
define([
    'underscore',
    'mage/url',
    'Magento_Checkout/js/view/progress-bar',
    'Magento_Checkout/js/model/step-navigator',
    'mage/translate'
], function (_, url, Component, stepNavigator, $t) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Spartrak_Checkout/progress-bar'
        },

        /** @inheritdoc */
        initialize: function () {
            // Core's initialize() does the hash wiring. It must run.
            this._super();

            this.cartUrl = url.build('checkout/cart/');

            return this;
        },

        /**
         * The markers, in reading order, as plain view models.
         *
         * A method rather than three separate template expressions: the
         * template should ask one question — "what do I draw?" — instead of
         * re-deriving the step model inline for every marker.
         *
         * @returns {Array<Object>}
         */
        markers: function () {
            var markers = [{
                code: 'cart',
                /**
                 * 'Shopping basket', NOT 'Shopping cart', and the difference is
                 * not cosmetic. Figma labels this marker عربة التسوق (817:22383)
                 * while it labels the cart PAGE سلة التسوق — two different words
                 * for two different things.
                 *
                 * Magento keys translation on the source string alone, with no
                 * context mechanism, so both could not share 'Shopping cart':
                 * the cart page template already maps that key to سلة التسوق,
                 * and the theme dictionary was silently shadowing this module's
                 * own entry, printing the page's word on the step.
                 *
                 * 'Shopping basket' is not a new key — the theme dictionary
                 * already carries it, already mapped to عربة التسوق, for exactly
                 * this marker.
                 */
                title: $t('Shopping basket'),
                // Behind us by definition: a shopper cannot be on the checkout
                // page without having left the cart.
                complete: true,
                active: false,
                href: this.cartUrl,
                // No connector. Figma draws the line as belonging to the marker
                // it leads INTO — 817:22397 is a child of step two, and step
                // one has none.
                connector: false
            }];

            this.steps().sort(this.sortItems).forEach(function (step) {
                markers.push({
                    code: step.code,
                    title: step.title,
                    complete: stepNavigator.isProcessed(step.code),
                    active: step.isVisible(),
                    href: null,
                    step: step,
                    connector: true
                });
            });

            return markers;
        },

        /**
         * A marker is "reached" when the shopper is on it or has passed it.
         * Drives both the filled disc and the connector leading into it.
         *
         * @param {Object} marker
         * @returns {Boolean}
         */
        isReached: function (marker) {
            return marker.complete || marker.active;
        },

        /**
         * Jump to an already-completed step.
         *
         * Named apart from core's `navigateTo(step)` on purpose — that method
         * takes a STEP and is still used by core's own bindings; this one takes
         * a MARKER, which is a different shape. Overloading one name for two
         * argument types is how the cart marker would end up in
         * `stepNavigator.navigateTo('cart')`, which no step answers to.
         *
         * Only completed steps move: clicking ahead to a step the quote is not
         * ready for is what produces a half-populated payment screen.
         *
         * @param {Object} marker
         * @returns {Boolean}
         */
        goToStep: function (marker) {
            if (marker.complete && marker.step) {
                stepNavigator.navigateTo(marker.step.code);
            }

            return false;
        },

        /**
         * "الرجوع" (817:22427) — one step back along the funnel.
         *
         * On the first checkout step there is no earlier step, so back means
         * the cart. That is why this reads the CURRENT step rather than being
         * handed a fixed target.
         *
         * @returns {Boolean}
         */
        back: function () {
            var steps = this.steps().sort(this.sortItems),
                current = _.findIndex(steps, function (step) {
                    return step.isVisible();
                });

            if (current > 0) {
                stepNavigator.navigateTo(steps[current - 1].code);

                return false;
            }

            window.location.href = this.cartUrl;

            return false;
        }
    });
});
