/**
 * Spartrak — product video on the PDP.
 *
 * ===========================================================================
 * NOTHING HAPPENS UNTIL A SHOPPER ASKS
 * ===========================================================================
 * On load this file does four things: read a JSON array, index it, find the
 * gallery, and bind two events. It creates no player, no <video>, no <iframe>,
 * and requests nothing over the network. A product with ten videos costs ten
 * entries in an array — the posters are already on the page because a video IS
 * a media gallery entry, so Fotorama is already rendering its frame and its
 * thumbnail.
 *
 * The player is built on the first click, on one frame, and torn down again
 * when the shopper moves away. There is never more than one player alive.
 *
 * ===========================================================================
 * WHY IT IS A FACADE OVER FOTORAMA AND NOT A SECOND GALLERY
 * ===========================================================================
 * The PDP gallery is Magento's own `mage/gallery/gallery` (Fotorama), and it
 * already does thumbnails, keyboard navigation, swipe, fullscreen and RTL. A
 * separate video carousel would have duplicated all of that and then had to
 * keep the two in sync — the shopper would see two galleries disagreeing about
 * which item they were looking at.
 *
 * So this widget owns exactly one thing: a layer over the active stage frame.
 * Fotorama keeps owning navigation.
 *
 * ===========================================================================
 * IDENTITY, NEVER INDEX
 * ===========================================================================
 * Videos are matched to frames by `videoUrl`, falling back to the frame's
 * image URL. NOT by position.
 *
 * That is load-bearing on this storefront. `js/spartrak-gallery-rtl-mixin`
 * reverses the gallery array so Arabic reads right to left, and
 * Magento_ProductVideo attaches its videos by array index — which is why its
 * videos would land on the wrong frames here. Keying on identity makes the
 * reversal irrelevant instead of something to compensate for.
 *
 * ===========================================================================
 * WHY jQuery, WHEN THE HOUSE PREFERENCE IS `define([], fn)`
 * ===========================================================================
 * Fotorama publishes its state through jQuery custom events (`fotorama:show`,
 * `gallery:loaded`). Those are dispatched with jQuery's own `.trigger()` and
 * do NOT reach a native `addEventListener`, so there is no dependency-free way
 * to know which frame is showing. jQuery and the widget factory are already on
 * every PDP; `jquery/ui` — the 105 KB aggregate — is deliberately not used.
 */
