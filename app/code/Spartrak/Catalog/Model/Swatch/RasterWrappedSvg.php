<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Catalog\Model\Swatch;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * SHRINKS THE RASTER HIDDEN INSIDE A "VECTOR" BRAND SWATCH.
 *
 * ===========================================================================
 * WHAT THESE FILES ACTUALLY ARE — measured, not assumed
 * ===========================================================================
 * The brand marks are uploaded as .svg, and Spartrak\Catalog\Plugin\Swatches\
 * SvgVariations exists precisely because an SVG has "no pixel dimensions to
 * reduce". For a real vector file that is true. These are not real vector
 * files.
 *
 * Figma exports a shape with an IMAGE FILL as an SVG wrapper around a base64
 * PNG. Every one of this storefront's seven swatches has that shape — a 58x58
 * <svg>, one <circle> filled from a <pattern>, and a single <image> whose
 * xlink:href is a data: URI holding the entire photograph:
 *
 *   /media/attribute/swatch/e/l/ellipse_10-6.svg   233,239 B  <- 1743x600 PNG
 *   /media/attribute/swatch/e/l/ellipse_10-5.svg   102,175 B  <- 1328x676 PNG
 *   /media/attribute/swatch/e/l/ellipse_10-2.svg   102,180 B  <-  842x442 PNG
 *   /media/attribute/swatch/e/l/ellipse_10-1.svg    70,306 B  <- 2100x838 PNG
 *   /media/attribute/swatch/e/l/ellipse_10-3.svg    62,140 B  <-  760x413 PNG
 *   /media/attribute/swatch/e/l/ellipse_10-4.svg    38,759 B  <-  900x423 PNG
 *   /media/attribute/swatch/e/l/ellipse_10-7.svg    14,449 B  <-  600x600 PNG
 *                                                  ---------
 *                                                  623,248 B
 *
 * The two largest are the #1 and #6 heaviest resources on the HOMEPAGE — this
 * is not a PLP-only concern. A 2100px-wide photograph is being shipped to
 * draw a mark inside a 134.779px plate (.spartrak-home-brands__plate; 104px on
 * a phone, and a 58x58 mark in the mega-nav pane). At a 3x device pixel ratio
 * the widest that raster is ever painted is ~405 device pixels. 2100 is five
 * times oversampled, and base64 adds a further 33% on top of that.
 *
 * ===========================================================================
 * WHY THE SVG IS REWRITTEN AND NOT REPLACED
 * ===========================================================================
 * Only the payload changes. Every attribute — the <svg> width/height/viewBox,
 * the <circle>, the <pattern> transform matrix, and critically the <image>'s
 * own width and height — is left byte-for-byte alone.
 *
 * That is what makes this geometrically safe rather than merely careful. An
 * SVG <image>'s width/height are USER-SPACE units, not the raster's pixel
 * count: the decoded bitmap is resampled into that box by the renderer. The
 * pattern transform is expressed against those same user-space units. So
 * swapping a 2100x838 bitmap for a 512x204 one inside an <image width="2100"
 * height="838"> changes precisely nothing about where anything lands — no
 * matrix to recompute, and nothing to get subtly wrong. preserveAspectRatio
 * ="none" on these exports means the raster is stretched to fill that box
 * exactly, so even the rounding in the new dimensions is absorbed.
 *
 * A file with no embedded raster (a genuine vector mark) matches nothing and
 * is served untouched, which is the correct outcome for it.
 *
 * ===========================================================================
 * LOSSLESS, AND PROVEN SO RATHER THAN ASSERTED
 * ===========================================================================
 * CLAUDE.md section 3 makes pixel accuracy non-negotiable, and section 4 asks,
 * where accuracy and weight appear to conflict, for the implementation that
 * preserves both. Lossy WebP at q82 saved more (538,829 B) and was rejected:
 * it changes pixels in a brand mark.
 *
 * LOSSLESS WebP was measured against a decoded round-trip of all seven files,
 * pixel by pixel:
 *
 *   visible pixels differing   0        (every file)
 *   alpha channel differing    0        (every file)
 *   max RGB delta              0        (every file)
 *
 * The only bytes that change are the RGB values carried underneath FULLY
 * TRANSPARENT pixels, which no renderer paints. Encoding is therefore lossless
 * in the only sense that matters, and the result is:
 *
 *   original                623,248 B
 *   PNG re-encode           217,448 B   -405,800 B  (65.1%)
 *   WebP lossless           117,975 B   -505,273 B  (81.1%)
 *
 * WebP inside an SVG <image> is not exotic here: Lighthouse's own Baseline
 * audit on this storefront reports `webp` as Widely Available since 2020-09-16,
 * and the theme already serves spartrak-logo.webp as a plain <img>, so WebP
 * decoding is already a hard requirement of rendering this site at all.
 *
 * BOTH encodings are still produced and the SMALLEST OF THE THREE — original
 * included — wins. That last clause is not decoration: re-encoding
 * ellipse_10-7 as PNG would have GROWN it from 14,449 to 19,885 bytes. A
 * transformation that can make a file bigger and does not check is a
 * regression waiting for a different input.
 *
 * ===========================================================================
 * FAILURE IS ALWAYS "SERVE THE ORIGINAL"
 * ===========================================================================
 * Every bail-out below returns null, and the caller then serves the untouched
 * upload. A heavy swatch is a performance defect; a missing brand logo is a
 * broken page, and this must never turn the first into the second. No host
 * capability is assumed — a PHP build without WebP simply keeps the PNG path,
 * and one without GD keeps the original.
 */
