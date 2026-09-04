/**
 * Spartrak — the cart page's quantity control.
 *
 * Figma draws two different controls for one field: a dropdown on the desktop
 * cart (820:16449, 83x48) and a `− 1 +` stepper on the mobile cart
 * (669:13034). This widget is what makes those the SAME field.
 *
 * ===========================================================================
 * ONE FORM FIELD, TWO PRESENTATIONS
 * ===========================================================================
 * The template renders exactly one quantity input per line: a native <select>
 * named cart[<id>][qty]. The stepper is built HERE, at runtime, and does
 * nothing but write into that select. Two elements sharing the name
 * cart[<id>][qty] would both be submitted and the last would silently win —
 * which is the bug this design invites and this structure makes impossible.
 *
 * It is also why the stepper is created in JavaScript rather than printed by
 * the template: markup that cannot work without a script should not exist
 * without it. With scripting off, the native <select> stays visible on every
 * viewport and the cart remains completely usable.
 *
 * ===========================================================================
 * IT DOES NOT SUBMIT THE FORM ITSELF
 * ===========================================================================
 * It clicks core's own update button. That button carries
 * name="update_cart_action" value="update_qty" and is wired to
 * Magento_Checkout/js/action/update-shopping-cart, which validates the
 * quantities against checkout/cart/updateItemQty before posting. Submitting
 * the form directly from here would skip that validation and lose the action
 * flag, so the cart would post without knowing what it was being asked to do.
 *
 * Reusing the button also means this file has no opinion about the request:
 * whatever core does today, and whatever it does after an upgrade, is what
 * happens.
 *
 * ===========================================================================
 * WHY THE DEBOUNCE IS NOT OPTIONAL
 * ===========================================================================
 * A shopper raising a quantity from 1 to 4 taps `+` four times. Without a
 * delay that is four full cart round trips, three of them already stale, and
 * on a slow connection the answers can arrive out of order and leave the cart
 * showing 2. One trailing submit per burst is both fewer requests and the only
 * way the result is deterministic.
 */