define(['jquery', 'jquery-ui-modules/widget'], function ($) {
    'use strict';

    /**
     * Embed prefixes are CONSTANTS in this file. A provider video reaches this
     * widget as a validated id and never as a URL, so there is no path by
     * which merchant input becomes an iframe `src`. Same boundary the homepage
     * rail draws (js/spartrak-home-video.js), and the server draws it too —
     * see Spartrak\ProductVideo\Model\Video\SourceNormalizer.
     *
     * youtube-nocookie and Vimeo's `dnt=1` are the privacy-preserving variants
     * of each: no cookie is set, and no view is attributed, until the shopper
     * presses play.
     */
    var YOUTUBE = 'https://www.youtube-nocookie.com/embed/',
        VIMEO = 'https://player.vimeo.com/video/';

    $.widget('mage.spartrakPdpVideo', {
        options: {
            videos: []
        },

        _create: function () {
            if (!this.options.videos || !this.options.videos.length) {
                return;
            }

            this.gallery = $('[data-gallery-role="gallery-placeholder"]').first();

            if (!this.gallery.length) {
                return;
            }

            this._index();

            this.active = null;
            this.player = null;

            // Both events, because they answer different questions.
            // `gallery:loaded` fires once, when Fotorama has built its DOM and
            // there is finally a stage to attach to. `fotorama:show` fires on
            // every frame change thereafter.
            this._on(this.gallery, {
                'gallery:loaded': this._onGalleryReady,
                'fotorama:show': this._onFrameChange
            });

            this._bindPageState();
        },

        /**
         * Two lookups over the same descriptors, tried in order.
         *
         * `videoUrl` is the identity — it is what distinguishes two videos on
         * one product. The poster URL is a fallback for the case where
         * Fotorama has not carried the custom key onto its frame object; it is
         * equally index-free.
         *
         * @private
         */
        _index: function () {
            var byKey = {},
                byPoster = {};

            this.options.videos.forEach(function (video) {
                if (video.key) {
                    byKey[video.key] = video;
                }

                if (video.poster) {
                    byPoster[video.poster] = video;
                }
            });

            this.byKey = byKey;
            this.byPoster = byPoster;
        },

        /**
         * @private
         */
        _onGalleryReady: function () {
            this.api = this.gallery.data('gallery');
            this.stage = this.gallery.find('.fotorama__stage').first();

            if (!this.stage.length) {
                return;
            }

            this._openFeatured();
            this._sync();
        },

        /**
         * "Open on this video" — the featured flag from the product form.
         *
         * The index is LOOKED UP by identity rather than assumed, so this is
         * correct on a reversed RTL gallery as well. Nothing is played: the
         * shopper is shown the video's poster with a play button on it, which
         * is the whole point of a facade.
         *
         * @private
         */
        _openFeatured: function () {
            var self = this,
                target = -1,
                frames;

            if (!this.api || !this.api.fotorama || !this.api.fotorama.data) {
                return;
            }

            frames = this.api.fotorama.data;

            frames.some(function (frame, i) {
                if (self._match(frame) && self._match(frame).featured) {
                    target = i;

                    return true;
                }

                return false;
            });

            if (target > -1 && typeof this.api.fotorama.show === 'function') {
                this.api.fotorama.show(target);
            }
        },

        /**
         * @private
         */
        _onFrameChange: function () {
            // A frame change is a change of subject. Whatever was playing is
            // no longer what the shopper is looking at, so it stops and its
            // network connection goes with it — this is what stops a
            // background <video> or iframe streaming while the shopper reads
            // the next image.
            this._teardown();
            this._sync();
        },

        /**
         * Decides whether the frame now showing is a video, and puts the play
         * affordance on it if so.
         *
         * @private
         */
        _sync: function () {
            var frame = this.api && this.api.fotorama ? this.api.fotorama.activeFrame : null,
                video = frame ? this._match(frame) : null;

            this.active = video;

            if (!video) {
                this._layer().attr('hidden', 'hidden').empty();

                return;
            }

            this._renderPoster(video);
        },

        /**
         * @param {Object} frame
         * @return {Object|null}
         * @private
         */
        _match: function (frame) {
            if (!frame) {
                return null;
            }

            return this.byKey[frame.videoUrl] || this.byPoster[frame.img] || null;
        },

        /**
         * The layer, created once and reused. Absolutely positioned over the
         * stage rather than injected into a frame, because Fotorama recycles
         * its frame elements and would discard anything living inside one.
         *
         * @return {jQuery}
         * @private
         */
        _layer: function () {
            if (!this.layer || !this.layer.length) {
                this.layer = $('<div class="spartrak-pdp-video__layer" hidden></div>');
                this.stage.append(this.layer);
            }

            return this.layer;
        },

        /**
         * State one: a play button. No player exists yet.
         *
         * @private
         */
        _renderPoster: function (video) {
            var label = video.title
                    ? $.mage.__('Play video: %1').replace('%1', video.title)
                    : $.mage.__('Play video'),
                button = $('<button/>', {
                    'type': 'button',
                    'class': 'spartrak-pdp-video__play',
                    'aria-label': label
                }).attr('data-spartrak-video-play', '');

            this._layer().removeAttr('hidden').empty().append(button);
        },

        /**
         * State two: the player. Built here and nowhere else.
         *
         * @private
         */
        _activate: function () {
            var video = this.active;

            if (!video || this.player) {
                return;
            }

            this.player = video.type === 'youtube' || video.type === 'vimeo'
                ? this._buildEmbed(video)
                : this._buildNative(video);

            if (!this.player) {
                return;
            }

            this._layer()
                .addClass('spartrak-pdp-video__layer--playing')
                .empty()
                .append(this.player);

            if (video.chapters && video.chapters.length && video.type !== 'youtube' && video.type !== 'vimeo') {
                this._layer().append(this._buildChapters(video));
            }

            if (video.download && video.src) {
                this._layer().append(this._buildDownload(video));
            }
        },

        /**
         * A native <video>, which is the whole player for uploaded and
         * direct-URL sources. No library: the browser's own element already
         * has controls, keyboard support, fullscreen, captions and Picture in
         * Picture, and shipping a JS player to re-draw them would be tens of
         * kilobytes to end up with less.
         *
         * `preload="none"` is the load-bearing attribute — the source is not
         * fetched until play() is called, so even reaching this function
         * downloads nothing.
         *
         * @return {jQuery}
         * @private
         */
        _buildNative: function (video) {
            var el = $('<video/>', {
                    'class': 'spartrak-pdp-video__player',
                    'playsinline': 'playsinline',
                    'preload': 'none'
                }),
                node;

            if (video.controls) {
                el.attr('controls', 'controls');
            }

            if (video.loop) {
                el.attr('loop', 'loop');
            }

            // MUTED IS FORCED WHEN AUTOPLAY IS ON, and not as a convenience:
            // every browser refuses to autoplay audible media, so an unmuted
            // autoplay is simply a video that does not start. Honouring the
            // merchant's autoplay switch means overriding their mute switch —
            // the alternative is a setting that silently does nothing.
            if (video.muted || video.autoplay) {
                el.attr('muted', 'muted').prop('muted', true);
            }

            el.append($('<source/>', { 'src': video.src, 'type': video.mime || '' }));

            // Not a message about a missing plugin: a browser this old is not
            // going to get a working player out of us either, so it is offered
            // the file instead.
            el.append(
                $('<a/>', { 'href': video.src, 'class': 'spartrak-pdp-video__fallback' })
                    .text($.mage.__('Download this video'))
            );

            node = el[0];

            if (video.autoplay) {
                // play() rejects on a policy violation. Swallowed on purpose:
                // the shopper still has a fully working control bar, and an
                // unhandled rejection in the console is noise, not information.
                var started = node.play();

                if (started && typeof started.catch === 'function') {
                    started.catch(function () {});
                }
            }

            return el;
        },

        /**
         * A third-party iframe, created HERE and only here — which is the
         * first moment anything from YouTube or Vimeo is requested. Before
         * this click the shopper's browser has spoken to neither.
         *
         * @return {jQuery}
         * @private
         */
        _buildEmbed: function (video) {
            var base = video.type === 'youtube' ? YOUTUBE : VIMEO,
                params = video.type === 'youtube'
                    ? ['rel=0', 'playsinline=1', 'modestbranding=1']
                    : ['dnt=1', 'playsinline=1'],
                src;

            if (!video.id) {
                return null;
            }

            // Autoplay is unconditional here: the shopper has just pressed a
            // play button, so "start playing" is the whole request. Muted goes
            // with it for the same policy reason as the native player.
            params.push('autoplay=1');
            params.push((video.type === 'youtube' ? 'mute=' : 'muted=') + (video.muted ? '1' : '0'));

            if (video.loop) {
                params.push('loop=1');

                if (video.type === 'youtube') {
                    // YouTube's loop needs the playlist to be the video itself,
                    // which is its own documented quirk rather than ours.
                    params.push('playlist=' + encodeURIComponent(video.id));
                }
            }

            if (!video.controls) {
                params.push('controls=0');
            }

            src = base + encodeURIComponent(video.id) + '?' + params.join('&');

            return $('<iframe/>', {
                'class': 'spartrak-pdp-video__player spartrak-pdp-video__player--embed',
                'src': src,
                'title': video.title || $.mage.__('Product video'),
                'frameborder': '0',
                'allow': 'autoplay; encrypted-media; picture-in-picture; fullscreen',
                'allowfullscreen': 'allowfullscreen',
                'referrerpolicy': 'strict-origin-when-cross-origin'
            });
        },

        /**
         * Chapter markers. Native sources only — seeking a YouTube or Vimeo
         * iframe means loading that provider's player API and driving it,
         * which is exactly the third-party weight this module exists to keep
         * off the page. Both providers already ship chapter UI of their own.
         *
         * @return {jQuery}
         * @private
         */
        _buildChapters: function (video) {
            var self = this,
                list = $('<ul/>', {
                    'class': 'spartrak-pdp-video__chapters',
                    'aria-label': $.mage.__('Chapters')
                });

            video.chapters.forEach(function (chapter) {
                var seconds = chapter[0],
                    label = chapter[1];

                list.append(
                    $('<li/>').append(
                        $('<button/>', {
                            'type': 'button',
                            'class': 'spartrak-pdp-video__chapter'
                        })
                            .attr('data-spartrak-video-seek', seconds)
                            .text(self._timecode(seconds) + '  ' + label)
                    )
                );
            });

            return list;
        },

        /**
         * @return {jQuery}
         * @private
         */
        _buildDownload: function (video) {
            return $('<a/>', {
                'class': 'spartrak-pdp-video__download',
                'href': video.src,
                'download': ''
            }).text($.mage.__('Download this video'));
        },

        /**
         * @param {Number} seconds
         * @return {String}
         * @private
         */
        _timecode: function (seconds) {
            var minutes = Math.floor(seconds / 60),
                rest = Math.floor(seconds % 60);

            return minutes + ':' + (rest < 10 ? '0' : '') + rest;
        },

        /**
         * Delegated, because the play button and the chapter buttons are
         * created and destroyed as the shopper moves through the gallery —
         * binding to them directly would mean rebinding on every frame change.
         *
         * @private
         */
        _bindPageState: function () {
            var self = this;

            this._on(this.element.add(this.gallery), {});

            $(document).on('click.spartrakPdpVideo', '[data-spartrak-video-play]', function (event) {
                event.preventDefault();
                self._activate();
            });

            $(document).on('click.spartrakPdpVideo', '[data-spartrak-video-seek]', function (event) {
                var node = self.player && self.player[0];

                event.preventDefault();

                if (node && typeof node.currentTime !== 'undefined') {
                    node.currentTime = parseInt($(this).attr('data-spartrak-video-seek'), 10) || 0;

                    if (node.paused && typeof node.play === 'function') {
                        var resumed = node.play();

                        if (resumed && typeof resumed.catch === 'function') {
                            resumed.catch(function () {});
                        }
                    }
                }
            });

            // A tab in the background is not being watched. Pausing there is
            // free bandwidth and battery back, and the browser will not always
            // do it for us.
            this._onVisibility = function () {
                if (document.hidden) {
                    self._pause();
                }
            };
            document.addEventListener('visibilitychange', this._onVisibility);

            // Scrolled past. Same argument, and the same house pattern as
            // js/spartrak-home-tiles.js — feature-detected, and a no-op where
            // it is missing rather than a hard dependency.
            if (window.IntersectionObserver) {
                this.observer = new window.IntersectionObserver(function (entries) {
                    if (!entries[entries.length - 1].isIntersecting) {
                        self._pause();
                    }
                }, { threshold: 0 });
                this.observer.observe(this.gallery[0]);
            }
        },

        /**
         * Pause, but keep the player. The shopper may scroll back.
         *
         * An iframe cannot be paused from outside without the provider's API,
         * so there is nothing honest to do for one here — it is torn down on a
         * frame change instead, which is the case that actually matters.
         *
         * @private
         */
        _pause: function () {
            var node = this.player && this.player[0];

            if (node && typeof node.pause === 'function') {
                node.pause();
            }
        },

        /**
         * Destroy the player and release the network.
         *
         * `removeAttribute('src')` then `load()` is the part that matters for a
         * native video: removing the element alone leaves some browsers holding
         * the connection and finishing the buffer for a video nobody is
         * watching. Emptying the source and reloading tells the media element
         * there is nothing to fetch.
         *
         * For an iframe, removing the node is the teardown — it takes the
         * provider's player, its timers and its sockets with it.
         *
         * @private
         */
        _teardown: function () {
            var node = this.player && this.player[0];

            if (!node) {
                return;
            }

            if (typeof node.pause === 'function') {
                node.pause();
            }

            if (node.tagName === 'VIDEO') {
                node.removeAttribute('src');
                $(node).find('source').remove();

                if (typeof node.load === 'function') {
                    node.load();
                }
            }

            this.player.remove();
            this.player = null;

            if (this.layer && this.layer.length) {
                this.layer.removeClass('spartrak-pdp-video__layer--playing');
            }
        },

        /**
         * @private
         */
        _destroy: function () {
            this._teardown();

            $(document).off('.spartrakPdpVideo');

            if (this.observer) {
                this.observer.disconnect();
                this.observer = null;
            }

            if (this._onVisibility) {
                document.removeEventListener('visibilitychange', this._onVisibility);
                this._onVisibility = null;
            }
        }
    });

    return $.mage.spartrakPdpVideo;
});