class RasterWrappedSvg
{
    /**
     * Longest edge of the rewritten raster, in pixels.
     *
     * The largest box any of these is drawn in is the homepage rail's
     * 134.779px plate, which at a 3x device pixel ratio is ~405 device pixels.
     * 512 clears that with room for a 3.8x ratio, and still cuts a 2100px
     * source by a factor of four linearly.
     *
     * Deliberately NOT tuned per render site: one cached artefact serves the
     * mega-nav's 58px mark, the mobile drawer and the homepage plate, and the
     * alternative is three files where the largest is already this small.
     */
    private const MAX_EDGE = 512;

    /** Where derivatives live, under Magento's own attribute/swatch root. */
    private const CACHE_SEGMENT = 'spartrak_optimised';

    /**
     * Refuse to even read an SVG larger than this. The file is loaded into a
     * PHP string and then regex-scanned; an unbounded read is a memory
     * exhaustion vector on an admin-uploadable path.
     */
    private const MAX_SOURCE_BYTES = 12582912;

    /**
     * Refuse to decode a raster larger than this. A truecolor GD image costs
     * ~4 bytes per pixel, so this caps one decode at roughly 160 MB.
     */
    private const MAX_SOURCE_PIXELS = 40000000;

    /** PHP maps quality 101 (IMG_WEBP_LOSSLESS) to WebP's lossless mode. */
    private const WEBP_LOSSLESS = 101;

    private const PNG_COMPRESSION = 9;

