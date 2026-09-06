<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\ProductVideo\Model\Video;

use Magento\Framework\Exception\LocalizedException;
use Spartrak\ProductVideo\Model\Source\SourceType;

/**
 * Turns whatever an admin typed into a source this storefront is willing to play.
 *
 * ===========================================================================
 * THIS IS THE SECURITY BOUNDARY, AND IT RUNS ONCE
 * ===========================================================================
 * Two rules, and both of them are the reason this class exists rather than a
 * regex in a template:
 *
 *   1. A PROVIDER VIDEO IS AN ID, NEVER A URL. YouTube and Vimeo sources are
 *      reduced here to a bare id matched against a strict character class, and
 *      that id is the only thing that ever reaches the frontend. The player
 *      composes `https://www.youtube-nocookie.com/embed/<id>` itself from a
 *      hardcoded prefix. So there is no path — none — by which an
 *      admin-supplied string becomes an iframe `src`. That is the same
 *      boundary js/spartrak-home-video.js draws for the homepage rail, moved
 *      to the server where it costs nothing per request.
 *
 *   2. A DIRECT URL MUST BE http(s) AND MUST LOOK LIKE A VIDEO FILE. `javascript:`,
 *      `data:` and every other scheme are refused outright, and the path has to
 *      end in .mp4 or .webm. A URL that passes goes into a <source src>, which
 *      is not a script context — but it is still merchant input rendered into a
 *      page, and "it is only an admin" is how stored XSS gets in.
 *
 * ===========================================================================
 * AND IT RUNS ONCE
 * ===========================================================================
 * Every method here is called from the SAVE path
 * (Plugin\Catalog\Gallery\SaveVideoSettings). The result is columns in
 * spartrak_product_video. Nothing in this class is on a storefront request:
 * the PDP reads a source_type and a provider_id that were settled the last
 * time someone pressed Save.
 *
 * It is a plain class with no state and no Magento dependencies, which is also
 * what makes it the right thing for a future CLI bulk import to call — the
 * validation cannot be bypassed by importing round the admin form.
 */
class SourceNormalizer
{
    /**
     * YouTube ids are 11 characters today, but the length has changed once
     * already and Google has never promised it will not again. The class is
     * pinned; the length is a generous range. Anything with a character
     * outside this set is not an id.
     */
    private const YOUTUBE_ID = '/^[A-Za-z0-9_-]{6,32}$/';

    /** Vimeo ids are purely numeric. */
    private const VIMEO_ID = '/^[0-9]{6,16}$/';

    /**
     * Recognises the forms an admin actually pastes: watch links, share links,
     * embed links, Shorts, and live URLs.
     */
    private const YOUTUBE_PATTERNS = [
        '~^https?://(?:www\.|m\.)?youtube(?:-nocookie)?\.com/watch\?(?:.*&)?v=([A-Za-z0-9_-]+)~i',
        '~^https?://(?:www\.|m\.)?youtube(?:-nocookie)?\.com/(?:embed|v|shorts|live)/([A-Za-z0-9_-]+)~i',
        '~^https?://youtu\.be/([A-Za-z0-9_-]+)~i',
    ];

    private const VIMEO_PATTERNS = [
        '~^https?://(?:www\.)?vimeo\.com/(?:channels/[^/]+/|groups/[^/]+/videos/)?([0-9]+)~i',
        '~^https?://player\.vimeo\.com/video/([0-9]+)~i',
    ];

    /** Extension => the `type` attribute the <source> element gets. */
    private const NATIVE_MIME = [
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
    ];

    /**
     * Works out what a video row is, from the URL field and the (optional)
     * uploaded file the admin dialog posted.
     *
     * @param string $url the dialog's `video_url` field
     * @param string $uploadedPath media-relative path of an uploaded file, if any
     *
     * @return array{source_type: string, provider_id: ?string, src_path: ?string, mime_type: ?string}
     * @throws LocalizedException when the input is not something we will play
     */
    public function normalize(string $url, string $uploadedPath = ''): array
    {
        $uploadedPath = trim($uploadedPath);

        // An upload wins over the URL field. The dialog keeps the URL visible
        // for reference after a file is attached, and silently preferring the
        // stale URL would play the wrong video.
        if ($uploadedPath !== '') {
            return [
                'source_type' => SourceType::FILE,
                'provider_id' => null,
                'src_path' => $uploadedPath,
                'mime_type' => $this->mimeForPath($uploadedPath),
            ];
        }

        $url = trim($url);

        if ($url === '') {
            throw new LocalizedException(
                __('A video needs either an uploaded file or a URL.')
            );
        }

        $youtubeId = $this->matchFirst(self::YOUTUBE_PATTERNS, $url);

        if ($youtubeId !== null && preg_match(self::YOUTUBE_ID, $youtubeId) === 1) {
            return [
                'source_type' => SourceType::YOUTUBE,
                'provider_id' => $youtubeId,
                'src_path' => null,
                'mime_type' => null,
            ];
        }

        $vimeoId = $this->matchFirst(self::VIMEO_PATTERNS, $url);

        if ($vimeoId !== null && preg_match(self::VIMEO_ID, $vimeoId) === 1) {
            return [
                'source_type' => SourceType::VIMEO,
                'provider_id' => $vimeoId,
                'src_path' => null,
                'mime_type' => null,
            ];
        }

        return [
            'source_type' => SourceType::URL,
            'provider_id' => null,
            'src_path' => null,
            'mime_type' => $this->mimeForDirectUrl($url),
        ];
    }

    /**
     * @param string[] $patterns
     */
    private function matchFirst(array $patterns, string $url): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * A direct URL is only accepted when it is http(s) AND its path ends in a
     * container this storefront can actually play.
     *
     * The scheme test is not decoration. Without it, `javascript:alert(1)`
     * would be stored and later rendered into a `src` attribute — and the
     * failure mode of getting that wrong is stored XSS on a product page,
     * triggered for every visitor.
     *
     * @throws LocalizedException
     */
    private function mimeForDirectUrl(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new LocalizedException(
                __('A video URL must start with http:// or https://, or be a YouTube or Vimeo link.')
            );
        }

        // The query string is stripped first: a signed CDN URL is
        // "…/clip.mp4?Expires=…&Signature=…" and its extension is still mp4.
        $path = (string) parse_url($url, PHP_URL_PATH);

        return $this->mimeForPath($path);
    }

    /**
     * @throws LocalizedException
     */
    private function mimeForPath(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (!isset(self::NATIVE_MIME[$extension])) {
            throw new LocalizedException(
                __(
                    'This storefront plays MP4 and WebM video, or YouTube and Vimeo links. '
                    . '"%1" is neither.',
                    $path
                )
            );
        }

        return self::NATIVE_MIME[$extension];
    }
}
