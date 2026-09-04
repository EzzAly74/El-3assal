/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 *
 * The address book's add/edit dialog — Figma 557:5173.
 *
 * ===========================================================================
 * IT INTERCEPTS LINKS, IT DOES NOT REPLACE THEM
 * ===========================================================================
 * "اضف عنوان جديد" and every card's "تعديل" are real anchors to real pages,
 * and they still are. This binds a click handler that calls preventDefault()
 * and opens the dialog instead — so the feature is an enhancement layered over
 * a page that already works, rather than a set of dead `href="#"` stubs that
 * need JavaScript to do anything at all.
 *
 * The same reasoning runs through the form it loads: that is a real <form>
 * posting to core's own `customer/address/formPost`, so submitting it is a
 * normal navigation and this file owns no save path (see
 * Magento_Customer/templates/address/spartrak-form.phtml). If the fetch fails,
 * the shopper is sent to the page the anchor pointed at.
 *
 * ===========================================================================
 * WHY MAGENTO'S MODAL AND NOT OUR OWN
 * ===========================================================================
 * `Magento_Ui/js/modal/modal` emits `.modal-inner-wrap`, `.modal-header`,
 * `.modal-title` and `.action-close` — the exact DOM that
 * components/_checkout.less already dresses as this 800px dialog for the
 * checkout, down to the close control's 32px circle. It brings the focus trap,
 * the Escape binding, the overlay and the aria-modal wiring with it. A second
 * dialog would mean a second implementation of all of that, plus a second
 * stylesheet for a box the design says is the same box.
 *
 * ===========================================================================
 * ONE FETCH PER OPEN, AND WHY IT IS NOT CACHED
 * ===========================================================================
 * The response embeds a form key and the address's current values, both of
 * which go stale — the key when the session rotates, the values the moment a
 * save succeeds. A cached fragment would eventually post a dead key or show a
 * shopper the address they had just changed.
 *
 * A PLAIN FUNCTION, NOT A jQuery WIDGET: `x-magento-init` keyed by a module
 * path calls the returned function as `fn(config, element)`, which is the
 * signature this module's own js/account-edit.js already uses. Declaring a
 * widget would need its name resolved separately and buys nothing here.
 */
define(["jquery", "Magento_Ui/js/modal/modal", "mage/translate"], function (
  $,
  modal,
  $t,
) {
  "use strict";

  var SELECTOR = {
    newTrigger: "[data-spartrak-address-new]",
    editTrigger: "[data-spartrak-address-edit]",
    cancel: "[data-spartrak-address-cancel]",
    defaultToggle: "[data-spartrak-address-default]",
    defaultMirror: "[data-spartrak-address-default-mirror]",
  };

  return function (config, element) {
    var $host = $(element),
      hasModal = false,
      loading = false;

    /**
     * Built once and re-titled thereafter. Destroying and rebuilding the
     * widget on every open leaks the overlay it appends to <body>.
     *
     * @param {String} title
     */
    function ensureModal(title) {
      if (hasModal) {
        $host.modal("setTitle", title);

        return;
      }

      modal(
        {
          type: "popup",
          /*
           * The class the checkout's own dialog carries — which is what
           * makes this the 800px Figma box with no stylesheet of its own.
           * The `--account` modifier is a hook for anything that ever
           * needs to tell the two apart; nothing uses it yet.
           */
          modalClass: "spartrak-address-modal spartrak-address-modal--account",
          title: title,
          /*
           * Empty: Figma puts the actions INSIDE the form (557:5194), so
           * the button row the widget would otherwise draw underneath
           * them would be a second, emptier footer.
           */
          buttons: [],
          clickableOverlay: true,
        },
        $host,
      );

      hasModal = true;
    }

    /**
     * @param {String|null} addressId  Null for "add".
     * @param {String} title
     */
    function open(addressId, title) {
      if (loading) {
        return;
      }

      loading = true;

      $.ajax({
        url: config.formUrl,
        type: "GET",
        dataType: "html",
        data: addressId ? { id: addressId } : {},
        cache: false,
        /* Picks up the theme's own spinner — see CLAUDE.md §10. */
        showLoader: true,
      })
        .done(function (html) {
          $host.html(html);

          /*
           * ===============================================================
           * RUN MAGENTO'S OWN INITIALISER OVER THE FRAGMENT
           * ===============================================================
           * Magento scans for `data-mage-init` once, at page load, so markup
           * injected afterwards is never initialised. The form carries
           * `data-mage-init='{"validation":{}}'` — and until this call
           * existed, that attribute and every `required` / `data-validate`
           * below it were decoration: the form is `novalidate` (deliberately,
           * so the platform's widget owns the messages rather than the
           * browser's bubbles), and nothing had ever turned the widget on. A
           * shopper submitting an empty required field got a server round trip
           * and a redirect instead of an inline message.
           *
           * `mage.apply()` rather than `$form.validation()`: it reads the
           * declaration out of the template, so the rules have ONE home and a
           * future field's own `data-mage-init` is picked up here for free.
           *
           * IT IS SAFE TO RE-RUN. Both of Magento's initialisers consume their
           * own markers — `apply()` strips the `data-mage-init` attribute as it
           * reads it, and its script processor removes each
           * `text/x-magento-init` node from the DOM — so a second pass finds
           * only what has not been initialised yet. Nothing already bound on
           * this page is bound twice, including this dialog.
           *
           * REQUIRED LAZILY, inside the handler, so the validation bundle is
           * fetched the first time somebody opens the form rather than on
           * every account page load (CLAUDE.md section 4).
           */
          require(["mage/apply/main"], function (mage) {
            mage.apply();
          });

          ensureModal(title);
          $host.modal("openModal");
        })
        .fail(function () {
          /*
           * Exactly what the anchor would have done. A dialog that fails
           * silently leaves the shopper pressing a control that does
           * nothing; navigating is the behaviour they had before this
           * file existed.
           */
          window.location.href = addressId
            ? config.editUrlPrefix + addressId + "/"
            : config.newUrl;
        })
        .always(function () {
          loading = false;
        });
    }

    /*
     * Delegated from the document: one listener instead of one per card,
     * and it cannot go stale if the list is ever re-rendered.
     */
    $(document).on("click", SELECTOR.newTrigger, function (event) {
      event.preventDefault();
      open(null, config.addTitle || $t("Add a new shipping address"));
    });

    $(document).on("click", SELECTOR.editTrigger, function (event) {
      event.preventDefault();
      open(
        $(this).attr("data-spartrak-address-edit"),
        config.editTitle || $t("Edit shipping address"),
      );
    });

    /* Both live inside the fetched fragment, so both are delegated too. */
    $host.on("click", SELECTOR.cancel, function (event) {
      event.preventDefault();

      if (hasModal) {
        $host.modal("closeModal");
      }
    });

    /*
     * Keeps `default_billing` in step with the one control Figma draws.
     * This storefront collects a single kind of address, so the two Magento
     * defaults must never diverge — the reasoning is in
     * Controller\Address\SetDefault, which applies the same rule from the
     * card. With no script the form posts `default_shipping` alone, which
     * is the honest degradation for a shipping address book.
     */
    $host.on("change", SELECTOR.defaultToggle, function () {
      $host
        .find(SELECTOR.defaultMirror)
        .prop("checked", $(this).prop("checked"));
    });
  };
});
