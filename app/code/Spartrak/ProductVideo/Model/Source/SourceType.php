<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\ProductVideo\Model\Source;

/**
 * The four kinds of video source.
 *
 * ===========================================================================
 * DERIVED, NEVER CHOSEN
 * ===========================================================================
 * There is no dropdown for this. Model\Video\SourceNormalizer works the type
 * out from what the admin actually supplied — a pasted link or an uploaded
 * file — and stores the answer. Asking a merchant to classify their own URL
 * would be asking them to get it right, and offering them a way to get it
 * wrong: a video labelled "YouTube" whose URL is an MP4 is a broken player,
 * and there is no reading of that mistake that helps anybody.
 *
 * ===========================================================================
 * WHY THE ANSWER IS STORED AT ALL
 * ===========================================================================
 * So the storefront never re-derives it. No regex runs and no provider is
 * sniffed on a page a shopper is waiting for; the work happened once, when
 * someone pressed Save.
 *
 * It is also the thing the player switches on. `file` and `url` mount a native
 * <video>; `youtube` and `vimeo` mount an iframe built from a validated
 * provider id. Those are genuinely different players, and a template that had
 * to ask "does this URL look like YouTube?" to choose between them would be
 * asking a question the database already knows.
 */
final class SourceType
{
    /** A file uploaded through the product form, living under pub/media. */
    public const FILE = 'file';

    /** A direct MP4/WebM URL somewhere else — a CDN, an object store. */
    public const URL = 'url';

    public const YOUTUBE = 'youtube';

    public const VIMEO = 'vimeo';

    /**
     * The two that play through a native <video> element rather than an
     * embedded third-party iframe.
     *
     * Chapters are offered for these only. Seeking a YouTube or Vimeo iframe
     * to a timestamp means loading that provider's player API and driving it —
     * exactly the third-party weight this module exists to keep off the page.
     * Their own players already ship chapter UI of their own.
     */
    public const NATIVE_TYPES = [self::FILE, self::URL];

    public static function isNative(string $sourceType): bool
    {
        return in_array($sourceType, self::NATIVE_TYPES, true);
    }
}
