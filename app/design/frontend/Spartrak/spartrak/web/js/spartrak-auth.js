/**
 * Spartrak Login/Register Modal — client for Spartrak_CustomerAuth.
 *
 * Replaces the step-switching shell that shipped with Phase 4. That version
 * advanced the UI without talking to anything; this one drives the module's five
 * JSON endpoints (otp/send, otp/verify, account/loginPost, account/createPost,
 * password/resetPost) and is the whole sign-in surface for the storefront.
 *
 * The step machine is declared in markup, not here. Each <form> carries
 * data-auth-endpoint / data-auth-purpose / data-auth-next, so adding or
 * reordering a step is a template change. This file knows how to post a form,
 * how to read a failure, and how to hand off a completed session — nothing about
 * the specific journeys.
 *
 * FORM KEY. Read from the form_key cookie rather than rendered into the page,
 * because the modal block is full-page-cached and a cached form key is stale for
 * everyone who receives it. Sign-in, registration and password reset each
 * regenerate the session id, which invalidates the key that was just used, so
 * every auth response carries a fresh one and _applyFormKey() writes it back to
 * the cookie. Skipping that step is the classic cause of an "Invalid Form Key"
 * failure on the *second* request of an AJAX auth flow.
 *
 * SECTION DATA. Requests deliberately run as ordinary global jQuery ajax calls.
 * Magento_Customer/js/customer-data binds ajaxComplete and invalidates the
 * sections registered in the module's etc/frontend/sections.xml; passing
 * global:false here would silence that and leave the header rendering a
 * logged-out state over a real session. The explicit invalidate() before reload
 * is belt-and-braces for the case where the section config has not loaded yet.
 */
