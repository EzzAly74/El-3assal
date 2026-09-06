/**
 * Spartrak — the video dialog's upload button.
 *
 * ===========================================================================
 * A MIXIN, SO MAGENTO'S 1,295-LINE WIDGET IS NOT FORKED
 * ===========================================================================
 * Everything else this module adds to the video dialog is a form field, and
 * form fields ride through Magento's own generic serialise/populate loops with
 * no JavaScript at all (see Block\Adminhtml\Product\Edit\NewVideo for why they
 * are all `select` and all carry `edited-data`).
 *
 * A FILE is the one thing that cannot. `serializeArray()` skips file inputs,
 * and the dialog posts gallery data rather than a multipart body, so a chosen
 * file would never leave the browser. This sends it — and nothing else.
 *
 * It is ~60 lines against a fork of a file twenty times that size, and it
 * touches exactly two of the widget's members: `_create`, to bind one button,
 * and `options`, to read the endpoint the block published.
 *
 * ===========================================================================
 * WHAT IT WRITES BACK
 * ===========================================================================
 *   #spartrak_video_path  the STAGED media path. This is what the server
 *                         actually reads; the product save promotes the file
 *                         out of tmp and stores the final path.
 *   #video_url            the file's URL. Magento marks that field required,
 *                         and a merchant who has just uploaded a file should
 *                         not then have to paste a link to satisfy a
 *                         validator. The server prefers the staged path over
 *                         this value, so the two cannot disagree.
 *
 * Progressive enhancement: if this file fails to load, the dialog is exactly
 * Magento's — YouTube, Vimeo and any direct URL still work, and only the
 * upload button goes quiet.
 */
define(['jquery'], function ($) {
    'use strict';

    return function (widget) {
        $.widget('mage.newVideoDialog', widget, {
            /**
             * @private
             */
            _create: function () {
                this._super();
                this._bindSpartrakUpload();
            },

            /**
             * @private
             */
            _bindSpartrakUpload: function () {
                var self = this;

                this.element.on(
                    'click.spartrakVideoUpload',
                    '[data-role="spartrak-video-upload"]',
                    function (event) {
                        event.preventDefault();
                        self._spartrakUpload();
                    }
                );
            },

            /**
             * @private
             */
            _spartrakUpload: function () {
                var self = this,
                    input = this.element.find('#spartrak_video_upload')[0],
                    file = input && input.files ? input.files[0] : null,
                    url = this.options.spartrakUploadUrl,
                    payload;

                if (!file) {
                    this._spartrakError($.mage.__('Choose a video file first.'));

                    return;
                }

                if (!url) {
                    this._spartrakError($.mage.__('The upload endpoint is not configured.'));

                    return;
                }

                payload = new FormData();
                payload.append('spartrak_video_upload', file);
                // The dialog's own form key. Taken from the form rather than
                // from a global so this posts the same key the product form
                // will, and so it keeps working if Magento moves where the key
                // is rendered.
                payload.append('form_key', this.element.find('input[name="form_key"]').val());

                this._spartrakClearError();
                $('body').trigger('processStart');

                $.ajax({
                    url: url,
                    type: 'post',
                    data: payload,
                    processData: false,
                    contentType: false
                }).done(function (result) {
                    if (!result || result.error) {
                        self._spartrakError(
                            (result && result.error) || $.mage.__('The video could not be uploaded.')
                        );

                        return;
                    }

                    // `name` is the staged file's path relative to the tmp
                    // directory; the server rebuilds the full staged path from
                    // its own constant rather than trusting one sent back to
                    // it.
                    self.element.find('#spartrak_video_path').val(result.spartrak_tmp_path || '');
                    self.element.find('#video_url').val(result.url || '').trigger('change');
                }).fail(function () {
                    self._spartrakError($.mage.__('The video could not be uploaded.'));
                }).always(function () {
                    $('body').trigger('processStop');
                });
            },

            /**
             * Reuses the dialog's own error styling, so an upload failure looks
             * like every other failure in this modal rather than like a
             * different application.
             *
             * @param {String} message
             * @private
             */
            _spartrakError: function (message) {
                this._spartrakClearError();
                this.element
                    .find('#spartrak_video_upload')
                    .parent()
                    .append(
                        $('<div/>', { 'class': 'image-upload-error spartrak-video-upload-error' })
                            .append($('<div/>', { 'class': 'image-upload-error-cross' }))
                            .append($('<span/>').text(message))
                    );
            },

            /**
             * @private
             */
            _spartrakClearError: function () {
                this.element.find('.spartrak-video-upload-error').remove();
            }
        });

        return $.mage.newVideoDialog;
    };
});
