/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 *
 * The account card's two dialogs — Figma 821:17158 / overlay 1078:7263.
 *
 * ===========================================================================
 * WHAT IT GATES
 * ===========================================================================
 * The card holds two things Magento will not let a plain form change on its
 * own, and each gets the confirmation the platform actually requires:
 *
 *   PHONE     — `phone_number` is the login identifier and is not posted with
 *               the form at all. Changing it means proving you hold the NEW
 *               number: /phone-auth/otp/send, /phone-auth/otp/verify, then
 *               /phone-auth/account/phonechange, which is the only writer of
 *               the attribute. Nothing is saved until the code checks out, so
 *               abandoning the dialog leaves the shopper signing in with the
 *               number they had.
 *
 *   EMAIL     — core requires the current password
 *               (Account\EditPost::processChangeEmailRequest). The card has no
 *               password box (Figma draws none), so the password is collected
 *               in a dialog at submit time and injected into the card's own
 *               form.
 *
 * PASSWORD CHANGE WAS THE THIRD, AND IS GONE (2026-09-03). The card's تغيير
 * link opened this same dialog with two extra fields; both the link and the
 * fields were removed on an explicit product decision that the profile card
 * must not be able to change a password. What survives is the CURRENT-password
 * prompt above, which authorises an email change and nothing else — so this
 * file no longer has a mode, and `change_password` is never posted from here.
 * Magento's own change-password form (customer/account/edit?changepass=1) and
 * the OTP reset flow are untouched.
 *
 * ===========================================================================
 * THE PASSWORD DIALOG POSTS THROUGH THE CARD, NOT PAST IT
 * ===========================================================================
 * Its field is copied into `#form-validate` as a hidden input and the card is
 * submitted normally. So there is ONE save path — core's `editPost` — rather
 * than a second endpoint that can also change an account. That is the same
 * rule the address dialog follows for the same reason.
 *
 * ===========================================================================
 * WHY THE PHONE IS CHECKED ON SUBMIT AND NOT ON BLUR
 * ===========================================================================
 * A shopper editing the number and then changing their mind should not be
 * chased by a dialog. The comparison is against the SERVER-RENDERED value, so
 * typing a digit and deleting it again leaves the card at rest — the same rule
 * the submit gate applies to the email field.
 */
