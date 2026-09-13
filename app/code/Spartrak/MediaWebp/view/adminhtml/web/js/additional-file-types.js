/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 *
 * The formats this module adds to the admin uploaders, plus the one seam both
 * core uploader widgets leave open.
 *
 * Magento_Catalog/catalog/base-image-uploader and Magento_Backend/js/media-uploader
 * each build their Uppy instance inside _create(), hard-code their own
 * file-type gate in the options they pass it, keep the instance in a local
 * variable, and expose no widget option. So there is nothing to configure and
 * no handle to reconfigure afterwards.
 *
 * The `Uppy` global cannot be wrapped: Magento ships Uppy 4.1 as an esbuild
 * bundle whose namespace properties are defined with
 * `Object.defineProperty(ns, name, { get, enumerable: true })` - getter-only AND
 * non-configurable, so `window.Uppy.Uppy = ...` throws
 * "Cannot set property Uppy of #<Object> which has only a getter".
 *
 * What IS writable is the Uppy class prototype. Both widgets call `uppy.use()`
 * to install their first plugin immediately after construction, so `use` is
 * patched for the duration of _create(), handing each new instance to a
 * decorator before its first plugin is installed, and restored in a finally
 * block. The decorators then reconfigure the instance through Uppy's own public
 * setOptions() - no private state is touched.
 *
 * Server-side counterparts: etc/di.xml and etc/adminhtml/di.xml. Core keeps
 * these lists independent of the server's, so they have to be kept in step.
 */
define([], function () {
    'use strict';

    /**
     * Marks an instance as already decorated, so a widget that calls use()
     * several times is only decorated once.
     */
    var DECORATED = '__spartrakMediaWebpDecorated';

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
         * Reconfigure a live Uppy instance through its public API.
         *
         * @param {Object} uppy
         * @param {Object} options
         * @return {Boolean} whether the options could be applied
         */
        setUppyOptions: function (uppy, options) {
            if (!uppy || typeof uppy.setOptions !== 'function') {
                return false;
            }

            uppy.setOptions(options);

            return true;
        },

        /**
         * Run `callback` with every Uppy instance built inside it handed to
         * `decorate` just before that instance installs its first plugin.
         *
         * Degrades to plain `callback()` if the seam is not there - a changed
         * Uppy build must leave the admin working, not throw.
         *
         * @param {Function} decorate - receives the Uppy instance
         * @param {Function} callback
         * @return {*} whatever `callback` returns
         */
        whileDecoratingUppyInstances: function (decorate, callback) {
            var uppyClass = window.Uppy && window.Uppy.Uppy,
                prototype = uppyClass && uppyClass.prototype,
                originalUse;

            if (!prototype || typeof prototype.use !== 'function') {
                return callback();
            }

            originalUse = prototype.use;

            try {
                prototype.use = function () {
                    if (!this[DECORATED]) {
                        this[DECORATED] = true;
                        decorate(this);
                    }

                    return originalUse.apply(this, arguments);
                };
            } catch (e) {
                // Frozen prototype: leave core behaviour exactly as it was.
                return callback();
            }

            try {
                return callback();
            } finally {
                prototype.use = originalUse;
            }
        }
    };
});
