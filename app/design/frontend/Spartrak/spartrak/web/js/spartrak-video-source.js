/**
 * Spartrak — the ONE place a product video's URL becomes a provider id.
 *
 * ===========================================================================
 * WHY THIS FILE EXISTS
 * ===========================================================================
 * Two storefront surfaces play the same product video: the PDP gallery
 * (js/spartrak-pdp-video-mixin) and the homepage showcase rail
 * (js/spartrak-home-video). Both need the same three answers — is this a
 * video, whose is it, and what is its id — and CLAUDE.md section 9 forbids
 * duplicating business logic across components. So neither of them parses a
 * URL; both ask this module.
 *
 * It is a dependency of both, so RequireJS resolves it ONCE and it is bundled
 * once. Sharing it costs no request.
 *
 * ===========================================================================
 * THE SECURITY BOUNDARY IS HERE, AND IT IS AN ALLOW-LIST
 * ===========================================================================
 * `video_url` is merchant input: an admin types it into Magento's own Add
 * Video dialog. It must never reach an iframe `src` as-is.
 *
 * So nothing here passes a URL through. A URL is DECOMPOSED into a provider
 * and an id, the id is matched against a strict character class, and `embed()`
 * then COMPOSES a fresh address from a hardcoded prefix. A string that is not
 * a recognised provider URL, or whose id is not the exact shape that provider
 * uses, returns null — and the caller renders no play button at all rather
 * than a control wired to something unvalidated.
 *
 * That is stricter than Magento's own storefront parser
 * (Magento_ProductVideo/js/fotorama-add-video-events, parseURL), which
 * extracts an id and never checks its shape. The host and path tests below
 * deliberately mirror that function so a URL an admin saved through Magento's
 * dialog is recognised the same way here; the id validation is the addition.
 *
 * ===========================================================================
 * PRIVACY-PRESERVING EMBEDS
 * ===========================================================================
 * youtube-nocookie.com and Vimeo's `dnt=1` are the no-tracking variants of
 * each provider's embed: no cookie is written and no view is attributed until
 * the shopper presses play. Both are the only prefixes in this file, so no
 * caller can opt out of them by accident.
 */
define([], function () {
    'use strict';

    var PREFIX = {
            youtube: 'https://www.youtube-nocookie.com/embed/',
            vimeo: 'https://player.vimeo.com/video/'
        },

        /**
         * A provider's id is a fixed shape, and anything else is refused.
         *
         * YouTube ids are exactly 11 URL-safe base64 characters; Vimeo ids are
         * decimal. Both classes exclude every character that could steer an
         * embed address — no dot, slash, colon, question mark or ampersand can
         * survive them.
         */
        ID = {
            youtube: /^[\w-]{11}$/,
            vimeo: /^\d+$/
        };

    /**
     * Splits a URL with the browser's own parser rather than a regular
     * expression, which is both shorter and correct about the cases a hand
     * written pattern gets wrong (default ports, protocol-relative URLs,
     * uppercase hosts, trailing fragments).
     *
     * @param {String} url
     * @return {HTMLAnchorElement}
     */
    function split(url) {
        var a = document.createElement('a');

        a.href = url;

        return a;
    }

    /**
     * The first path segment, with any `/embed/` or `/v/` or `/shorts/`
     * wrapper stripped — which is where every non-query provider URL keeps its
     * id.
     *
     * @param {String} pathname
     * @return {String}
     */
    function firstSegment(pathname) {
        return pathname
            .replace(/^\/(embed\/|v\/|shorts\/)?/, '')
            .replace(/\/.*/, '');
    }

    return {
        /**
         * A video URL's provider and id, or null if it is neither.
         *
         * @param {String} url - `video_url` from a Magento media gallery entry.
         * @return {{type: String, id: String}|null}
         */
        parse: function (url) {
            var parts,
                type,
                id;

            if (typeof url !== 'string' || url === '') {
                return null;
            }

            parts = split(url);

            // An `http(s)` scheme is required before the host is even looked
            // at. `javascript:` and `data:` URLs parse to an empty host and
            // would fall through to null anyway — this makes the refusal
            // explicit instead of incidental.
            if (parts.protocol !== 'http:' && parts.protocol !== 'https:') {
                return null;
            }

            if (/(^|\.)youtube\.com$/.test(parts.hostname) && parts.search) {
                // The watch URL: the id is a query parameter, and `split`
                // already normalised the rest of the query away from it.
                type = 'youtube';
                id = (parts.search.split('v=')[1] || '').split('&')[0];
            } else if (/(^|\.)(youtube\.com|youtu\.be|youtube-nocookie\.com)$/.test(parts.hostname)) {
                type = 'youtube';
                id = firstSegment(parts.pathname);
            } else if (/(^|\.)vimeo\.com$/.test(parts.hostname)) {
                type = 'vimeo';
                // Vimeo keeps the id in the LAST segment, not the first:
                // /video/123, /channels/staffpicks/123 and /123 all end in it.
                id = parts.pathname.split('/').filter(Boolean).pop() || '';
            } else {
                return null;
            }

            return ID[type].test(id) ? { type: type, id: id } : null;
        },

        /**
         * A player address, composed from a hardcoded prefix and a validated
         * id. There is no code path by which a merchant-supplied string
         * becomes any other part of this URL.
         *
         * @param {{type: String, id: String}} source - from parse() above.
         * @param {Object} params - provider player parameters.
         * @return {String}
         */
        embed: function (source, params) {
            var query = Object.keys(params || {}).map(function (key) {
                return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
            });

            return PREFIX[source.type] + encodeURIComponent(source.id)
                + (query.length ? '?' + query.join('&') : '');
        },

        /**
         * The player parameters for a provider, given what the shopper asked
         * for. Kept here rather than in each caller because the two surfaces
         * must not disagree about what "muted" or "looping" means to YouTube.
         *
         * `autoplay` is unconditional: every caller reaches this only after a
         * shopper has pressed a play button, so starting playback IS the
         * request. Both providers also refuse to autoplay audible media, which
         * is why an unmuted start is honoured only after playback begins —
         * see each caller.
         *
         * @param {{type: String, id: String}} source
         * @param {Object} state - {muted: Boolean, captions: Boolean, loop: Boolean}
         * @return {Object}
         */
        params: function (source, state) {
            var params = {
                autoplay: 1,
                playsinline: 1
            };

            if (source.type === 'youtube') {
                params.rel = 0;
                params.modestbranding = 1;
                params.mute = state.muted ? 1 : 0;
                params.cc_load_policy = state.captions ? 1 : 0;

                if (state.loop) {
                    // YouTube loops a PLAYLIST, not a video, so looping one
                    // video means naming it as its own playlist. Their quirk,
                    // recorded here so it does not read as ours.
                    params.loop = 1;
                    params.playlist = source.id;
                }
            } else {
                params.dnt = 1;
                params.muted = state.muted ? 1 : 0;

                if (state.captions) {
                    params.texttrack = 'en';
                }

                if (state.loop) {
                    params.loop = 1;
                }
            }

            return params;
        }
    };
});