    /**
     * The single data: URI these exports carry.
     *
     * Anchored to the formats GD can actually decode, so a payload this class
     * could not rewrite is never matched in the first place. The match is used
     * to require EXACTLY ONE occurrence — a multi-image SVG is left alone
     * rather than half-rewritten.
     */
    private const PAYLOAD_PATTERN = '~data:image/(?:png|jpeg|jpg|gif|webp);base64,([A-Za-z0-9+/=]+)~i';

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The media-relative path of an optimised copy, or null to serve the
     * original.
     *
     * @param string $mediaRelativePath e.g. attribute/swatch/e/l/ellipse_10-1.svg
     */
    public function optimise(string $mediaRelativePath): ?string
    {
        $source = $this->normalise($mediaRelativePath);

        if ($source === '' || !function_exists('imagecreatefromstring')) {
            return null;
        }

        $target = $this->targetPath($source);

        try {
            $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);

            if (!$media->isExist($source) || !$media->isFile($source)) {
                return null;
            }

            // mtime cache. A re-upload overwrites the source and bumps its
            // mtime, so the derivative rebuilds on the next request without
            // anyone having to remember to purge anything.
            if ($media->isExist($target)
                && $media->stat($target)['mtime'] >= $media->stat($source)['mtime']
            ) {
                return $target;
            }

            if ($media->stat($source)['size'] > self::MAX_SOURCE_BYTES) {
                return null;
            }

            $rewritten = $this->rewrite($media->readFile($source));

            if ($rewritten === null) {
                return null;
            }

            $media->writeFile($target, $rewritten);

            return $target;
        } catch (\Throwable $throwable) {
            // Includes the "pub/media is read-only during a deploy" case.
            $this->logger->warning(
                'Spartrak_Catalog: could not optimise swatch ' . $source
                . ', serving the original: ' . $throwable->getMessage()
            );

            return null;
        }
    }

    /**
     * The rewritten SVG, or null when the original is already the best answer.
     */
    private function rewrite(string $svg): ?string
    {
        // preg_match_all rather than preg_match: the count is the point. Two
        // payloads means an export this class does not understand, and a
        // half-rewritten SVG is worse than an unoptimised one.
        if (preg_match_all(self::PAYLOAD_PATTERN, $svg, $matches, PREG_SET_ORDER) !== 1) {
            return null;
        }

        $needle = $matches[0][0];
        $raw = base64_decode($matches[0][1], true);

        if ($raw === false || $raw === '') {
            return null;
        }

        $info = @getimagesizefromstring($raw);

        if (!$info || (int) $info[0] < 1 || (int) $info[1] < 1) {
            return null;
        }

        $width = (int) $info[0];
        $height = (int) $info[1];

        if ($width * $height > self::MAX_SOURCE_PIXELS) {
            return null;
        }

        $source = @imagecreatefromstring($raw);

        if (!$source instanceof \GdImage) {
            return null;
        }

        try {
            $resampled = $this->resample($source, $width, $height);
        } finally {
            imagedestroy($source);
        }

        if ($resampled === null) {
            return null;
        }

        try {
            $candidates = $this->encode($resampled);
        } finally {
            imagedestroy($resampled);
        }

        $best = null;
        $bestSize = strlen($svg);

        foreach ($candidates as $mime => $bytes) {
            $candidate = str_replace(
                $needle,
                'data:' . $mime . ';base64,' . base64_encode($bytes),
                $svg
            );

            if (strlen($candidate) < $bestSize) {
                $best = $candidate;
                $bestSize = strlen($candidate);
            }
        }

        // null when nothing beat the upload — the size guard that stops a
        // re-encode inflating an already-small file.
        return $best;
    }

    /**
     * Downscale to MAX_EDGE on the longest edge, preserving the raster's own
     * aspect ratio and its alpha channel.
     */
    private function resample(\GdImage $source, int $width, int $height): ?\GdImage
    {
        $scale = min(1.0, self::MAX_EDGE / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = @imagecreatetruecolor($targetWidth, $targetHeight);

        if (!$target instanceof \GdImage) {
            return null;
        }

        // These three calls in this order are what keeps a transparent
        // background transparent: blending OFF so the fill replaces rather
        // than composites, an explicit fully-transparent fill, and savealpha
        // so the channel survives encoding. Omitting any one of them turns a
        // logo's transparent surround black.
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height
        );

        return $target;
    }

    /**
     * Every encoding this host can produce, keyed by MIME type.
     *
     * @return array<string, string>
     */
    private function encode(\GdImage $image): array
    {
        $out = [];

        if (function_exists('imagewebp')) {
            $bytes = $this->capture(
                static fn (): bool => imagewebp($image, null, self::WEBP_LOSSLESS)
            );

            if ($bytes !== null) {
                $out['image/webp'] = $bytes;
            }
        }

        $bytes = $this->capture(
            static fn (): bool => imagepng($image, null, self::PNG_COMPRESSION)
        );

        if ($bytes !== null) {
            $out['image/png'] = $bytes;
        }

        return $out;
    }

    /**
     * Run a GD writer that streams to stdout and return what it wrote.
     *
     * The buffer is always closed, including when the encoder throws, so a
     * failure here cannot leave output buffering on and corrupt the response
     * the storefront is midway through rendering.
     */
    private function capture(callable $writer): ?string
    {
        ob_start();

        try {
            $ok = (bool) $writer();
        } catch (\Throwable $throwable) {
            ob_end_clean();

            return null;
        }

        $bytes = ob_get_clean();

        return ($ok && is_string($bytes) && $bytes !== '') ? $bytes : null;
    }

    /**
     * attribute/swatch/e/l/x.svg -> attribute/swatch/spartrak_optimised/e/l/x.svg
     *
     * The segment is inserted after Magento's own swatch root rather than
     * appended at the media root, so derivatives sit inside the directory an
     * admin already recognises and deleting it is a safe cache purge.
     */
    private function targetPath(string $source): string
    {
        $root = 'attribute/swatch/';

        if (str_starts_with($source, $root)) {
            return $root . self::CACHE_SEGMENT . '/' . substr($source, strlen($root));
        }

        return self::CACHE_SEGMENT . '/' . $source;
    }

    /**
     * Reject anything that is not a plain relative path to an .svg.
     *
     * Traversal is refused outright rather than stripped: the argument
     * originates from an admin-supplied attribute value, and a normaliser that
     * silently "fixes" ../../ is one bug away from writing outside pub/media.
     */
    private function normalise(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');

        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            return '';
        }

        return strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'svg' ? $path : '';
    }
}
