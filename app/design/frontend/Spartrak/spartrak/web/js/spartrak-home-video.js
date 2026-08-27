/**
 * Spartrak — product video facade for "شاهد المنتج، وأحكم بنفسك".
 *
 * ===========================================================================
 * WHY A FACADE
 * ===========================================================================
 * Magento stores product video as an `external-video` media-gallery entry: a
 * YouTube or Vimeo URL. Embedding three of those iframes on the homepage
 * would put roughly a megabyte of third-party JavaScript in front of the
 * page's own largest paint, on every visit, for a video most shoppers will
 * never press play on. CLAUDE.md section 4 names that outright — "third-party
 * scripts/tags loading before it".
 *
 * So the card ships the product's real image plus a real play button, and the
 * iframe is constructed on the FIRST CLICK. Nothing is requested from a third
 * party until a shopper asks for it.
 *
 * This is a facade, not a mock: the button is wired to a genuine video URL
 * that came out of Magento. A product with no video renders no controls at
 * all rather than a button that does nothing.
 *
 * ===========================================================================
 * MUTE AND CAPTIONS ARE REAL, WITHIN WHAT AN EMBED URL CAN EXPRESS
 * ===========================================================================
 * Both providers accept the state as URL parameters. Before playback the
 * toggles set what the video will START as; after playback has begun,
 * changing one rebuilds the iframe src so the change actually takes effect.
 * They are not decoration — but they are also not a full player API, and that
 * limit is recorded here rather than hidden.
 */
define(['jquery', 'jquery-ui-modules/widget'], function ($) {
    'use strict';

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
         */
        _frameOf: function (event) {
            return $(event.currentTarget).closest('[data-video-url]');
        },

        _onPlay: function (event) {
            event.preventDefault();

            var frame = this._frameOf(event);

            if (!frame.length) {
                return;
            }

            this._mount(frame);
        },

        _onMute: function (event) {
            event.preventDefault();
            this._toggle($(event.currentTarget), 'muted');
        },

        _onCaptions: function (event) {
            event.preventDefault();
            this._toggle($(event.currentTarget), 'captions');
        },

        _toggle: function (button, flag) {
            var frame = button.closest('[data-video-url]');
            var pressed = button.attr('aria-pressed') === 'true';

            button.attr('aria-pressed', pressed ? 'false' : 'true');

            // Only rebuild if the video is already playing; before that, the
            // new state is simply read off the buttons when play is pressed.
            if (frame.find('iframe').length) {
                this._mount(frame);
            }
        },

        /**
         * Builds (or rebuilds) the provider iframe inside a frame.
         */
        _mount: function (frame) {
            var url = this._embedUrl(
                frame.attr('data-video-url'),
                frame.find('[data-video-mute]').attr('aria-pressed') !== 'false',
                frame.find('[data-video-captions]').attr('aria-pressed') === 'true'
            );

            if (!url) {
                return;
            }

            frame.find('iframe').remove();

            var iframe = document.createElement('iframe');

            iframe.className = 'spartrak-home-showcase__frame';
            iframe.setAttribute('src', url);
            iframe.setAttribute('title', frame.find('.spartrak-home-showcase__handle').text() || '');
            iframe.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture');
            iframe.setAttribute('allowfullscreen', 'allowfullscreen');
            // The embed is third-party content: it gets no access to this
            // document, and referrer information is kept to the origin.
            iframe.setAttribute('loading', 'lazy');
            iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');

            frame.append(iframe);
            frame.attr('data-video-playing', '');
        },

        /**
         * Provider-specific embed URL, or '' for a URL we do not recognise.
         *
         * Unknown providers are refused rather than guessed at: injecting an
         * arbitrary admin-supplied URL into an iframe src is the kind of thing
         * that turns a content field into an attack surface.
         */
        _embedUrl: function (raw, muted, captions) {
            if (!raw) {
                return '';
            }

            var youtube = raw.match(
                /(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{6,})/
            );

            if (youtube) {
                return 'https://www.youtube-nocookie.com/embed/' + youtube[1]
                    + '?autoplay=1&rel=0&playsinline=1'
                    + '&mute=' + (muted ? '1' : '0')
                    + '&cc_load_policy=' + (captions ? '1' : '0');
            }

            var vimeo = raw.match(/vimeo\.com\/(?:video\/)?(\d+)/);

            if (vimeo) {
                return 'https://player.vimeo.com/video/' + vimeo[1]
                    + '?autoplay=1&dnt=1'
                    + '&muted=' + (muted ? '1' : '0')
                    + '&texttrack=' + (captions ? 'en' : '');
            }

            return '';
        }
    });

    return $.mage.spartrakHomeVideo;
});
