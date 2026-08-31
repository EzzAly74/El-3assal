/**
 * Spartrak InstaPay - echoes the chosen file's name back to the shopper.
 *
 * A native <input type="file"> inside a styled dropzone gives no feedback at
 * all once it is clipped: the shopper picks a screenshot, the panel looks
 * exactly as it did, and they cannot tell whether it took. This says so.
 *
 * That is the entire feature. There is no upload here, no preview, no progress
 * bar and no library - the form is a plain multipart POST and works with this
 * script absent, which is why it is loaded as an enhancement rather than being
 * part of the page's critical path (CLAUDE.md section 4).
 */
define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        var $input = $(config.inputSelector, element),
            $output = $(config.outputSelector, element);

        if (!$input.length || !$output.length) {
            return;
        }

        $input.on('change', function () {
            var file = this.files && this.files[0];

            // role="status" on the output element means a screen reader
            // announces this without the focus moving.
            $output.text(file ? file.name : '');
        });
    };
});
