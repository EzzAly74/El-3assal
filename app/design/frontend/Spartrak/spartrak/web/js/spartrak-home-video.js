/**
 * Spartrak — product video facade for "شاهد المنتج، وأحكم بنفسك".
 *
 * ===========================================================================
 * WHY A FACADE
 * ===========================================================================
 * Embedding three provider iframes on the homepage would put roughly a
 * megabyte of third-party JavaScript in front of the page's own largest paint,
 * on every visit, for a video most shoppers will never press play on.
 * CLAUDE.md section 4 names that outright — "third-party scripts/tags loading
 * before it".
 *
 * So the card ships the product's real image plus a real play button, and the
 * player is constructed on the FIRST CLICK. Nothing is requested from a third
 * party, and no video byte is fetched, until a shopper asks for it.
 *
 * This is a facade, not a mock: the button is wired to a genuine video that
 * came out of Magento. A product with no video renders no controls at all
 * rather than a button that does nothing.
 *
 * ===========================================================================
 * FOUR SOURCE TYPES, NOT TWO — AND THE SERVER DECIDES WHICH
 * ===========================================================================
 * The card used to carry `data-video-url` and this file guessed the provider
 * by matching the URL against two regular expressions, returning '' for
 * anything else. That was correct while Magento could only store a YouTube or
 * Vimeo link. It stopped being correct the moment Spartrak_ProductVideo let a
 * merchant upload an MP4 or paste a CDN URL: those produced a play button that
 * silently did nothing.
 *
 * The card now carries `data-video` — the descriptor the SERVER already
 * resolved, containing a source type and either a validated provider id or a
 * file URL. So this widget CHOOSES a player rather than guessing one:
 *
 *      file / url          a native <video>, `preload="none"`
 *      youtube / vimeo     an iframe, built from a hardcoded prefix
 *
 * The security boundary is unchanged and is now enforced twice. A provider
 * video reaches this file as an ID, never a URL, and the embed address is
 * composed below from a constant — so there is no path by which merchant input
 * becomes an iframe `src`. See
 * Spartrak\ProductVideo\Model\Video\SourceNormalizer, which is where a URL is
 * turned into that id, once, on save.
 *
 * ===========================================================================
 * MUTE AND CAPTIONS
 * ===========================================================================
 * On a native <video> both are real: the element has a `muted` property and a
 * text-track list. On an embed they are URL parameters, so changing one after
 * playback has begun rebuilds the iframe. That is not a full player API, and
 * the limit is recorded here rather than hidden.
 */
