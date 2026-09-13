/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 *
 * The formats this module adds to the admin uploaders, plus the one seam both
 * core uploader widgets leave open.
 *
 * Magento_Catalog/catalog/base-image-uploader and Magento_Backend/js/media-uploader
 * each build their Uppy instance inside _create(), keep it in a local variable,
 * and hard-code their own file-type gate in the options object they pass to the
 * constructor. Neither exposes a widget option, and neither keeps a handle we
 * could call uppy.setOptions() on afterwards.
 *
 * What they DO both do is call `new Uppy.Uppy(options)` while _create() runs. So
 * the constructor is swapped for exactly that window, the options object is
 * decorated on its way through, and the constructor is put back in a finally
 * block. _create() is synchronous, so nothing outside that window ever sees the
 * wrapper.
 *
 * Server-side counterparts: etc/di.xml and etc/adminhtml/di.xml. Core keeps
 * these lists independent of the server's, so they have to be kept in step.
 */
define([], function () {
    'use strict';

    return {
        /**
         * Bare extensions, as Magento_Backend/js/media-uploader compares them.
         */
        extensions: ['webp'],

        /**
         * Dotted form, as Uppy's `restrictions.allowedFileTypes` expects.
         */
        fileTypes: ['.webp'],

        /**
         * Whether a file Uppy is about to add is one of ours.
         *
         * @param {Object} file
         * @return {Boolean}
         */
        matches: function (file) {
            var extension = file && file.extension;

            return !!extension && this.extensions.indexOf(String(extension).toLowerCase()) !== -1;
        },

        /**
         * Run `callback` with every Uppy options object built inside it passed
         * through `decorate` first.
         *
         * @param {Function} decorate - receives and returns an options object
         * @param {Function} callback
         * @return {*} whatever `callback` returns
         */
        whileDecoratingUppyOptions: function (decorate, callback) {
            var namespace = window.Uppy,
                OriginalUppy;

            if (!namespace || typeof namespace.Uppy !== 'function') {
                return callback();
            }

            OriginalUppy = namespace.Uppy;

            namespace.Uppy = function (options) {
                return new OriginalUppy(decorate(options));
            };

            try {
                return callback();
            } finally {
                namespace.Uppy = OriginalUppy;
            }
        }
    };
});
