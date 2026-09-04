/**
 * Spartrak InstaPay — the receipt upload's two states.
 *
 * ===========================================================================
 * WHAT IT OWNS
 * ===========================================================================
 * One slot with two mutually exclusive faces: the empty dashed dropzone, and
 * the picture the shopper chose with its name, its size and a way out of it.
 * This file is the switch between them, and nothing else — the picker itself is
 * two native `<label for>`s and the submit is a plain multipart POST.
 *
 * ===========================================================================
 * WHY A PICTURE AND NOT THE FILE NAME
 * ===========================================================================
 * This replaced a script that echoed the chosen file's NAME back
 * ("certificate-1 (2).jpg"), which answered the wrong question. A file name
 * confirms that the input fired; it does not confirm that the RIGHT screenshot
 * is attached. And the mistake this screen invites is precisely picking the
 * wrong image out of a camera roll full of near-identical screenshots — after
 * which a human reviewer rejects the transfer and somebody has to be phoned.
 *
 * ===========================================================================
 * NO UPLOAD, NO LIBRARY, NO NETWORK
 * ===========================================================================
 * `URL.createObjectURL(file)` hands the <img> a handle to the bytes the browser
 * already holds. Nothing is sent anywhere until the form is submitted, so
 * choosing a 9 MB photograph costs one local decode and no bandwidth at all —
 * which matters here more than almost anywhere, because this page is reached
 * mid-payment on whatever connection the shopper happens to be on (CLAUDE.md
 * section 4).
 *
 * THE OBJECT URL NEEDS `blob:` IN THE `img-src` CSP DIRECTIVE, and this store
 * enforces CSP. That is granted by etc/csp_whitelist.xml — which explains, at
 * length, why it is not a weakening. Without it the browser refuses the URL,
 * fires `error`, and this file falls back to the undecodable state below: the
 * exact symptom the feature was first reported with.
 *
 * Handles are revoked as soon as they are replaced, and again on unload: an
 * object URL pins the whole file in memory for the life of the document, and a
 * shopper trying four screenshots would otherwise pin all four.
 *
 * ===========================================================================
 * HEIC IS WHY THERE IS AN UNDECODABLE STATE
 * ===========================================================================
 * ProofStorage accepts JPG and HEIC, and HEIC is what an iPhone produces by
 * default — the single most likely way this feature is used. No desktop browser
 * decodes it. So the preview is attempted and, when the decode fails, the slot
 * says the file is attached and cannot be previewed, keeping the name and both
 * controls. Neither state is guessed: the swap happens on the image element's
 * own `load` / `error`.
 *
 * ===========================================================================
 * PROGRESSIVE
 * ===========================================================================
 * The chosen-state markup ships `hidden`. With this file absent or broken the
 * shopper sees the ordinary dropzone, picks a file through a native label, and
 * the form submits exactly as it did before any of this existed.
 */
define(['jquery', 'mage/translate'], function ($, $t) {
    'use strict';

    /**
     * Bytes as something a person reads. Two units are enough: a receipt is
     * kilobytes and a photograph of a screen is megabytes.
     *
     * @param {Number} bytes
     * @return {String}
     */
    function formatSize(bytes) {
        if (!bytes || bytes < 0) {
            return '';
        }

        if (bytes < 1024 * 1024) {
            return Math.max(1, Math.round(bytes / 1024)) + ' ' + $t('KB');
        }

        return (bytes / (1024 * 1024)).toFixed(1) + ' ' + $t('MB');
    }

    return function (config, element) {
        var $input = $(config.inputSelector, element),
            $dropzone = $(config.dropzoneSelector, element),
            $chosen = $(config.chosenSelector, element),
            $image = $(config.previewSelector, element),
            $undecodable = $(config.undecodableSelector, element),
            $name = $(config.nameSelector, element),
            $size = $(config.sizeSelector, element),
            $remove = $(config.removeSelector, element),
            objectUrl = null;

        // Every element is required for the swap to be coherent. Bailing out
        // whole leaves the native dropzone working, which is the honest
        // degradation; half-wiring it would leave a preview with no way back.
        if (!$input.length || !$dropzone.length || !$chosen.length ||
            !$image.length || !$undecodable.length || !$name.length ||
            !$size.length || !$remove.length
        ) {
            return;
        }

        /**
         * Release the bytes the previous choice was holding.
         */
        function release() {
            if (objectUrl !== null) {
                window.URL.revokeObjectURL(objectUrl);
                objectUrl = null;
            }
        }

        /**
         * Back to the dashed panel. Also the no-file state, so it runs on init
         * paths and on remove alike.
         */
        function showEmpty() {
            release();
            $image.prop('hidden', true).removeAttr('src');
            $undecodable.prop('hidden', true);
            $name.text('');
            $size.text('');
            $chosen.prop('hidden', true);
            $dropzone.prop('hidden', false);
        }

        /**
         * The picture rendered.
         */
        $image.on('load', function () {
            $undecodable.prop('hidden', true);
            $image.prop('hidden', false);
        });

        /**
         * The browser could not decode it — HEIC, or a file whose bytes are not
         * the image its name claims. The file stays attached: the server checks
         * the type properly on submit, from the bytes rather than the name
         * (Model\ProofStorage). Only the preview is unavailable.
         */
        $image.on('error', function () {
            $image.prop('hidden', true).removeAttr('src');
            $undecodable.prop('hidden', false);
            release();
        });

        $input.on('change', function () {
            var file = this.files && this.files[0];

            if (!file) {
                showEmpty();

                return;
            }

            release();
            $image.prop('hidden', true).removeAttr('src');
            $undecodable.prop('hidden', true);

            $name.text(file.name);
            $size.text(formatSize(file.size));

            // Swapped BEFORE the decode starts, so the panel does not sit on
            // the dropzone for however long a 9 MB photograph takes. The figure
            // holds its height either way, so nothing shifts.
            $dropzone.prop('hidden', true);
            $chosen.prop('hidden', false);

            objectUrl = window.URL.createObjectURL(file);
            // alt stays empty: the name beside it is the text alternative, and
            // a receipt's contents cannot be described from here anyway.
            $image.attr('src', objectUrl);
        });

        /**
         * `input.value = ''` is the only way to un-choose a file, and it is the
         * one thing on this panel that genuinely needs script. Setting it fires
         * no `change` event, so the empty state is restored directly.
         *
         * The input keeps its `required` attribute throughout, so removing the
         * picture correctly makes the form incomplete again rather than letting
         * it submit with nothing attached.
         */
        $remove.on('click', function () {
            $input.val('');
            showEmpty();
            // Focus follows the control that replaced the one just removed, so
            // a keyboard user is not dropped at the top of the document.
            $dropzone.trigger('focus');
        });

        // Belt and braces for a shopper who submits or navigates away with a
        // choice made: `pagehide` fires where `unload` is unreliable (Safari,
        // and any browser using a back-forward cache).
        $(window).on('pagehide', release);
    };
});