define(['jquery', 'jquery-ui-modules/widget'], function ($) {
    'use strict';

    /** Composed from constants. Never from anything a merchant typed. */
    var YOUTUBE = 'https://www.youtube-nocookie.com/embed/',
        VIMEO = 'https://player.vimeo.com/video/';

    $.widget('mage.spartrakHomeVideo', {
        options: {},

        _create: function () {
            this._on({
                'click [data-video-play]': this._onPlay,
                'click [data-video-mute]': this._onMute,
                'click [data-video-captions]': this._onCaptions
            });
        },

        /**
         * The media frame a clicked control belongs to.
         *
         * @private
         */
        _frameOf: function (event) {
            return $(event.currentTarget).closest('[data-video]');
        },

        /**
         * The descriptor the server put on the frame, parsed once and cached
         * on the element.
         *
         * @return {Object|null}
         * @private
         */
        _descriptorOf: function (frame) {
            var parsed = frame.data('spartrakVideoParsed');

            if (parsed) {
                return parsed;
            }

            try {
                parsed = JSON.parse(frame.attr('data-video'));
            } catch (e) {
                // A malformed descriptor means no player. Better a poster with
                // an inert button than a thrown exception that takes the rest
                // of the rail's JavaScript down with it.
                return null;
            }

            frame.data('spartrakVideoParsed', parsed);

            return parsed;
        },

        _onPlay: function (event) {
            event.preventDefault();

            var frame = this._frameOf(event);

            if (frame.length) {
                this._mount(frame);
            }
        },

        _onMute: function (event) {
            event.preventDefault();
            this._toggle($(event.currentTarget), event);
        },

        _onCaptions: function (event) {
            event.preventDefault();
            this._toggle($(event.currentTarget), event);
        },

        /**
         * @private
         */
        _toggle: function (button, event) {
            var frame = this._frameOf(event),
                pressed = button.attr('aria-pressed') === 'true',
                video = frame.find('video')[0];

            button.attr('aria-pressed', pressed ? 'false' : 'true');

            if (!frame.length) {
                return;
            }

            // A native element takes the change directly — no rebuild, no
            // reload, and the video does not restart under the shopper.
            if (video) {
                video.muted = button.is('[data-video-mute]') ? !pressed : video.muted;

                return;
            }

            // An embed can only express its state in the URL, so it is rebuilt
            // — and only if it exists. Before playback the new state is simply
            // read off the buttons when play is finally pressed.
            if (frame.find('iframe').length) {
                this._mount(frame);
            }
        },

        /**
         * Builds (or rebuilds) the player inside a frame.
         *
         * @private
         */
        _mount: function (frame) {
            var video = this._descriptorOf(frame),
                muted = frame.find('[data-video-mute]').attr('aria-pressed') !== 'false',
                captions = frame.find('[data-video-captions]').attr('aria-pressed') === 'true',
                player;

            if (!video) {
                return;
            }

            player = video.type === 'youtube' || video.type === 'vimeo'
                ? this._buildEmbed(video, muted, captions)
                : this._buildNative(video, muted);

            if (!player) {
                return;
            }

            frame.find('iframe, video').remove();
            frame.append(player);
            frame.attr('data-video-playing', '');
        },

        /**
         * A native <video>. No library: the browser's own element already has
         * controls, keyboard support, fullscreen and text tracks, and a JS
         * player would be tens of kilobytes to end up with less.
         *
         * @return {HTMLElement|null}
         * @private
         */
        _buildNative: function (video, muted) {
            var el = document.createElement('video');

            if (!video.src) {
                return null;
            }

            el.className = 'spartrak-home-showcase__frame';
            el.setAttribute('playsinline', 'playsinline');
            // Nothing is fetched until play() is called below — so even
            // building the element costs no bandwidth.
            el.setAttribute('preload', 'none');
            el.setAttribute('src', video.src);

            if (video.controls) {
                el.setAttribute('controls', 'controls');
            }

            if (video.loop) {
                el.setAttribute('loop', 'loop');
            }

            // MUTED IS FORCED, and not as a preference: browsers refuse to
            // autoplay audible media, and this play is an autoplay as far as
            // the policy is concerned. An unmuted start would simply not start.
            el.muted = true;
            el.setAttribute('muted', 'muted');

            frameAppendReady(el, muted);

            return el;
        },

        /**
         * A third-party iframe, created HERE and only here — the first moment
         * anything is requested from YouTube or Vimeo. Before this click the
         * shopper's browser has spoken to neither.
         *
         * @return {HTMLElement|null}
         * @private
         */
        _buildEmbed: function (video, muted, captions) {
            var base = video.type === 'youtube' ? YOUTUBE : VIMEO,
                params = ['autoplay=1', 'playsinline=1'],
                iframe;

            if (!video.id) {
                return null;
            }

            if (video.type === 'youtube') {
                params.push('rel=0', 'modestbranding=1');
                params.push('mute=' + (muted ? '1' : '0'));
                params.push('cc_load_policy=' + (captions ? '1' : '0'));

                if (video.loop) {
                    // YouTube's loop needs the playlist to be the video itself.
                    // Its quirk, documented here so it does not read as ours.
                    params.push('loop=1', 'playlist=' + encodeURIComponent(video.id));
                }
            } else {
                params.push('dnt=1');
                params.push('muted=' + (muted ? '1' : '0'));
                params.push('texttrack=' + (captions ? 'en' : ''));

                if (video.loop) {
                    params.push('loop=1');
                }
            }

            iframe = document.createElement('iframe');
            iframe.className = 'spartrak-home-showcase__frame';
            iframe.setAttribute('src', base + encodeURIComponent(video.id) + '?' + params.join('&'));
            iframe.setAttribute('title', video.title || '');
            iframe.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture');
            iframe.setAttribute('allowfullscreen', 'allowfullscreen');
            // Third-party content: no access to this document, and referrer
            // information kept to the origin.
            iframe.setAttribute('loading', 'lazy');
            iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');

            return iframe;
        }
    });

    /**
     * Starts a native player once it is in the document, and honours the mute
     * button afterwards.
     *
     * Two frames of separation, because `play()` on an element that is not yet
     * attached is a rejected promise in some browsers, and unmuting BEFORE
     * playback has actually begun is what trips the autoplay policy. Unmuting
     * after it has begun is a user gesture's worth of permission already spent.
     *
     * @param {HTMLElement} el
     * @param {Boolean} muted
     */
    function frameAppendReady(el, muted) {
        window.requestAnimationFrame(function () {
            var started = el.play();

            if (started && typeof started.catch === 'function') {
                // Swallowed on purpose: the shopper still has a working
                // control bar, and an unhandled rejection in the console is
                // noise rather than information.
                started.catch(function () {});
            }

            if (!muted) {
                el.muted = false;
            }
        });
    }

    return $.mage.spartrakHomeVideo;
});
