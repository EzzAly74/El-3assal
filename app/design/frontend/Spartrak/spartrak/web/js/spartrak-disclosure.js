/**
 * Spartrak — light-dismiss for a native <details> disclosure.
 *
 * ===========================================================================
 * WHAT THIS REPLACES, AND WHAT THAT COST
 * ===========================================================================
 * Porto's language and currency switchers open their panel with Magento's
 * `dropdownDialog` widget. That widget is `mage/dropdown`, whose first
 * dependency is `jquery-ui-modules/dialog` — a draggable, resizable, modal
 * dialog implementation being used here to show a two-item list.
 *
 * Measured on the live Arabic checkout (Lighthouse, 2026-09-05, incognito),
 * one `data-mage-init='{"dropdownDialog": ...}'` attribute pulled:
 *
 *     mage/dropdown                        1,040 B      3,718 B parsed
 *     jquery-ui-modules/dialog             4,122 B     15,647 B
 *     jquery-ui-modules/widgets/button     2,108 B      7,446 B
 *     jquery-ui-modules/widgets/draggable  5,170 B     22,190 B
 *     jquery-ui-modules/widgets/mouse      1,122 B      3,957 B
 *     jquery-ui-modules/widgets/resizable  5,848 B     22,435 B
 *     jquery-ui-modules/position           2,820 B     10,587 B
 *     widgets/controlgroup + checkboxradio 3,185 B     10,636 B
 *     jquery-ui-modules/form-reset-mixin     500 B      1,203 B
 *     ------------------------------------------------------------
 *                              10 requests  25,915 B   97,819 B parsed
 *
 * — several serial RequireJS layers deep, because a module is only discovered
 * once its requirer has executed.
 *
 * ===========================================================================
 * WHY <details> AND NOT A SMALLER WIDGET
 * ===========================================================================
 * A disclosure is a solved problem in HTML. `<details>`/`<summary>` gives the
 * open/close state, the click handling, the focusability, the Enter/Space
 * keyboard behaviour and the implicit `aria-expanded` for free, in every
 * browser since 2020, and it keeps working with JavaScript switched off.
 *
 * That is also an ACCESSIBILITY improvement over what it replaces, not just a
 * performance one: Porto's trigger is a bare `<div class="action toggle">`
 * with no tabindex and no role, so the language switcher could not be reached
 * or operated from the keyboard at all (CLAUDE.md section 15).
 *
 * The one thing `<details>` does not do natively is LIGHT DISMISS — closing
 * when a click lands outside it, or when Escape is pressed. `dropdownDialog`
 * did do that (`closeOnClickOutside` defaults to true and neither switcher
 * overrides it), so it is behaviour this has to preserve rather than drop.
 * That is the whole of what this file is, and it is why it is ~30 lines of
 * plain DOM rather than a widget: no jQuery, no widget factory, no
 * dependencies whatsoever, so the `define` array below is genuinely empty and
 * this module pulls nothing else in behind it.
 *
 * ===========================================================================
 * WHY IT IS GENERIC AND NOT "THE LANGUAGE SWITCHER'S SCRIPT"
 * ===========================================================================
 * Both switchers need it, and they render in different places — the language
 * control is moved into the support strip, the currency control stays in the
 * header — so an element-scoped `data-mage-init` on each `<details>` is the
 * only wiring that survives either of them being moved again. The document
 * listeners are registered once no matter how many elements opt in.
 */
define([], function () {
    'use strict';

    /**
     * Every <details> that has opted into light dismiss.
     *
     * @type {HTMLDetailsElement[]}
     */
    var registry = [],
        listening = false;

    /**
     * @param {HTMLDetailsElement} [except]
     */
    function closeAll(except) {
        registry.forEach(function (element) {
            if (element !== except && element.open) {
                element.open = false;
            }
        });
    }

    /**
     * Document-level handlers, attached at most once.
     *
     * Capture phase: the listener has to run before <summary>'s own default
     * toggle, so that clicking the trigger of a CLOSED panel closes any other
     * open one first and then opens this one, rather than the two fighting.
     */
    function listenOnce() {
        if (listening) {
            return;
        }

        listening = true;

        document.addEventListener('click', function (event) {
            registry.forEach(function (element) {
                if (element.open && !element.contains(event.target)) {
                    element.open = false;
                }
            });
        }, true);

        document.addEventListener('keydown', function (event) {
            // `key` and not `keyCode`: the latter is deprecated, and 'Esc' is
            // the legacy IE/Edge spelling that some Android keyboards still
            // report.
            if (event.key !== 'Escape' && event.key !== 'Esc') {
                return;
            }

            registry.forEach(function (element) {
                var summary;

                if (!element.open) {
                    return;
                }

                element.open = false;

                // Focus goes back to the control that opened the panel, or it
                // would be stranded on a now-hidden link.
                summary = element.querySelector('summary');

                if (summary) {
                    summary.focus();
                }
            });
        });
    }

    /**
     * `mage/apply/main` calls a module that exports a function as
     * `fn(config, element)`.
     *
     * @param {Object} config
     * @param {HTMLElement} element
     */
    return function (config, element) {
        if (!element || typeof element.open !== 'boolean' || registry.indexOf(element) !== -1) {
            return;
        }

        registry.push(element);

        // Opening one closes the others. Cheap here, and it means two
        // switchers side by side behave like one control group.
        element.addEventListener('toggle', function () {
            if (element.open) {
                closeAll(element);
            }
        });

        listenOnce();
    };
});
