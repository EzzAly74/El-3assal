/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 *
 * Widens the MEDIA BROWSER uploader's client-side file-type gate - the "Upload
 * Images" panel behind the WYSIWYG description editor and the admin media
 * browser (Magento_Cms browser/content/uploader.phtml and
 * Magento_Backend media/uploader.phtml both mount this widget).
 *
 * Unlike the product gallery, Magento_Backend/js/media-uploader does not use
 * Uppy's own restrictions. It hard-codes `allowedExt = ['jpeg','jpg','png','gif']`
 * as a local inside _create() and tests it in its own onBeforeFileAdded handler:
 *
 *     allowedResize = $.inArray(currentFile.extension?.toLowerCase(), allowedExt) !== -1;
 *     if (!allowedResize) { ...aggregateError('Disallowed file type.'); return false; }
 *
 * That single flag answers two different questions at once - "is this type
 * allowed?" and "may the Compressor resize it?" - and for WebP the honest answer
 * to both is yes: the server accepts it (see etc/di.xml) and canvas-based
 * compression handles it. Since the list is a closure local with no seam, the
 * handler is wrapped instead and shown a view of the file whose `extension`
 * reads as a type core already accepts; the real extension is restored on the
 * object handed back to Uppy, so nothing downstream sees the stand-in.
 *
 * The alternative was forking all 185 lines of the core widget, which would have
 * silently frozen every future fix to it.
 */
define([
    'jquery',
    'Spartrak_MediaWebp/js/additional-file-types',
    'jquery/uppy-core'
], function ($, additionalFileTypes) {
    'use strict';

    /**
     * Stand-in extension: a lossless raster type core's list already contains,
     * so the core handler takes its "accepted" branch unchanged.
     */
    var PROXY_EXTENSION = 'png';

    /**
     * Wrap the widget's own onBeforeFileAdded gate.
     *
     * @param {Object} options
     * @return {Object}
     */
    function allowAdditionalTypes(options) {
        var coreHandler = options && options.onBeforeFileAdded;

        if (typeof coreHandler !== 'function') {
            return options;
        }

        options.onBeforeFileAdded = function (currentFile) {
            var accepted;

            if (!additionalFileTypes.matches(currentFile)) {
                return coreHandler.apply(this, arguments);
            }

            accepted = coreHandler.call(this, $.extend({}, currentFile, {
                extension: PROXY_EXTENSION
            }));

            if (!accepted) {
                return accepted;
            }

            return $.extend({}, accepted, {
                extension: currentFile.extension
            });
        };

        return options;
    }

    return function (mediaUploader) {
        $.widget('mage.mediaUploader', mediaUploader, {

            /** @inheritdoc */
            _create: function () {
                var widget = this;

                additionalFileTypes.whileDecoratingUppyOptions(allowAdditionalTypes, function () {
                    widget._super();
                });
            }
        });

        return $.mage.mediaUploader;
    };
});
