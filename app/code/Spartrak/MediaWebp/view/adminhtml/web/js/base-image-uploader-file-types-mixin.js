/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 *
 * Widens the PRODUCT GALLERY uploader's client-side file-type restriction.
 *
 * Magento_Catalog/catalog/base-image-uploader gates uploads through Uppy's own
 * `restrictions.allowedFileTypes`, written inline as
 * ['.gif', '.jpeg', '.jpg', '.png']. Adding to that list is all this mixin does.
 *
 * See Spartrak_MediaWebp/js/additional-file-types for why the Uppy constructor
 * is the seam, and for the server-side counterpart.
 */
define([
    'jquery',
    'Spartrak_MediaWebp/js/additional-file-types',
    'jquery/uppy-core'
], function ($, additionalFileTypes) {
    'use strict';

    /**
     * Merge the extra types into an Uppy options object, in place.
     *
     * @param {Object} options
     * @return {Object}
     */
    function addFileTypes(options) {
        var allowed = options && options.restrictions && options.restrictions.allowedFileTypes;

        if (!Array.isArray(allowed)) {
            return options;
        }

        options.restrictions.allowedFileTypes = allowed.concat(
            additionalFileTypes.fileTypes.filter(function (fileType) {
                return allowed.indexOf(fileType) === -1;
            })
        );

        return options;
    }

    return function (baseImage) {
        $.widget('mage.baseImage', baseImage, {

            /** @inheritdoc */
            _create: function () {
                var widget = this;

                additionalFileTypes.whileDecoratingUppyOptions(addFileTypes, function () {
                    widget._super();
                });
            }
        });

        return $.mage.baseImage;
    };
});