define([
    'jquery',
    'mage/translate',
    'Magento_Customer/js/customer-data',
    'mage/cookies',
    'jquery/ui'
], function ($, $t, customerData) {
    'use strict';

    /**
     * Focusable selector for the panel's focus trap. Kept narrow on purpose —
     * a modal that traps focus onto disabled or hidden controls is worse than
     * one that does not trap at all.
     */
    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled])';

    $.widget('mage.spartrakAuth', {
        options: {
            endpoints: {},
            codeLength: 6,
            resendSeconds: 60,

            /**
             * Pause on the terminal step before reloading, so the shopper sees
             * the confirmation rather than a page that flashes and jumps. Long
             * enough to read, short enough not to feel stuck.
             */
            successDelay: 900
        },

        /** @inheritdoc */
        _create: function () {
            this._session = {
                phone: '',
                token: '',
                busy: false
            };

            this._on({
                'click [data-auth-close]': this._onCloseClick,
                'click [data-auth-goto]': this._onGotoClick,
                'submit [data-auth-form]': this._onSubmit,
                'click [data-otp-resend]': this._onResend,
                'input [data-otp-box]': this._onOtpInput,
                'keydown [data-otp-box]': this._onOtpKeydown,
                'paste [data-otp-box]': this._onOtpPaste,
                'click [data-password-toggle]': this._onPasswordToggle,
                keydown: this._onKeydown
            });

            /*
             * Two open triggers, both outside this widget's own root, so they
             * have to be delegated from the document:
             *
             *   .authorization-link > a — Magento_Customer's native header link,
             *   rendered inside header.links. Its "Sign Out" variant is left
             *   alone (href test) so an authenticated shopper's logout still
             *   navigates.
             *
             *   [data-auth-open] — any deliberate trigger elsewhere in the
             *   theme, optionally carrying data-auth-step to open straight onto
             *   a specific step (e.g. a "Register" call to action).
             *
             * Same narrowly-scoped delegation exception documented in
             * spartrak-mobile-drawer.js: specific selectors, namespaced, removed
             * in _destroy.
             */
            $(document)
                .on('click.spartrakAuth', '.authorization-link > a', this._onNativeLinkClick.bind(this))
                .on('click.spartrakAuth', '[data-auth-open]', this._onOpenTriggerClick.bind(this));

            this._openFromLocationHash();
        },

        /**
         * Third open trigger: a `#auth=<step>` URL fragment.
         *
         * This is how Observer\RedirectNativeAuthPageToModal hands off a visit
         * to /customer/account/login|create|forgotpassword — it 302s to the
         * referring page (or the store home page) with that fragment attached
         * instead of ever rendering Magento's native auth page. A fragment
         * rather than a query string is what lets the redirect target still hit
         * the SAME full-page-cache entry as a plain page load; see the
         * observer's own comment for the full reasoning. Reading it here is
         * the other half of that contract — without this, the fragment would
         * arrive and do nothing, and the redirect would silently fail its one
         * job.
         *
         * Guards against an unknown/garbage step id the same way `open()`
         * already does: `_goto()` no-ops when the selector matches nothing.
         */
        _openFromLocationHash: function () {
            var match = /^#auth=([a-z-]+)$/.exec(window.location.hash);

            if (!match) {
                return;
            }

            this.open(match[1]);

            // Consumed, not left sitting in the address bar: a shopper who
            // closes the modal and reloads should not have it reopen, and a
            // bookmark of this URL should not silently re-trigger it either.
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', window.location.pathname + window.location.search);
            } else {
                window.location.hash = '';
            }
        },

        /** @inheritdoc */
        _destroy: function () {
            $(document).off('.spartrakAuth');
            this._clearResendTimer();
        },

        // ------------------------------------------------------------------
        // Opening and closing
        // ------------------------------------------------------------------

        _onNativeLinkClick: function (event) {
            var href = $(event.currentTarget).attr('href') || '';

            if (href.indexOf('logout') !== -1) {
                return; // authenticated visitor signing out — let it navigate
            }

            event.preventDefault();
            this.open('login');
        },

        _onOpenTriggerClick: function (event) {
            event.preventDefault();
            this.open($(event.currentTarget).data('authStep') || 'login');
        },

        /**
         * @param {String} stepId
         */
        open: function (stepId) {
            // Remembered so focus can go back where it came from on close —
            // without this, dismissing the modal drops keyboard focus to the top
            // of the document and the shopper loses their place entirely.
            this._returnFocusTo = document.activeElement;

            this._goto(stepId || 'login');
            this.element.attr('data-open', 'true').attr('aria-hidden', 'false');
            $(document.body).addClass('spartrak-auth-open');
        },

        _onCloseClick: function (event) {
            event.preventDefault();
            this.close();
        },

        close: function () {
            // _finishing is checked separately from _session.busy because the
            // ajax always() handler clears busy immediately after done() runs —
            // so during the successDelay pause before reload, busy is already
            // false. Closing in that window would drop the shopper back onto a
            // page that is about to reload out from under them.
            if (this._session.busy || this._finishing) {
                return; // never abandon an in-flight auth request mid-write
            }

            this.element.removeAttr('data-open').attr('aria-hidden', 'true');
            $(document.body).removeClass('spartrak-auth-open');
            this._clearResendTimer();

            if (this._returnFocusTo && this._returnFocusTo.focus) {
                this._returnFocusTo.focus();
            }
        },

        _onKeydown: function (event) {
            if (event.key === 'Escape') {
                this.close();

                return;
            }

            if (event.key === 'Tab') {
                this._trapFocus(event);
            }
        },

        /**
         * Keep Tab inside the dialog. aria-modal alone does not do this for
         * sighted keyboard users in any current browser.
         */
        _trapFocus: function (event) {
            var focusable = this.element.find('[data-auth-panel]')
                    .find(FOCUSABLE)
                    .filter(':visible'),
                first = focusable.first()[0],
                last = focusable.last()[0];

            if (!first) {
                return;
            }

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },

        // ------------------------------------------------------------------
        // Step machine
        // ------------------------------------------------------------------

        _onGotoClick: function (event) {
            var target = $(event.currentTarget).data('authGoto');

            event.preventDefault();

            if (target) {
                this._goto(target);
            }
        },

        /**
         * @param {String} stepId
         */
        _goto: function (stepId) {
            var step = this.element.find('[data-auth-step="' + stepId + '"]');

            if (!step.length) {
                return;
            }

            this.element.find('[data-auth-step]').prop('hidden', true);
            step.prop('hidden', false);

            this._clearErrors();

            // Point the dialog's accessible name at the step that is actually
            // showing, so the announced title changes with the content.
            this.element.find('[data-auth-panel]')
                .attr('aria-labelledby', 'spartrak-auth-title-' + stepId);

            step.find('[data-auth-phone-echo]').text(this._session.phone);

            if (step.find('[data-otp-group]').length) {
                this._resetOtp(step);
                this._startResendTimer(step, this._pendingResendSeconds || this.options.resendSeconds);
                this._pendingResendSeconds = null;
            }

            step.find(FOCUSABLE).filter(':visible').first().trigger('focus');
        },

        // ------------------------------------------------------------------
        // Submission
        // ------------------------------------------------------------------

        _onSubmit: function (event) {
            var form = $(event.currentTarget),
                endpointKey = form.data('authEndpoint'),
                url = this.options.endpoints[endpointKey],
                self = this;

            event.preventDefault();

            if (this._session.busy) {
                return;
            }

            if (!url) {
                this._showError(form, $t('This form is not configured correctly. Please refresh the page.'));

                return;
            }

            if (!this._validate(form)) {
                return;
            }

            this._clearErrors();
            this._setBusy(form, true);

            $.ajax({
                url: url,
                type: 'POST',
                dataType: 'json',
                data: this._payload(form)
            }).done(function (response) {
                // A 200 with success:false is not a shape these endpoints
                // produce today, but treating it as success would silently sign
                // nobody in, so it is handled as a failure rather than assumed
                // away.
                if (!response || response.success !== true) {
                    self._showError(form, (response && response.message) || $t('Something went wrong. Please try again.'));

                    return;
                }

                self._applyFormKey(response);
                self._onStepSuccess(form, response);
            }).fail(function (jqXHR) {
                self._showError(form, self._messageFor(jqXHR), jqXHR.responseJSON || {});
            }).always(function () {
                self._setBusy(form, false);
            });
        },

        /**
         * Build the POST body for a step.
         *
         * The phone and the verification token are carried in widget state
         * rather than in hidden inputs: the token is a bearer credential, and
         * parking it in the DOM makes it readable by anything else running on
         * the page and survivable across a step the shopper backed out of.
         *
         * @param {jQuery} form
         * @return {Object}
         */
        _payload: function (form) {
            var data = {
                    form_key: this._formKey()
                },
                purpose = form.data('authPurpose'),
                endpoint = form.data('authEndpoint');

            form.find('input[name]').each(function () {
                var field = $(this);

                if (field.is('[data-otp-box]')) {
                    return;
                }

                data[field.attr('name')] = field.val();
            });

            if (purpose) {
                data.purpose = purpose;
            }

            // Steps after the phone entry do not render a phone field, but every
            // endpoint is stateless and keys on the number.
            if (!data.phone && this._session.phone) {
                data.phone = this._session.phone;
            }

            if (form.find('[data-otp-group]').length) {
                data.code = this._collectOtp(form);
            }

            if (endpoint === 'register' || endpoint === 'passwordReset') {
                data.verification_token = this._session.token;
            }

            return data;
        },

        /**
         * Advance, or finish.
         *
         * @param {jQuery} form
         * @param {Object} response
         */
        _onStepSuccess: function (form, response) {
            var next = form.data('authNext'),
                phoneField = form.find('[data-auth-phone-input]');

            if (phoneField.length) {
                this._session.phone = $.trim(phoneField.val());
            }

            if (response.verification_token) {
                this._session.token = response.verification_token;
            }

            // The server decides the resend cooldown; the configured value is
            // only a pre-flight guess. Handed to _goto via _pendingResendSeconds
            // because the timer belongs to the step being entered, not this one.
            if (typeof response.resend_in === 'number') {
                this._pendingResendSeconds = response.resend_in;
            }

            // Staging safety valve. real_delivery:false means the log gateway is
            // configured and no SMS left the building — without this, a tester
            // waits indefinitely for a message that was never sent.
            if (response.real_delivery === false) {
                this._showNotice(
                    form,
                    $t('SMS delivery is not configured on this environment. The code was written to the server log.')
                );
            }

            // An already-signed-in shopper double-submitting the modal is
            // reported by the backend rather than treated as an error.
            if (next === 'finish' || response.already_signed_in) {
                this._finish(response, form);

                return;
            }

            this._goto(next);
        },

        /**
         * Terminal success: show the confirmation, then reload.
         *
         * A reload rather than a partial re-render is deliberate. Sign-in merges
         * the guest cart, changes the header, the minicart, prices under customer
         * group rules, and anything else the page personalizes. Re-rendering only
         * the sections this widget knows about would leave the rest of the page
         * describing the previous visitor.
         *
         * @param {Object} response
         * @param {jQuery} [form] the step that produced this success — used
         *        only to choose the loading copy, see _applyLoadingLede
         */
        _finish: function (response, form) {
            var self = this;

            if (response.message) {
                this.element.find('[data-auth-success-message]').text(response.message);
            }

            this._applyLoadingLede(form);
            this._goto('success');
            this._finishing = true; // block close() during the handoff
            this._session.token = '';

            customerData.invalidate(['*']);

            setTimeout(function () {
                self._reload();
            }, this.options.successDelay);
        },

        /**
         * Pick the loading step's second line to match the journey that just
         * finished.
         *
         * FIXED 2026-08-27: the loading step is the terminal state of sign-in,
         * registration AND password reset, but its lede was a single hardcoded
         * "Your account is being created…" — so signing in to an existing
         * account claimed to be creating one. Reported from the live site.
         *
         * The copy itself lives in login-modal.phtml as data attributes so it
         * stays PHP-translated alongside every other string in this component;
         * this only chooses between them. The journey is read from the
         * submitting form's `data-auth-endpoint`, which is the last point where
         * it is still known — by the time the success step is showing, the step
         * id is just 'success' for all three.
         *
         * Unknown or missing endpoint leaves the markup's own text standing
         * rather than blanking the line.
         *
         * @param {jQuery} [form]
         */
        _applyLoadingLede: function (form) {
            var lede = this.element.find('[data-auth-loading-lede]').first(),
                endpoint = form && form.length ? form.data('authEndpoint') : null,
                copy;

            if (!lede.length || !endpoint) {
                return;
            }

            // Keys are the `endpoints` map's own names (see login-modal.phtml's
            // $widgetConfig), which is what data-auth-endpoint carries — NOT
            // the controller action names. These three are exactly the steps
            // whose data-auth-next is "finish".
            copy = {
                login: lede.data('ledeLogin'),
                register: lede.data('ledeSignup'),
                passwordReset: lede.data('ledeReset')
            }[endpoint];

            if (copy) {
                lede.text(copy);
            }
        },

        /**
         * QA FIX (2026-08-26): a plain reload() re-requests the EXACT SAME
         * URL, which is precisely what let the header's account chip come
         * back showing "Sign in" right after a successful login — fixing
         * itself only once the shopper navigated to an uncached page (their
         * profile) confirmed this was a full-page-cache issue, not a
         * customer-data/session one.
         *
         * Traced against real 2.4.8 source: Magento's FPC picks a cached
         * variant by hashing the request URI together with the incoming
         * `X-Magento-Vary` cookie (Framework\App\PageCache\Identifier::
         * getValue()). That cookie SHOULD already carry the fresh
         * logged-in value by the time this fires — Response\Http's own
         * plugin writes it on every response, this login response included
         * — but core's own LoginPost controller never relies on that timing
         * at all: it always redirects to a genuinely different, uncached
         * destination rather than reloading the page it started on. Staying
         * on the shopper's current page is the whole point of the modal, so
         * a full redirect elsewhere is not an option here — instead, an
         * inert marker query parameter makes the reload target a URL FPC has
         * never cached under ANY vary key, guaranteeing a live render
         * regardless of what a CDN/proxy layer in front of Magento does with
         * cookies. Confirmed this parameter survives FPC's own key
         * computation unstripped: Identifier::getMarketingParameterPatterns()
         * only strips a fixed list of ad-tracking params (gclid, utm-style,
         * etc.), never an arbitrary custom one.
         *
         * A named seam (not an inline call) so it stays independently
         * stubbable, same as before this fix.
         */
        _reload: function () {
            var url = window.location.pathname + window.location.search,
                marker = 'spartrak_auth=1',
                separator = url.indexOf('?') === -1 ? '?' : '&';

            window.location.href = url + separator + marker + window.location.hash;
        },

        // ------------------------------------------------------------------
        // Form key
        // ------------------------------------------------------------------

        _formKey: function () {
            return $.mage.cookies.get('form_key') || '';
        },

        /**
         * Adopt the refreshed key an auth response carries.
         *
         * Written back to the cookie rather than held in this widget, because
         * every other form on the page (add to cart, newsletter, the native
         * account forms) reads the same cookie and is equally stale after a
         * session id change.
         *
         * @param {Object} response
         */
        _applyFormKey: function (response) {
            if (response.form_key) {
                $.mage.cookies.set('form_key', response.form_key);
            }
        },

        // ------------------------------------------------------------------
        // OTP entry
        // ------------------------------------------------------------------

        _resetOtp: function (step) {
            step.find('[data-otp-box]').val('');
        },

        _collectOtp: function (form) {
            var code = '';

            form.find('[data-otp-box]').each(function () {
                code += $(this).val();
            });

            return code;
        },

        _onOtpInput: function (event) {
            var box = $(event.currentTarget),
                // Strip anything non-numeric rather than rejecting the
                // keystroke: an Arabic keyboard sends Arabic-Indic digits, which
                // the server normalizes but a naive [0-9] guard would silently
                // eat.
                value = box.val().replace(/\D/g, '');

            box.val(value.slice(0, 1));

            if (value.length) {
                box.nextAll('[data-otp-box]').first().trigger('focus');
            }

            this._maybeAutoSubmit(box.closest('[data-auth-form]'));
        },

        _onOtpKeydown: function (event) {
            var box = $(event.currentTarget);

            // Backspace on an empty box steps back, which is what every OTP
            // field the shopper has used elsewhere does.
            if (event.key === 'Backspace' && !box.val()) {
                box.prevAll('[data-otp-box]').first().trigger('focus');
            }
        },

        /**
         * Accept a pasted code into the whole group. Shoppers paste the code
         * straight out of the SMS far more often than they type it.
         */
        _onOtpPaste: function (event) {
            var clipboard = (event.originalEvent || event).clipboardData,
                boxes = $(event.currentTarget).closest('[data-otp-group]').find('[data-otp-box]'),
                digits;

            if (!clipboard) {
                return;
            }

            digits = (clipboard.getData('text') || '').replace(/\D/g, '');

            if (!digits) {
                return;
            }

            event.preventDefault();

            boxes.each(function (index) {
                $(this).val(digits.charAt(index) || '');
            });

            boxes.eq(Math.min(digits.length, boxes.length) - 1).trigger('focus');
            this._maybeAutoSubmit(boxes.closest('[data-auth-form]'));
        },

        /**
         * Submit as soon as the last digit lands. Asking someone who has just
         * typed a complete code to also press a button is friction with no
         * purpose — the button stays for keyboard and screen-reader users, and
         * for a corrected code.
         */
        _maybeAutoSubmit: function (form) {
            if (this._session.busy) {
                return;
            }

            if (this._collectOtp(form).length === form.find('[data-otp-box]').length) {
                form.trigger('submit');
            }
        },

        // ------------------------------------------------------------------
        // Password visibility toggle
        // ------------------------------------------------------------------

        /**
         * Flip the adjacent input between type="password" and type="text".
         *
         * Only one eye glyph was supplied (see the template docblock), so the
         * shown/hidden distinction is carried entirely by aria-pressed and
         * aria-label rather than a second icon variant — inventing one would
         * be exactly the kind of asset guess the project forbids.
         */
        _onPasswordToggle: function (event) {
            var button = $(event.currentTarget),
                input = button.siblings('input'),
                nowVisible = input.attr('type') === 'password';

            event.preventDefault();

            input.attr('type', nowVisible ? 'text' : 'password');
            button.attr('aria-pressed', nowVisible ? 'true' : 'false');
            button.attr('aria-label', nowVisible ? $t('Hide password') : $t('Show password'));

            // The click moves focus to the button; returning it to the input
            // keeps the shopper's typing position where they left it instead
            // of stranding the cursor on a button with nothing to type into.
            input.trigger('focus');
        },

        // ------------------------------------------------------------------
        // Resend
        // ------------------------------------------------------------------

        _onResend: function (event) {
            var button = $(event.currentTarget),
                form = button.closest('[data-auth-form]'),
                self = this;

            event.preventDefault();

            if (button.prop('disabled') || this._session.busy) {
                return;
            }

            this._clearErrors();
            this._session.busy = true;

            $.ajax({
                url: this.options.endpoints.otpSend,
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: this._formKey(),
                    phone: this._session.phone,
                    purpose: form.data('authPurpose')
                }
            }).done(function (response) {
                if (!response || response.success !== true) {
                    self._showError(form, (response && response.message) || $t('Could not resend the code.'));

                    return;
                }

                self._applyFormKey(response);
                self._resetOtp(form);
                self._showNotice(form, response.message || $t('We sent a new code.'));
                self._startResendTimer(
                    form,
                    typeof response.resend_in === 'number' ? response.resend_in : self.options.resendSeconds
                );
            }).fail(function (jqXHR) {
                var payload = jqXHR.responseJSON || {};

                self._showError(form, self._messageFor(jqXHR), payload);

                // A 429 tells us exactly how long to wait; reflect it in the
                // button instead of letting the shopper hammer a blocked
                // endpoint.
                if (typeof payload.retry_after === 'number') {
                    self._startResendTimer(form, payload.retry_after);
                }
            }).always(function () {
                self._session.busy = false;
            });
        },

        /**
         * @param {jQuery} scope
         * @param {Number} seconds
         */
        _startResendTimer: function (scope, seconds) {
            var button = scope.find('[data-otp-resend]'),
                countdown = button.find('[data-resend-countdown]'),
                timerEl = button.find('[data-resend-timer]'),
                remaining = parseInt(seconds, 10),
                self = this;

            this._clearResendTimer();

            if (!button.length) {
                return;
            }

            if (!remaining || remaining <= 0) {
                button.prop('disabled', false);
                countdown.prop('hidden', true);

                return;
            }

            button.prop('disabled', true);
            countdown.prop('hidden', false);
            timerEl.text(remaining);

            this._resendInterval = setInterval(function () {
                remaining -= 1;
                timerEl.text(remaining);

                if (remaining <= 0) {
                    self._clearResendTimer();
                    button.prop('disabled', false);
                    countdown.prop('hidden', true);
                }
            }, 1000);
        },

        _clearResendTimer: function () {
            if (this._resendInterval) {
                clearInterval(this._resendInterval);
                this._resendInterval = null;
            }
        },

        // ------------------------------------------------------------------
        // Validation, errors, busy state
        // ------------------------------------------------------------------

        /**
         * Mirrors the backend's Egyptian-mobile rule for immediate UX
         * feedback ONLY — Normalizer::assertReachableByCountry() in PHP
         * remains the single authority on what phone number is actually
         * accepted. Kept deliberately narrow (this exact pattern, nothing
         * more) specifically so it stays a mirror rather than a second,
         * independently-evolving implementation: if the backend's rule ever
         * changes, this one has to be re-copied by hand, and a narrow, obvious
         * copy is far easier to notice has gone stale than a "clever"
         * equivalent would be.
         *
         * Accepts an optional leading trunk "0" (the everyday local spelling,
         * "01012345678") or none (the country-code selector already implies
         * it, "1012345678") — both normalize to the same account either way.
         *
         * @param {String} value
         * @return {Boolean}
         */
        _isValidEgyptianPhone: function (value) {
            var digits = $.trim(value).replace(/\D/g, '');

            if (digits.charAt(0) === '0') {
                digits = digits.slice(1);
            }

            return (/^1[0125]\d{8}$/).test(digits);
        },

        /**
         * Presence and format, deliberately narrow.
         *
         * Password POLICY (length/strength/complexity) stays entirely
         * AccountManagement's — duplicating it here would create a second
         * source of truth that silently disagrees with admin configuration
         * the day someone changes the minimum length. Phone FORMAT is
         * different: it is a fixed business rule (Egyptian mobiles only),
         * not admin-configurable, so mirroring it client-side costs nothing
         * and saves a full round trip on the single most common typo. The
         * password match is enforced here for the same "needs no server
         * knowledge" reason.
         *
         * @param {jQuery} form
         * @return {Boolean}
         */
        _validate: function (form) {
            var missing = form.find('input[required]:visible').filter(function () {
                    return !$.trim($(this).val());
                }),
                password = form.find('input[name="password"]'),
                confirmation = form.find('input[name="password_confirmation"]'),
                phoneInput = form.find('[data-auth-phone-input]'),
                otpBoxes = form.find('[data-otp-box]');

            // OTP boxes carry no `required` attribute (one per digit would make
            // the browser's own validation message nonsense), so completeness is
            // checked here. Without this, pressing Verify on a half-typed code
            // spends one of the shopper's limited verify attempts on a code they
            // never finished entering.
            if (otpBoxes.length && this._collectOtp(form).length !== otpBoxes.length) {
                this._showError(form, $t('Please enter the full verification code.'));
                otpBoxes.filter(function () {
                    return !$(this).val();
                }).first().trigger('focus');

                return false;
            }

            if (phoneInput.length && $.trim(phoneInput.val()) && !this._isValidEgyptianPhone(phoneInput.val())) {
                this._showError(
                    form,
                    $t('Please enter a valid Egyptian mobile number, for example 01012345678.'),
                    {field: 'phone'}
                );
                phoneInput.trigger('focus');

                return false;
            }

            if (missing.length) {
                this._showError(form, $t('Please fill in all required fields.'));
                missing.first().trigger('focus');

                return false;
            }

            if (confirmation.length && password.val() !== confirmation.val()) {
                // field: 'password_confirmation' so this renders identically to
                // the equivalent server-side rejection (CreatePost/ResetPost) —
                // one visual treatment for "your confirmation doesn't match"
                // regardless of which side caught it.
                this._showError(form, $t('The passwords do not match.'), {field: 'password_confirmation'});
                confirmation.trigger('focus');

                return false;
            }

            return true;
        },

        /**
         * Turn a failed request into one shopper-facing line.
         *
         * The endpoints already return translated, deliberately vague messages
         * (sign-in reports the same text for "no such number" and "wrong
         * password"), so they are surfaced as-is. Only the transport-level
         * cases need inventing text for.
         *
         * @param {Object} jqXHR
         * @return {String}
         */
        _messageFor: function (jqXHR) {
            var payload = jqXHR.responseJSON;

            if (payload && payload.message) {
                return payload.message;
            }

            if (jqXHR.status === 0) {
                return $t('Connection lost. Please check your network and try again.');
            }

            return $t('Something went wrong. Please try again.');
        },

        /**
         * @param {jQuery} form
         * @param {String} message
         * @param {Object} [payload]
         */
        /**
         * Render one error message in exactly ONE place.
         *
         * FIXED 2026-08-27: this used to write `text` into the shared region
         * AND the same message again into the offending field's slot, so any
         * response carrying a `field` hint — a bad phone number, a mismatched
         * confirmation, a wrong password — printed the identical sentence
         * twice, once boxed above the form and once under the input.
         *
         * The rule now: if the payload names a field AND this step actually
         * has a slot for it, the message belongs under that field (Figma's
         * red-border-plus-message-under-the-input pattern) and the shared
         * region stays hidden. Everything else — form-level failures, network
         * errors, anything with no field — goes to the shared region. The two
         * are alternatives, never both.
         *
         * ACCESSIBILITY is preserved by moving, not dropping, the live region:
         * the field slot carries `role="alert"` of its own (login-modal.phtml),
         * so whichever element receives the message announces it, and
         * _showFieldError additionally wires aria-invalid + aria-describedby on
         * the input.
         *
         * @param {jQuery} form
         * @param {String} message
         * @param {Object} [payload]
         */
        _showError: function (form, message, payload) {
            var region = form.find('[data-auth-error]').first(),
                text = message;

            payload = payload || {};

            // Counting down the remaining tries is the difference between a
            // shopper retrying carefully and a shopper retrying until the
            // account locks.
            if (typeof payload.attempts_remaining === 'number') {
                text += ' ' + $t('Attempts remaining: %1').replace('%1', payload.attempts_remaining);
            }

            if (this._showFieldError(form, payload.field, text)) {
                // Placed at the field. Make sure a message left over from an
                // earlier, field-less failure is not still standing above it.
                this._hideRegion(region);

                return;
            }

            region
                .removeClass('spartrak-auth__error--notice')
                .text(text)
                .prop('hidden', false);
        },

        /**
         * @param {jQuery} region
         */
        _hideRegion: function (region) {
            region
                .removeClass('spartrak-auth__error--notice')
                .text('')
                .prop('hidden', true);
        },

        /**
         * @param {jQuery} form
         * @param {String} [field]
         * @param {String} [message]
         * @return {Boolean} true when the message was placed at a field slot,
         *         which is what tells _showError to leave the shared region
         *         hidden rather than repeating itself.
         */
        _showFieldError: function (form, field, message) {
            var slot,
                input;

            if (!field) {
                return false;
            }

            slot = form.find('[data-field-error="' + field + '"]').first();

            if (!slot.length) {
                return false; // this form's step has no slot for that field — fine
            }

            slot.text(message).prop('hidden', false);

            input = form.find('[name="' + field + '"]').first();

            if (input.length) {
                input.attr('aria-invalid', 'true').attr('aria-describedby', this._fieldErrorId(slot, field));
            }

            return true;
        },

        /**
         * Assigns the field-error span an id the first time it is needed,
         * rather than rendering one for every slot up front on every page load.
         *
         * Namespaced by step as well as field: `phone` appears in three
         * different steps (login, signup, reset-phone), each with its own
         * `[data-field-error="phone"]` slot, so `field` alone would produce
         * duplicate ids across the document.
         *
         * @param {jQuery} slot
         * @param {String} field
         * @return {String}
         */
        _fieldErrorId: function (slot, field) {
            var id = slot.attr('id'),
                stepId;

            if (!id) {
                stepId = slot.closest('[data-auth-step]').data('authStep') || 'step';
                id = 'spartrak-auth-field-error-' + stepId + '-' + field;
                slot.attr('id', id);
            }

            return id;
        },

        /**
         * Non-failure information in the same region — a resent code, or the
         * staging "no SMS was actually sent" warning.
         *
         * @param {jQuery} form
         * @param {String} message
         */
        _showNotice: function (form, message) {
            form.find('[data-auth-error]').first()
                .addClass('spartrak-auth__error--notice')
                .text(message)
                .prop('hidden', false);
        },

        _clearErrors: function () {
            this.element.find('[data-auth-error]')
                .prop('hidden', true)
                .removeClass('spartrak-auth__error--notice')
                .text('');

            this.element.find('[data-field-error]')
                .prop('hidden', true)
                .text('');

            this.element.find('[aria-invalid="true"]').removeAttr('aria-invalid').removeAttr('aria-describedby');
        },

        /**
         * @param {jQuery} form
         * @param {Boolean} busy
         */
        _setBusy: function (form, busy) {
            this._session.busy = busy;

            form.find('[type="submit"]')
                .prop('disabled', busy)
                .toggleClass('spartrak-auth__submit--loading', busy)
                .attr('aria-busy', busy ? 'true' : 'false');

            this.element.toggleClass('spartrak-auth--busy', busy);
        }
    });

    return $.mage.spartrakAuth;
});
