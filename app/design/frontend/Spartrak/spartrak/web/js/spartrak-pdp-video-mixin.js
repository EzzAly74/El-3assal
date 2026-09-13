/**
 * Spartrak — product video on the PDP, as a layer over Magento's own gallery.
 *
 * ===========================================================================
 * NOTHING IS LOADED UNTIL A SHOPPER ASKS
 * ===========================================================================
 * A product video is a media gallery entry, so Magento's Fotorama gallery is
 * ALREADY drawing its poster and its thumbnail. This file adds one thing to
 * that: a play button over the active stage frame when that frame is a video,
 * and an iframe built on the click.
 *
 * Before the click there is no iframe, no provider request and no player. That
 * is the whole reason `product.info.media.video` stays removed in
 * Magento_Catalog/layout/catalog_product_view.xml: Magento's own storefront
 * player pulls fotorama-add-video-events, load-player and the entire Vimeo
 * SDK — four requests and ~37 KB of parsed JavaScript — onto EVERY product
 * page, including the ~8,900 in this catalogue that have no video at all
 * (CLAUDE.md section 4: "third-party scripts/tags loading before it").
 *
 * ===========================================================================
 * WHY A MIXIN AND NOT A BLOCK + WIDGET
 * ===========================================================================
 * Everything this needs is already in the gallery's own configuration.
 * Magento\Catalog\Block\Product\View\Gallery::getGalleryImagesJson() puts
 * `videoUrl` on every entry (Gallery.php:145), `mage/gallery/gallery` hands
 * that array to Fotorama, and Fotorama clones it onto its frame objects
 * (fotorama.js:2036) — so `activeFrame.videoUrl` is the video, already
 * server-resolved, already store-scoped, already in the page.
 *
 * A mixin therefore needs no PHP block, no template, no layout node and no
 * `data-mage-init`. And because it merges into `mage/gallery/gallery`'s own
 * bundle — a module every PDP already downloads — it adds ZERO requests. A
 * product with no video pays for one array scan and returns.
 *
 * ===========================================================================
 * IDENTITY, NEVER INDEX — WHICH IS WHY RTL IS FREE
 * ===========================================================================
 * The video is read off the FRAME THE SHOPPER IS LOOKING AT, so there is no
 * second array to keep aligned with Fotorama's.
 *
 * That is load-bearing on this storefront. `js/spartrak-gallery-rtl-mixin`
 * reverses the gallery array so Arabic reads right to left, and Magento's own
 * player attaches its videos BY ARRAY INDEX (`videoData[i]` against
 * `fotorama.data[i]`, fotorama-add-video-events.js:484-486) — which is exactly
 * why its videos land on the wrong frames here. Reading the frame makes the
 * reversal irrelevant instead of something to compensate for.
 *
 * ===========================================================================
 * WHY IT ATTACHES IN initApi AND NOT initGallery
 * ===========================================================================
 * This replaces a widget that bound `fotorama:show` BEFORE Fotorama had been
 * constructed. Fotorama fires that event from inside its own initialisation,
 * three lines before it assigns `showStage.onEnd` (fotorama.js:3056 vs 3063),
 * so a handler that threw there took the whole gallery down with it: the
 * exception escaped `$fotoramaElement.fotorama(config)`, `initGallery` never
 * reached the `f:load` binding that clears `_block-content-loading`, `initApi`
 * never ran, and the next `resize` threw `showStage.onEnd is not a function`.
 * The gallery sat on its spinner forever.
 *
 * `initApi` is the seam that cannot do that. `_super()` runs first, so by the
 * time anything below executes the gallery is fully built, its API is
 * published and `gallery:loaded` has fired — and every DOM lookup here
 * tolerates a miss and returns rather than dereferencing it.
 */
