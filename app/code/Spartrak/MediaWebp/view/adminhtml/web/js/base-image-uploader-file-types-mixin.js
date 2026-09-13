/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 *
 * Widens the product gallery uploader's client-side file-type restriction.
 *
 * Magento_Catalog/catalog/base-image-uploader builds its Uppy instance inside
 * _create() with `restrictions.allowedFileTypes` written inline, keeps the
 * instance in a local variable, and exposes neither as a widget option - so
 * there is no seam to configure and no handle to call uppy.setOptions() on
 * afterwards. The alternative to the wrapper below is forking the whole 257-line
 * core widget, which would silently freeze every future core fix to it.
 *
 * So: swap the Uppy constructor for the duration of the original _create(), let
 * it build its options object, merge our types into it, and put the constructor
 * back. _create() is synchronous, the swap is undone in a finally block, and
 * nothing outside this call window ever sees the wrapper.
 *
 * Server-side counterpart: Spartrak_MediaWebp etc/adminhtml/di.xml. The two
 * lists are independent by core's design - keep them in step.
 */
define([
    'jquery',
    'jquery/uppy-core'
], function ($) {
    'use strict';

    var ADDITIONAL_FILE_TYPES = ['.webp'];

    /**
     * Merge the extra types into an Uppy options object, in place.
     *
     * @param {Object} options
     * @return {Object}
     */
    function withAdditionalFileTypes(options) {
        var allowed = options && options.restrictions && options.restrictions.allowedFileTypes;

        if (!Array.isArray(allowed)) {
            return options;
        }

        options.restrictions.allowedFileTypes = allowed.concat(
            ADDITIONAL_FILE_TYPES.filter(function (fileType) {
                return allowed.indexOf(fileType) === -1;
            })
        );

        return options;
    }

    return function (baseImage) {
        $.widget('mage.baseImage', baseImage, {

            /** @inheritdoc */
            _create: function () {
                var uppyNamespace = window.Uppy,
                    OriginalUppy;

                if (!uppyNamespace || typeof uppyNamespace.Uppy !== 'function') {
                    this._super();

                    return;
                }

                OriginalUppy = uppyNamespace.Uppy;

                uppyNamespace.Uppy = function (options) {
                    return new OriginalUppy(withAdditionalFileTypes(options));
                };

                try {
                    this._super();
                } finally {
                    uppyNamespace.Uppy = OriginalUppy;
                }
            }
        });

        return $.mage.baseImage;
    };
});
