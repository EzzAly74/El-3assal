/**
 * Spartrak RTL — product gallery reading order.
 *
 * THE PROBLEM. Fotorama has exactly one layout direction. Its thumbnail strip
 * is a `white-space: nowrap` shaft of inline-block frames, and every position
 * it computes is a running sum from the shaft's physical LEFT edge:
 *
 *     frameData.l = left;  left += thumbwidth + opts.thumbmargin;
 *
 * (fotorama.js, frameAppend). `.fotorama__thumb-border` — the ring around the
 * active thumb — is then placed with `translate3d(l, 0, 0)`, thumbsDraw()
 * decides which thumbs to lazy-load from the same numbers, and the drag
 * handler moves the shaft along the same axis. So the strip renders
 * left-to-right on an Arabic store: the first product image lands on the far
 * left, where an RTL reader expects the last one.
 *
 * WHY THIS IS NOT DONE IN CSS. Every CSS way to reverse the strip — `direction`
 * on the shaft, `flex-direction: row-reverse`, mirroring a wrapper with
 * `scaleX(-1)` — moves the FRAMES without moving the coordinate space those
 * three pieces of JS share. The active ring then lands on the mirror image of
 * the right thumb, and a mirrored wrapper additionally inverts drag: the strip
 * follows the finger backwards. There is no CSS-only fix that leaves the
 * widget internally consistent.
 *
 * WHY NOT FOTORAMA'S OWN `direction: 'rtl'`. It exists, and it reverses the
 * data exactly like this file does — but only ONCE, guarded by a flag that is
 * never cleared (`changeToRtl()`), so the first `updateData()` puts the
 * gallery back to LTR. Magento calls that on every configurable-product option
 * change (Magento_ConfigurableProduct/js/configurable, `_changeProductImage`),
 * so on this catalogue the thumbnails would silently flip back the moment a
 * shopper picked a variant. The option is also unreachable from a theme: the
 * key set is fixed in `Magento\Catalog\Block\Product\View\GalleryOptions`, and
 * Porto's gallery.phtml hard-codes its own list — reaching it would mean
 * copying a ~450-line Porto template into this theme.
 *
 * WHAT THIS DOES INSTEAD. Hands fotorama the images already in RTL order and
 * lets it do the only thing it knows how to do. Everything downstream stays
 * consistent because nothing downstream is touched: the ring, the lazy-load
 * window, drag, the shadows, the stage, the fullscreen view and the video
 * frames all read the same reversed array. Magento re-selects the main image
 * itself afterwards (`getMainImageIndex` in mage/gallery/gallery), so the
 * product still opens on the image the admin marked as main, now at the
 * inline-start of the strip.
 *
 * The stage order reverses with it, which is the point rather than a side
 * effect: the arrows sit at fixed physical edges, so on an RTL store the
 * right-hand arrow has to walk backwards through the catalogue order for the
 * gallery to advance the way the page reads.
 *
 * SCOPE. This file exists only in Spartrak/spartrak_rtl, so the LTR store
 * never requests it and pays nothing — there is no direction test at runtime
 * and no dead branch in the bundle. It is a mixin, so it merges into
 * mage/gallery/gallery's own bundle and adds no request; mage/gallery/gallery
 * is a PDP-only module and initialises after paint, so this is off the LCP
 * path entirely.
 */
define([], function () {
    'use strict';

    /**
     * Reverses a gallery data array, leaving anything else untouched — the API
     * below is public and its argument is not guaranteed to be an array.
     *
     * @param {*} data
     * @returns {*}
     */
    function toRtlOrder(data) {
        return Array.isArray(data) ? data.slice().reverse() : data;
    }

    return function (Gallery) {
        return Gallery.extend({
            /**
             * Reverses the initial set before the widget reads it. `config` is
             * the same object the parent receives, and `_super()` with no
             * arguments forwards the original ones (mage/utils/wrapper).
             *
             * @param {Object} config
             * @returns {Object} chainable
             */
            initialize: function (config) {
                if (config) {
                    config.data = toRtlOrder(config.data);
                }

                return this._super();
            },

            /**
             * Keeps later sets in the same order. `updateData` is built as a
             * closure inside the parent's `initApi`, so it is patched on the
             * finished object rather than overridden on the prototype.
             *
             * @returns {*}
             */
            initApi: function () {
                var result = this._super(),
                    api = this.settings.api,
                    updateData = api && api.updateData;

                if (typeof updateData === 'function') {
                    api.updateData = function (data) {
                        return updateData.call(this, toRtlOrder(data));
                    };
                }

                return result;
            }
        });
    };
});
