/**
 * Magento's validation widget, with ONE option changed: where the error goes.
 *
 * ===========================================================================
 * WHAT WAS WRONG
 * ===========================================================================
 * Reported with a screenshot of the account card: a malformed email address
 * printed "Please enter a valid email address (Ex: johndoe@domain.com)." in red
 * INSIDE the 48px input band, beside the value, in English.
 *
 * That is where core puts it. mage/validation's default errorPlacement ends in
 *
 *     errorPlacement.after(error)      // errorPlacement === the element
 *
 * so the message is inserted as the input's next sibling — and this theme's
 * input lives inside `.spartrak-account-field__band`, which is a 48px
 * `display: flex` row with `overflow: hidden`. The message therefore became a
 * FLEX ITEM sharing the band with the value it was complaining about, squeezing
 * the input and being clipped by the band's own overflow.
 *
 * No amount of colour or type work fixes that, because the defect is the
 * message's POSITION IN THE DOM, not its styling.
 *
 * ===========================================================================
 * WHY THIS AND NOT THE `.addon` HOOK
 * ===========================================================================
 * core does offer a structural hook — `element.closest('.addon')`, which moves
 * the error after the wrapper instead of after the input. Adding `addon` to the
 * band would have been one attribute.
 *
 * It was rejected on inspection: `.addon` carries styling of its own in
 * lib/web/css/source/lib/_forms.less — `inline-flex`, `width: 100%`,
 * `flex-basis: 100%` and an `order` on every descendant input — emitted
 * wherever the form-field mixin is invoked with a `.field` ancestor. Taking a
 * class for its JS behaviour and then having to out-specify the CSS that comes
 * with it is the specificity war CLAUDE.md section 9 rules out, and whether it
 * lands at all depends on Porto's compiled output rather than on anything this
 * theme states.
 *
 * Naming the container we actually own says the same thing with no borrowed
 * styling and no dependency on which branch of core's placement logic wins.
 *
 * ===========================================================================
 * IT REPLACES NO BEHAVIOUR
 * ===========================================================================
 * This is `$form.validation(options)` — the platform's own widget, with every
 * rule, message and trigger it ships. It is not a reimplementation, and it adds
 * no library: mage/validation was already being loaded on these pages by the
 * `"validation": {}` initialiser this stands in for. One option is passed that
 * JSON could not express, because errorPlacement is a function.
 *
 * Applies to the account card AND the address dialog, which are built from the
 * same `.spartrak-account-field` component — so the two cannot drift into
 * placing their errors differently.
 */
define(["jquery", "mage/validation"], function ($) {
  "use strict";

  /**
   * The field wrapper this theme draws, if the input is inside one.
   *
   * `.spartrak-account-field` is a column flex container with a 6px gap, so
   * appending puts the message under the band with the spacing the component
   * already defines — in flow, reserving its own height, which is what keeps a
   * validation error from shifting the layout around it.
   */
  var WRAPPER = ".spartrak-account-field";

  return function (config, element) {
    $(element).validation(
      $.extend({}, config, {
        /**
         * @param {jQuery} error
         * @param {jQuery} field
         */
        errorPlacement: function (error, field) {
          var wrapper = field.closest(WRAPPER);

          if (wrapper.length) {
            wrapper.append(error);

            return;
          }

          // Not one of this theme's fields — core's own final placement, so
          // anything else on the form behaves exactly as it did before.
          field.after(error);
        },
      }),
    );
  };
});
