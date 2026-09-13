/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 *
 * Widens the PRODUCT GALLERY uploader's client-side file-type restriction.
 *
 * Magento_Catalog/catalog/base-image-uploader gates uploads through Uppy's own
 * `restrictions.allowedFileTypes`, written inline as
 * ['.gif', '.jpeg', '.jpg', '.png']. Adding to that list is all this mixin does.
 *
 * See Spartrak_MediaWebp/js/additional-file-types for why the Uppy class
 * prototype is the seam, and for the server-side counterpart.
 */
define([
    'jquery',
    'Spartrak_MediaWebp/js/additional-file-types',
    'jquery/uppy-core'
], function ($, additionalFileTypes) {
    'use strict';

    /**
     * Add the extra types to a live Uppy instance's restrictions.
     *
     * @param {Object} uppy
     * @return {void}
     */
    function addFileTypes(uppy) {
        var allowed = uppy.opts && uppy.opts.restrictions && uppy.opts.restrictions.allowedFileTypes,
            missing;

        if (!Array.isArray(allowed)) {
            return;
        }

        missing = additionalFileTypes.fileTypes.filter(function (fileType) {
            return allowed.indexOf(fileType) === -1;
        });

        if (!missing.length) {
            return;
        }

        additionalFileTypes.setUppyOptions(uppy, {
            restrictions: {
                allowedFileTypes: allowed.concat(missing)
            }
        });
    }

    return function (baseImage) {
        $.widget('mage.baseImage', baseImage, {

            /** @inheritdoc */
            _create: function () {
                var widget = this;

                additionalFileTypes.whileDecoratingUppyInstances(addFileTypes, function () {
                    widget._super();
                });
            }
        });

        return $.mage.baseImage;
    };
});