define(["jquery", "Magento_Ui/js/modal/modal", "mage/translate"], function (
  $,
  modal,
  $t,
) {
  "use strict";

  var SEL = {
    step: "[data-spartrak-dialog-step]",
    error: "[data-spartrak-dialog-error]",
    cancel: "[data-spartrak-dialog-cancel]",

    phoneField: "[data-spartrak-phone-field]",
    phoneInput: "[data-spartrak-phone-input]",
    phoneHint: "[data-spartrak-phone-hint]",

    otpBox: "[data-spartrak-otp-box]",
    otpSubmit: "[data-spartrak-otp-submit]",
    otpResend: "[data-spartrak-otp-resend]",
    otpPhoneValue: "[data-spartrak-otp-phone-value]",

    passwordSubmit: "[data-spartrak-password-submit]",
    currentPassword: "[data-spartrak-dialog-current-password]",
  };

  return function (config, element) {
    var $host = $(element),
      $form = $(config.formSelector),
      $phoneField = $form.find(SEL.phoneField),
      $phoneInput = $phoneField.find(SEL.phoneInput),
      /* As SERVER-RENDERED. See the header for why. */
      initialPhone = String($phoneField.data("currentPhone") || ""),
      hasModal = false,
      pendingPhone = "",
      verificationToken = "";

    /* ------------------------------------------------------------------
     * Dialog plumbing
     * ---------------------------------------------------------------- */

    function showStep(step, title) {
      $host.find(SEL.step).prop("hidden", true);
      $host
        .find('[data-spartrak-dialog-step="' + step + '"]')
        .prop("hidden", false);
      $host.find(SEL.error).prop("hidden", true).text("");

      if (!hasModal) {
        modal(
          {
            type: "popup",
            modalClass: "spartrak-address-modal spartrak-account-dialog-modal",
            title: title,
            buttons: [],
            clickableOverlay: false,
          },
          $host,
        );
        hasModal = true;
      } else {
        $host.modal("setTitle", title);
      }

      $host.modal("openModal");
    }

    function closeDialog() {
      if (hasModal) {
        $host.modal("closeModal");
      }
    }

    function fail(message) {
      $host
        .find(SEL.step + ":not([hidden])")
        .find(SEL.error)
        .text(message || $t("Something went wrong. Please try again."))
        .prop("hidden", false);
    }

    /**
     * Every endpoint in this file answers with the same envelope, so the
     * unwrapping is written once. A `message` on a failure is written to be
     * shown (see AbstractJsonAction); anything else is not surfaced.
     */
    function post(url, data) {
      return $.ajax({
        url: url,
        type: "POST",
        dataType: "json",
        data: $.extend({ form_key: $.mage.cookies.get("form_key") }, data),
        showLoader: true,
      });
    }

    /* ------------------------------------------------------------------
     * Phone change
     * ---------------------------------------------------------------- */

    function typedPhone() {
      var national = String($phoneInput.val() || "").replace(/\D/g, "");

      return national === ""
        ? ""
        : config.dialCode + national.replace(/^0+/, "");
    }

    function phoneChanged() {
      var typed = typedPhone();

      return typed !== "" && typed !== initialPhone;
    }

    function sendCode() {
      pendingPhone = typedPhone();

      return post(config.otpSend, {
        phone: pendingPhone,
        purpose: config.purpose,
      });
    }

    function openOtp() {
      sendCode()
        .done(function () {
          $host.find(SEL.otpPhoneValue).text(pendingPhone);
          $host.find(SEL.otpBox).val("");
          showStep("otp", config.otpTitle || $t("Enter the OTP code"));
          $host.find(SEL.otpBox).first().trigger("focus");
        })
        .fail(function (xhr) {
          /*
           * Not shown in the dialog: the dialog is not open yet, and the
           * reason is almost always about the number the shopper typed —
           * already in use, or their own. It belongs beside the field.
           */
          var payload = xhr.responseJSON || {};

          $phoneField
            .find(SEL.phoneHint)
            .text(
              payload.message || $t("We could not send a code to that number."),
            )
            .prop("hidden", false);
        });
    }

    function submitCode() {
      var code = $host
        .find(SEL.otpBox)
        .map(function () {
          return String($(this).val() || "").trim();
        })
        .get()
        .join("");

      if (code.length !== config.codeLength) {
        fail($t("Please enter the whole code."));

        return;
      }

      post(config.otpVerify, {
        phone: pendingPhone,
        purpose: config.purpose,
        code: code,
      })
        .done(function (response) {
          verificationToken = (response || {}).verification_token || "";

          post(config.phoneCommit, {
            phone: pendingPhone,
            verification_token: verificationToken,
          })
            .done(function () {
              /*
               * The number is committed. Submitting the card now saves
               * the rest of it — and reloads the page, so the field
               * re-renders from the stored value rather than being
               * patched in place.
               */
              initialPhone = pendingPhone;
              submitCard();
            })
            .fail(function (xhr) {
              fail((xhr.responseJSON || {}).message);
            });
        })
        .fail(function (xhr) {
          fail((xhr.responseJSON || {}).message);
        });
    }

    /* ------------------------------------------------------------------
     * Password / email confirmation
     * ---------------------------------------------------------------- */

    function openPassword() {
      $host.find(SEL.currentPassword).val("");

      showStep("password", config.confirmTitle || $t("Confirm your password"));
      $host.find(SEL.currentPassword).trigger("focus");
    }

    /**
     * Copies the dialog's field into the card's form as hidden inputs and
     * submits it. The named inputs live ONLY here, so a card submitted
     * without going through the dialog cannot carry a password at all.
     */
    function submitCard() {
      var current = String($host.find(SEL.currentPassword).val() || "");

      $form.find("[data-spartrak-injected]").remove();

      function inject(name, value) {
        $("<input>", { type: "hidden", name: name, value: value })
          .attr("data-spartrak-injected", "")
          .appendTo($form);
      }

      if (current !== "") {
        inject("current_password", current);
        inject("change_email", "1");
      }

      closeDialog();
      /* `submit()` on the element, not jQuery's `.submit()`: the latter
               would re-run this handler and loop. */
      $form.off("submit.spartrakAccount");
      $form.get(0).submit();
    }

    function submitPassword() {
      var current = String($host.find(SEL.currentPassword).val() || "");

      if (current === "") {
        fail($t("Please enter your current password."));

        return;
      }

      submitCard();
    }

    /* ------------------------------------------------------------------
     * Wiring
     * ---------------------------------------------------------------- */

    /*
     * One submit gate, in priority order: the phone needs a code before
     * anything is saved, and the email needs a password. Neither fires when
     * the shopper only touched their name, so the common save is untouched.
     */
    $form.on("submit.spartrakAccount", function (event) {
      if (phoneChanged()) {
        event.preventDefault();
        openOtp();

        return;
      }

      if (
        $form.find("[data-spartrak-injected]").length === 0 &&
        $form.find('[data-input="change-email"]').data("emailChanged")
      ) {
        event.preventDefault();
        openPassword();
      }
    });

    /* The email field's own script owns the "did it change" decision; this
           only records the answer where the submit gate can read it. */
    $form.on("input change", '[data-input="change-email"]', function () {
      var $el = $(this);

      $el.data("emailChanged", $el.val() !== $el.prop("defaultValue"));
    });

    $phoneInput.on("input", function () {
      $phoneField.find(SEL.phoneHint).prop("hidden", !phoneChanged());
    });

    $host.on("click", SEL.otpSubmit, submitCode);
    $host.on("click", SEL.passwordSubmit, submitPassword);
    $host.on("click", SEL.cancel, function (event) {
      event.preventDefault();
      closeDialog();
    });

    $host.on("click", SEL.otpResend, function (event) {
      event.preventDefault();
      sendCode()
        .done(function () {
          $host.find(SEL.otpBox).val("");
          $host.find(SEL.otpBox).first().trigger("focus");
        })
        .fail(function (xhr) {
          fail((xhr.responseJSON || {}).message);
        });
    });

    /* Digit-by-digit advance, and Backspace steps back out of an empty box
           — the behaviour the login modal's OTP step already has. */
    $host.on("input", SEL.otpBox, function () {
      var $box = $(this);

      $box.val(
        String($box.val() || "")
          .replace(/\D/g, "")
          .slice(0, 1),
      );

      if ($box.val() !== "") {
        $box.nextAll(SEL.otpBox).first().trigger("focus");
      }
    });

    $host.on("keydown", SEL.otpBox, function (event) {
      if (event.key === "Backspace" && String($(this).val() || "") === "") {
        $(this).prevAll(SEL.otpBox).first().trigger("focus");
      }
    });
  };
});