define([
    'jquery',
    'js/spartrak-video-source',
    'mage/translate'
], function ($, videoSource) {
    'use strict';

    return function (Gallery) {
        return Gallery.extend({
            /**
             * @returns {*} whatever the gallery's own initApi returns
             */
            initApi: function () {
                var result = this._super();

                this._spartrakVideoInit();

                return result;
            },

            /**
             * Two event bindings, and that is the whole install cost.
             *
             * DELIBERATELY NOT GATED on the product having a video. An earlier
             * draft scanned the initial gallery array and returned early if
             * nothing in it was playable — which is wrong for a configurable
             * product, because Magento REPLACES the gallery when a shopper
             * picks an option (Magento_ConfigurableProduct/js/configurable
             * calls the gallery's `updateData`). A parent whose own gallery has
             * no video, with a variant that does, would have been gated out
             * before that video ever existed.
             *
             * So the decision is made per frame instead, in
             * _spartrakVideoSync, and nothing here is created speculatively:
             * the layer is not built until a frame actually turns out to be a
             * video. A product with none pays for two handlers and one string
             * test per frame change, and no DOM at all.
             *
             * @private
             */
            _spartrakVideoInit: function () {
                var self = this,
                    root = this._spartrakVideoRoot();

                if (!root.length) {
                    return;
                }

                // Bound on the FOTORAMA element, not on the placeholder — see
                // _spartrakVideoRoot. `fotorama:show` is triggered there
                // directly, so this covers normal navigation, keyboard, swipe,
                // fullscreen and the reload Magento performs on a configurable
                // option change, without relying on the event bubbling out of
                // a subtree that moves.
                root
                    .on('fotorama:show', function () {
                        self._spartrakVideoSync();
                    })
                    .on('click', '[data-spartrak-video-play]', function (event) {
                        event.preventDefault();
                        self._spartrakVideoPlay();
                    });

                this._spartrakVideoSync();
            },

            /**
             * The gallery element itself — `[data-gallery-role="gallery"]`, the
             * one `mage/gallery/gallery` hands to Fotorama.
             *
             * ===============================================================
             * THIS IS NOT THE SAME ELEMENT AS settings.$element, AND THE
             * DIFFERENCE IS THE WHOLE REASON FULLSCREEN WORKS
             * ===============================================================
             * `settings.$element` is the PLACEHOLDER. Fotorama's fullscreen
             * does this (fotorama.js:3176):
             *
             *     $fotorama.addClass(fullscreenClass).appendTo($BODY...)
             *
             * — it MOVES the gallery out of the placeholder and re-parents it
             * onto <body>, then puts it back on exit (`insertAfter($anchor)`).
             *
             * Everything this file owns lives inside that subtree: the stage,
             * the layer, the play button and the player. So binding on the
             * placeholder meant that the moment a shopper opened fullscreen,
             * the delegated click handler no longer had the button as a
             * descendant and `fotorama:show` no longer bubbled to it. The
             * button was still on screen — it had travelled to <body> with
             * everything else — and pressing it did nothing at all.
             *
             * Binding here instead makes the relocation a non-event: the
             * handlers are attached INSIDE the subtree that moves, so they
             * move with it, and the video keeps playing across the transition
             * rather than being torn down.
             *
             * `settings.$elementF` is Magento's own reference to it, set in
             * initGallery (mage/gallery/gallery.js). The `find()` is a fallback
             * for the theoretical case of a gallery build that has not set it.
             *
             * @returns {jQuery}
             * @private
             */
            _spartrakVideoRoot: function () {
                var settings = this.settings || {};

                return settings.$elementF && settings.$elementF.length
                    ? settings.$elementF
                    : settings.$element.find('[data-gallery-role="gallery"]').first();
            },

            /**
             * A frame's video, or null. The single question this file asks, and
             * it is answered by js/spartrak-video-source for both storefront
             * surfaces so the PDP and the homepage rail cannot disagree about
             * what a playable video is.
             *
             * @param {Object} frame
             * @returns {{type: String, id: String}|null}
             * @private
             */
            _spartrakVideoOf: function (frame) {
                return frame ? videoSource.parse(frame.videoUrl) : null;
            },

            /**
             * The overlay — created on demand, and reattached if Fotorama has
             * replaced the stage under it.
             *
             * It lives in `.fotorama__stage` rather than inside a frame because
             * Fotorama recycles frame elements and would discard anything put
             * in one.
             *
             * `create` is what keeps a product with no video free of DOM: the
             * sync path asks WITHOUT it and simply gets null, so no element is
             * ever appended to the ~8,900 galleries that have no video to show.
             *
             * Returns null rather than dereferencing when there is nothing to
             * attach to. That is not defensiveness for its own sake — it is the
             * bug this file replaces. The old widget did `this.stage.append()`
             * on a property that was only set by a LATER event, so the very
             * first `fotorama:show` threw and took the gallery with it.
             *
             * @param {Boolean} [create] - build the layer if it does not exist.
             * @returns {jQuery|null}
             * @private
             */
            _spartrakVideoLayer: function (create) {
                // Resolved from the fotorama element, NOT the placeholder, so
                // it is still found once fullscreen has re-parented the
                // gallery onto <body>. See _spartrakVideoRoot.
                var stage = this._spartrakVideoRoot().find('.fotorama__stage').first(),
                    attached = this.spartrakVideoLayer &&
                        this.spartrakVideoLayer.length &&
                        stage.length &&
                        $.contains(stage[0], this.spartrakVideoLayer[0]);

                if (attached) {
                    return this.spartrakVideoLayer;
                }

                if (!create || !stage.length) {
                    return null;
                }

                this.spartrakVideoLayer = $('<div class="spartrak-pdp-video__layer" hidden></div>')
                    .appendTo(stage);

                return this.spartrakVideoLayer;
            },

            /**
             * The frame now showing, straight from Fotorama.
             *
             * @returns {Object|null}
             * @private
             */
            _spartrakVideoFrame: function () {
                var api = this.settings && this.settings.api;

                return api && api.fotorama ? api.fotorama.activeFrame : null;
            },

            /**
             * Puts the play affordance on the active frame if it is a video,
             * and takes it off if it is not.
             *
             * @private
             */
            _spartrakVideoSync: function () {
                var frame = this._spartrakVideoFrame(),
                    video = this._spartrakVideoOf(frame),
                    layer,
                    label;

                // A frame change is a change of subject: whatever was playing
                // is no longer what the shopper is looking at, so it stops and
                // its connection to the provider goes with it. This is what
                // stops a video streaming in the background while the shopper
                // reads the next image.
                this._spartrakVideoStop();

                if (!video) {
                    // No `create` argument: an image frame must not bring a
                    // layer into existence just to hide it.
                    layer = this._spartrakVideoLayer();

                    if (layer) {
                        layer.attr('hidden', 'hidden').empty();
                    }

                    return;
                }

                layer = this._spartrakVideoLayer(true);

                if (!layer) {
                    return;
                }

                label = frame.caption
                    ? $.mage.__('Play video: %1').replace('%1', frame.caption)
                    : $.mage.__('Play video');

                layer.removeAttr('hidden').empty().append(
                    $('<button/>', {
                        'type': 'button',
                        'class': 'spartrak-pdp-video__play',
                        'aria-label': label
                    }).attr('data-spartrak-video-play', '')
                );
            },

            /**
             * Builds the player. This is the FIRST moment anything is requested
             * from YouTube or Vimeo — before this click the shopper's browser
             * has spoken to neither.
             *
             * @private
             */
            _spartrakVideoPlay: function () {
                var frame = this._spartrakVideoFrame(),
                    video = this._spartrakVideoOf(frame),
                    layer = this._spartrakVideoLayer(true);

                if (!video || !layer) {
                    return;
                }

                // Unmuted, because the shopper has just pressed play: that
                // gesture is the permission both providers' autoplay policies
                // ask for, so the video starts audible the way a shopper
                // expects rather than silently.
                layer.addClass('spartrak-pdp-video__layer--playing').empty().append(
                    $('<iframe/>', {
                        'class': 'spartrak-pdp-video__player',
                        'src': videoSource.embed(
                            video,
                            videoSource.params(video, { muted: false, captions: false, loop: false })
                        ),
                        'title': frame.caption || $.mage.__('Product video'),
                        'allow': 'autoplay; encrypted-media; picture-in-picture; fullscreen',
                        'allowfullscreen': 'allowfullscreen',
                        // Third-party content: no reach into this document, and
                        // referrer information kept to the origin.
                        'referrerpolicy': 'strict-origin-when-cross-origin'
                    })
                );
            },

            /**
             * Tears the player down. Removing the element is what closes the
             * connection — pausing an iframe is not something a cross-origin
             * document can do without loading that provider's player API,
             * which is the third-party weight this file exists to keep off the
             * page.
             *
             * @private
             */
            _spartrakVideoStop: function () {
                if (this.spartrakVideoLayer && this.spartrakVideoLayer.length) {
                    this.spartrakVideoLayer
                        .removeClass('spartrak-pdp-video__layer--playing')
                        .find('iframe')
                        .remove();
                }
            }
        });
    };
});
