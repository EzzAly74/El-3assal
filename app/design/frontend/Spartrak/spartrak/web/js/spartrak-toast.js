/**
 * Spartrak — page messages as auto-dismissing toasts.
 *
 * ===========================================================================
 * WHY A MUTATION OBSERVER AND NOT A REWRITE OF MAGENTO'S MESSAGES
 * ===========================================================================
 * Magento produces page messages down two different paths, and BOTH have to be
 * covered:
 *
 *   - server-rendered, already in the HTML on load (a redirect carrying a
 *     success message, a validation failure);
 *   - injected later by Knockout, from Magento_Theme/js/view/messages, after an
 *     AJAX add-to-cart or any customer-data update.
 *
 * Replacing either pipeline would mean forking core templates and re-emitting
 * their markup. Watching the container instead leaves both pipelines exactly as
 * they are — every message Magento decides to show still appears, with its own
 * type and its own text — and only adds the dismissal behaviour on top. Nothing
 * here creates, filters or suppresses a message.
 *
 * ===========================================================================
 * BEHAVIOUR
 * ===========================================================================
 *   - a toast lives for `lifetime` ms (3s) and then fades out;
 *   - HOVER OR FOCUS PAUSES the countdown and restarts it on leave, so a
 *     message can never expire while it is being read;
 *   - a close button dismisses immediately, and the whole toast is clickable
 *     for the same purpose;
 *   - the node is removed only after the exit transition, so the stack does
 *     not jump.
 *
 * Under `prefers-reduced-motion` the transition is disabled in CSS; the
 * fallback timer here still removes the node, so a toast can never get stuck.
 */
define(['jquery', 'jquery-ui-modules/widget'], function ($) {
    'use strict';

    $.widget('mage.spartrakToast', {
        options: {
            // How long a toast stays on screen. One number, one place.
            lifetime: 3000,
            // Must stay >= the CSS exit transition, or the node would be
            // removed mid-fade and the toast would vanish abruptly.
            exitDuration: 260,
            container: '.page.messages'
        },

        _create: function () {
            this.container = document.querySelector(this.options.container);

            if (!this.container) {
                return;
            }

            this.container.classList.add('spartrak-toasts');

            // Anything already in the DOM at load — the server-rendered path.
            this._collect(this.container);

            // ...and anything Knockout adds later — the AJAX path.
            this.observer = new MutationObserver(function (records) {
                records.forEach(function (record) {
                    Array.prototype.forEach.call(record.addedNodes, function (node) {
                        if (node.nodeType === 1) {
                            this._collect(node);
                        }
                    }.bind(this));
                }.bind(this));
            }.bind(this));

            this.observer.observe(this.container, { childList: true, subtree: true });
        },

        /**
         * Finds message nodes inside (or at) `root` and arms each one.
         */
        _collect: function (root) {
            var nodes = [];

            if (root.classList && root.classList.contains('message')) {
                nodes.push(root);
            }

            Array.prototype.push.apply(nodes, root.querySelectorAll ? root.querySelectorAll('.message') : []);

            nodes.forEach(this._arm.bind(this));
        },

        _arm: function (node) {
            // MutationObserver can report the same node more than once (a
            // parent insert plus a subtree scan), and arming twice would run
            // two timers against one toast.
            if (node.dataset.spartrakToast) {
                return;
            }

            node.dataset.spartrakToast = '1';

            this._addCloseButton(node);

            // Next frame, so the browser has painted the entry state first and
            // the transition actually runs.
            window.requestAnimationFrame(function () {
                node.classList.add('is-visible');
            });

            this._startTimer(node);

            node.addEventListener('mouseenter', this._clearTimer.bind(this, node));
            node.addEventListener('focusin', this._clearTimer.bind(this, node));
            node.addEventListener('mouseleave', this._startTimer.bind(this, node));
            node.addEventListener('focusout', this._startTimer.bind(this, node));
            node.addEventListener('click', this._dismiss.bind(this, node));
        },

        _addCloseButton: function (node) {
            var button = document.createElement('button');

            button.type = 'button';
            button.className = 'spartrak-toast__close';
            // Magento's messages carry role="alert" on their wrapper, so the
            // text is announced already; this control just needs a name.
            button.setAttribute('aria-label', $.mage.__('Close'));
            node.appendChild(button);
        },

        _startTimer: function (node) {
            this._clearTimer(node);

            node._spartrakTimer = window.setTimeout(
                this._dismiss.bind(this, node),
                this.options.lifetime
            );
        },

        _clearTimer: function (node) {
            if (node._spartrakTimer) {
                window.clearTimeout(node._spartrakTimer);
                node._spartrakTimer = null;
            }
        },

        _dismiss: function (node) {
            this._clearTimer(node);

            if (node.dataset.spartrakDismissed) {
                return;
            }

            node.dataset.spartrakDismissed = '1';
            node.classList.remove('is-visible');
            node.classList.add('is-leaving');

            window.setTimeout(function () {
                if (node.parentNode) {
                    node.parentNode.removeChild(node);
                }
            }, this.options.exitDuration);
        },

        _destroy: function () {
            if (this.observer) {
                this.observer.disconnect();
            }
        }
    });

    return $.mage.spartrakToast;
});
