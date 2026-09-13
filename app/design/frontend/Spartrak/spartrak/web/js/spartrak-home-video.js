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
 * ONE PARSER, SHARED WITH THE PRODUCT PAGE
 * ===========================================================================
 * The card carries `data-video-url` — `video_url` exactly as Magento's media
 * gallery stored it — and this file does not interpret it. Whose video it is
 * and what its id is are answered by js/spartrak-video-source, which is also
 * what the PDP gallery mixin asks. Two surfaces play the same video, so
 * neither of them owns the definition of one (CLAUDE.md section 9).
 *
 * The security boundary lives in that module: a URL is decomposed, the id is
 * matched against a strict character class, and the embed address is composed
 * from a hardcoded prefix — so no merchant-supplied string reaches an iframe
 * `src`. A URL it does not recognise yields no player, and the poster simply
 * stays.
 *
 * ===========================================================================
 * MUTE AND CAPTIONS ARE URL PARAMETERS
 * ===========================================================================
 * Magento's media gallery stores product video as a provider URL, so the
 * player is always an iframe and both controls are parameters on it — which
 * means changing one after playback has begun rebuilds the frame and restarts
 * the video. Driving them without a restart needs that provider's player API
 * loaded, which is exactly the third-party weight this file exists to keep off
 * the homepage. The limit is recorded here rather than hidden.
 */
define([
    'jquery',
    'js/spartrak-video-source',
    'jquery-ui-modules/widget'
], function ($, videoSource) {
    'use strict';

    $.widget('mage.spartrakHomeVideo', {
        options: {},

        _create: function () {
            this._on({
                'click [data-video-play]': this._onPlay,
                'click [data-video-mute]': this._onToggle,
                'click [data-video-captions]': this._onToggle
            });
        },

        /**
         * The media frame a clicked control belongs to.
         *
         * @private
         */
        _frameOf: function (event) {
            return $(event.currentTarget).closest('[data-video-url]');
        },

        /**
         * The frame's provider and id, resolved once and cached on the element.
         *
         * @param {jQuery} frame
         * @return {{type: String, id: String}|null}
         * @private
         */
        _sourceOf: function (frame) {
            var source = frame.data('spartrakVideoSource');

            if (source === undefined) {
                source = videoSource.parse(frame.attr('data-video-url'));
                frame.data('spartrakVideoSource', source);
            }

            return source;
        },

        /**
         * What the shopper has asked the player for, read off the controls
         * rather than tracked in a second copy of the state.
         *
         * @param {jQuery} frame
         * @return {{muted: Boolean, captions: Boolean, loop: Boolean}}
         * @private
         */
        _stateOf: function (frame) {
            return {
                muted: frame.find('[data-video-mute]').attr('aria-pressed') !== 'false',
                captions: frame.find('[data-video-captions]').attr('aria-pressed') === 'true',
                // Magento's media gallery has no loop field for an external
                // video, so there is nothing to honour. Passed explicitly so
                // the shared parameter builder is never handed undefined.
                loop: false
            };
        },

        _onPlay: function (event) {
            event.preventDefault();

            var frame = this._frameOf(event);

            if (frame.length) {
                this._mount(frame);
            }
        },

        /**
         * Mute and captions are the same interaction: flip the button, and
         * rebuild the player only if one already exists. Before playback the
         * new state is simply read off the buttons when play is finally
         * pressed, so nothing is loaded to change a setting.
         *
         * @private
         */
        _onToggle: function (event) {
            event.preventDefault();

            var button = $(event.currentTarget),
                frame = this._frameOf(event);

            button.attr('aria-pressed', button.attr('aria-pressed') === 'true' ? 'false' : 'true');

            if (frame.length && frame.find('iframe').length) {
                this._mount(frame);
            }
        },

        /**
         * Builds (or rebuilds) the player inside a frame. This is the FIRST
         * moment anything is requested from YouTube or Vimeo — before this
         * click the shopper's browser has spoken to neither.
         *
         * @private
         */
        _mount: function (frame) {
            var source = this._sourceOf(frame),
                iframe;

            if (!source) {
                return;
            }

            iframe = document.createElement('iframe');
            iframe.className = 'spartrak-home-showcase__frame';
            iframe.setAttribute('src', videoSource.embed(source, videoSource.params(source, this._stateOf(frame))));
            iframe.setAttribute('title', frame.attr('data-video-title') || '');
            iframe.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture');
            iframe.setAttribute('allowfullscreen', 'allowfullscreen');
            // Third-party content: no reach into this document, and referrer
            // information kept to the origin.
            iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');

            frame.find('iframe').remove();
            frame.append(iframe);
            frame.attr('data-video-playing', '');
        }
    });

    return $.mage.spartrakHomeVideo;
});