define(["jquery", "jquery-ui-modules/widget"], function ($) {
  "use strict";

  /**
   * ===================================================================
   * THE WIDGET NAME HAS TO MATCH THE data-mage-init KEY, AND IT DID NOT
   * ===================================================================
   * jQuery UI derives the jQuery method from the part AFTER the namespace, so
   * `spartrak.cartQty` created `$.fn.cartQty`. cart/form.phtml initialises it
   * with `"spartrakCartQty": {}`, and mage/apply/main resolves a data-mage-init
   * key by calling `$(el)[key](config)` — `$(el).spartrakCartQty`, which did
   * not exist.
   *
   * So the module loaded, the widget was defined, and nothing was ever
   * instantiated: no change handler, no debounced commit, no stepper. That is
   * why changing the quantity did nothing at all.
   *
   * The name is the full `spartrakCartQty` for that reason. It reads
   * redundantly beside the namespace and it is the only spelling that works:
   * the requirejs alias, the data-mage-init key and `$.fn.<name>` are three
   * places that have to agree, and this is the one that makes them.
   */
  $.widget("spartrak.spartrakCartQty", {
    options: {
      line: "[data-spartrak-cart-line]",
      select: 'select[data-role="cart-item-qty"]',
      qtyField: ".spartrak-cart-line__qty",
      updateButton: ".spartrak-cart__update",
      stepperFlag: "data-spartrak-qty-stepper",

      /**
       * Long enough to absorb a burst of taps, short enough that a
       * single deliberate change still feels immediate.
       */
      commitDelay: 600,
    },

    /** @type {number|null} */
    commitTimer: null,

    _create: function () {
      this.element.find(this.options.line).each(
        function (index, line) {
          this._enhanceLine($(line));
        }.bind(this),
      );

      this._on(this.element, {
        'change select[data-role="cart-item-qty"]': "_scheduleCommit",
      });
    },

    /**
     * Builds the stepper for one line and marks the line as enhanced.
     *
     * The marker attribute is what the stylesheet keys on to swap the
     * select for the stepper at the mobile breakpoint — CSS decides WHEN
     * each presentation shows, this decides only that both are available.
     */
    _enhanceLine: function ($line) {
      var $select = $line.find(this.options.select);

      // A line with a single possible quantity renders as static text and
      // has no select at all. Nothing to step through.
      if (!$select.length) {
        return;
      }

      var $stepper = $("<div/>", {
        class: "spartrak-cart-line__stepper",
        // The buttons are labelled individually; the group needs no
        // role of its own, but it must not be announced as a control
        // that duplicates the select.
        "aria-hidden": "false",
      });

      var $decrease = this._stepperButton(
        "decrease",
        "−",
        $.mage.__("Decrease quantity"),
      );
      var $value = $("<span/>", {
        class: "spartrak-cart-line__stepper-value",
        // The <select> is the labelled control; this is a readout of
        // it, so it is hidden from assistive tech to avoid announcing
        // the quantity twice.
        "aria-hidden": "true",
        text: $select.val(),
      });
      var $increase = this._stepperButton(
        "increase",
        "+",
        $.mage.__("Increase quantity"),
      );

      $stepper.append($decrease, $value, $increase);
      $line.find(this.options.qtyField).append($stepper);
      $line.attr(this.options.stepperFlag, "");

      this._on($decrease, {
        click: function () {
          this._step($select, -1);
        }.bind(this),
      });
      this._on($increase, {
        click: function () {
          this._step($select, 1);
        }.bind(this),
      });

      // Keeps the readout and the disabled states honest however the
      // value changed — stepper, native picker, or browser autofill.
      this._on($select, {
        change: function () {
          this._syncStepper($select, $value, $decrease, $increase);
        }.bind(this),
      });

      this._syncStepper($select, $value, $decrease, $increase);
    },

    _stepperButton: function (kind, glyph, label) {
      return $("<button/>", {
        type: "button",
        class:
          "spartrak-cart-line__stepper-button spartrak-cart-line__stepper-button--" +
          kind,
        "aria-label": label,
        text: glyph,
      });
    },

    /**
     * Moves the select to the adjacent option.
     *
     * Steps by OPTION rather than by arithmetic: the option list is bounded
     * by stock and by the merchant's cap (see
     * Spartrak\Checkout\ViewModel\CartQtyOptions), so walking it can never
     * land on a quantity the shopper is not allowed to buy — where value+1
     * could.
     */
    _step: function ($select, direction) {
      var index = $select.prop("selectedIndex") + direction;

      if (index < 0 || index >= $select.prop("options").length) {
        return;
      }

      $select.prop("selectedIndex", index).trigger("change");
    },

    _syncStepper: function ($select, $value, $decrease, $increase) {
      var index = $select.prop("selectedIndex"),
        last = $select.prop("options").length - 1;

      $value.text($select.val());
      $decrease.prop("disabled", index <= 0);
      $increase.prop("disabled", index >= last);
    },

    /**
     * One trailing commit per burst of changes. See the header.
     */
    _scheduleCommit: function () {
      if (this.commitTimer !== null) {
        window.clearTimeout(this.commitTimer);
      }

      this.commitTimer = window.setTimeout(
        function () {
          this.commitTimer = null;
          this._commit();
        }.bind(this),
        this.options.commitDelay,
      );
    },

    _commit: function () {
      var $update = this.element.find(this.options.updateButton);

      // If core's update button is not on the page, the safe thing is to
      // do nothing: the shopper's selection stays visible and unsaved
      // rather than being posted through a path this file invented.
      if ($update.length) {
        $update.trigger("click");
      }
    },

    _destroy: function () {
      if (this.commitTimer !== null) {
        window.clearTimeout(this.commitTimer);
        this.commitTimer = null;
      }
    },
  });

  return $.spartrak.spartrakCartQty;
});
