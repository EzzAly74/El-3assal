<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Model\Image;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * DERIVATIVES OF AN ADMIN-UPLOADED MEDIA FILE, AT THE SIZE IT IS ACTUALLY DRAWN.
 *
 * ===========================================================================
 * THE DEFECT THIS EXISTS FOR — measured on the live homepage, not assumed
 * ===========================================================================
 * Magento resizes PRODUCT images and nothing else. `Category::getImageUrl()`
 * returns the raw upload — there is no category equivalent of
 * `Magento\Catalog\Helper\Image` anywhere in 2.4.8 — so whatever a
 * merchandiser drags into Catalog > Categories > Content is what every
 * shopper downloads, at full size, in whatever format it was saved in.
 *
 * On the Arabic homepage that meant:
 *
 *   /media/catalog/category/ae0845b1…_1.png   782,520 bytes, 1249 x 848
 *   /media/catalog/category/ae0845b1…_2.png   782,520 bytes, 1249 x 848
 *   /media/catalog/category/ae0845b1…_3.png   782,520 bytes, 1249 x 848
 *                                             ---------------------------
 *                                             2,347,560 bytes
 *
 * drawn into boxes of 788x535 (the reveal stage), 308x206 (the rail card) and
 * 604 wide (the promo split) — six <img> elements over three files, half of
 * the page's entire 4.6 MB weight. Lighthouse put 2,532 KiB and ~4,100 ms of
 * LCP saving against exactly these. CLAUDE.md section 11 is explicit: never
 * load an unnecessarily large image for the rendered size.
 *
 * ===========================================================================
 * WHY GD DIRECTLY AND NOT Magento\Framework\Image\AdapterFactory
 * ===========================================================================
 * The framework adapter is the Magento-native way to resize, and it was the
 * first choice. It cannot do the half of this that matters most:
 * `Magento\Framework\Image\Adapter\Gd2::$_callbacks` maps GIF, JPEG, PNG, XBM
 * and WBMP and *no WebP at all* (verified in 2.4.8), and `save()` picks its
 * output function from that map — so an adapter-based resizer can only ever
 * write the format it read.
 *
 * That matters because format, not dimensions, is the bigger win here: these
 * are photographs saved as PNG. Re-encoding one to WebP is what turns 782 KB
 * into tens of KB; resizing a PNG to 788x535 still leaves a PNG.
 *
 * So this class uses GD through PHP directly. It is still a service — DI,
 * Magento\Framework\Filesystem for every path, StoreManager for every URL, no
 * SQL, no superglobals — and it degrades to the source format wherever
 * `imagewebp()` is unavailable, so a host without WebP support loses the
 * format win and keeps the sizing win rather than losing the images.
 *
 * ===========================================================================
 * WHEN IT RUNS
 * ===========================================================================
 * At render time, and therefore once per full-page-cache generation rather
 * than once per visitor — the same trade Model\Image\Storage::getDimensions()
 * already makes for banner dimensions, for the same reason. A derivative is
 * written once and reused until the source file changes (mtime comparison),
 * so the steady state is one stat() per image per cache build.
 *
 * Every failure path returns null and is logged. A null tells the caller to
 * fall back to the original URL: a heavy image is a performance defect, a
 * missing image is a broken page, and this must never turn the first into the
 * second.
 */
class Resizer
{
    /** Where derivatives live, relative to pub/media. */
    public const CACHE_PATH = 'spartrak/resized';

    /**
     * WebP quality. 82 is the knee of the curve for photographic content —
     * visually indistinguishable from the source at 1x and 2x, roughly a
     * tenth of the bytes of the equivalent PNG.
     */
    private const WEBP_QUALITY = 82;

    /** PNG compression level, used only when WebP is unavailable. */
    private const PNG_COMPRESSION = 9;

    /** JPEG quality, used only when WebP is unavailable. */
    private const JPEG_QUALITY = 82;

    /**
     * Refuse to decode anything larger than this. A truecolor GD image costs
     * 4 bytes per pixel, so 40 MP is ~160 MB of memory — past that the right
     * answer is to leave the original alone and log it, not to exhaust the
     * PHP process during a page render.
     */
    private const MAX_SOURCE_PIXELS = 40000000;

    /**
     * Stands in for "no limit on this axis" in responsive(), where the caller
     * constrains width and lets the aspect ratio decide the height. Larger
     * than any upload this storefront will see, so it never binds.
     */
    private const UNBOUNDED = 100000;

    /** @var array<string, array{url: string, width: int, height: int}|null> */
    private array $memo = [];

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * One derivative, scaled to fit inside the box and never enlarged.
     *
     * @param string $mediaRelativePath e.g. "catalog/category/foo.png"
     * @return array{url: string, width: int, height: int}|null
     */
    public function variant(string $mediaRelativePath, int $maxWidth, int $maxHeight): ?array
    {
        $source = $this->normalise($mediaRelativePath);

        if ($source === '' || $maxWidth < 1 || $maxHeight < 1) {
            return null;
        }

        $key = $source . '@' . $maxWidth . 'x' . $maxHeight;

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        return $this->memo[$key] = $this->build($source, $maxWidth, $maxHeight);
    }

    /**
     * A `w`-descriptor candidate set for one image.
     *
     * `w` descriptors and not `1x`/`2x`, deliberately. An `x` descriptor
     * assumes the box is the same CSS size everywhere, and none of these
     * boxes are: the tiles' reveal stage is 788x535 on desktop and 100vw x
     * 220 on a phone. With `x` descriptors a 2.6-DPR phone would pull the
     * desktop-retina file for a box a third of the width — which is how a
     * "responsive" image ends up heavier on mobile than on desktop. With `w`
     * descriptors plus the caller's `sizes`, the browser resolves the real
     * layout width against the real device ratio and picks correctly.
     *
     * Candidates that collapse onto the same actual width (because the
     * source is smaller than the box asked for — build() never enlarges) are
     * de-duplicated, so a small upload yields one candidate rather than three
     * identical ones.
     *
     * @param int[] $widths candidate widths, in CSS pixels
     * @param int $defaultWidth the width `src` should point at — the desktop
     *                          layout size, so a browser that ignores srcset
     *                          gets the right file rather than the largest
     * @return array{url: string, srcset: string, width: int, height: int}|null
     */
    public function responsive(string $mediaRelativePath, array $widths, int $defaultWidth): ?array
    {
        $default = $this->variant($mediaRelativePath, $defaultWidth, self::UNBOUNDED);

        if ($default === null) {
            return null;
        }

        $candidates = [];

        foreach ($widths as $width) {
            $candidate = $this->variant($mediaRelativePath, (int) $width, self::UNBOUNDED);

            if ($candidate !== null) {
                $candidates[$candidate['width']] = $candidate;
            }
        }

        $candidates[$default['width']] = $default;
        ksort($candidates);

        $srcset = [];

        foreach ($candidates as $candidate) {
            $srcset[] = $candidate['url'] . ' ' . $candidate['width'] . 'w';
        }

        return [
            'url' => $default['url'],
            'srcset' => implode(', ', $srcset),
            'width' => $default['width'],
            'height' => $default['height'],
        ];
    }

    /**
     * The media-relative path behind a category's `image` attribute value.
     *
     * Mirrors `Magento\Catalog\Model\Category::getImageUrl()`, which prefixes
     * exactly this path onto the raw attribute value. Kept here so callers
     * hand this class a path rather than un-picking a URL they just built.
     */
    public function categoryImagePath(string $imageAttributeValue): string
    {
        $value = trim($imageAttributeValue);

        return $value === '' ? '' : 'catalog/category/' . ltrim($value, '/');
    }

    /**
     * @return array{url: string, width: int, height: int}|null
     */
    private function build(string $source, int $maxWidth, int $maxHeight): ?array
    {
        try {
            $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);

            if (!$media->isExist($source) || !$media->isReadable($source)) {
                return null;
            }

            $sourceAbs = $media->getAbsolutePath($source);
            $info = @getimagesize($sourceAbs);

            if (!is_array($info) || (int) $info[0] < 1 || (int) $info[1] < 1) {
                // No raster header. SVG lands here, legitimately, and an SVG
                // is already the right size at every scale — the caller keeps
                // the original and nothing is lost.
                return null;
            }

            $sourceWidth = (int) $info[0];
            $sourceHeight = (int) $info[1];
            $type = (int) ($info[2] ?? 0);
            $decoders = $this->decoders();

            if (!isset($decoders[$type])) {
                return null;
            }

            if ($sourceWidth * $sourceHeight > self::MAX_SOURCE_PIXELS) {
                $this->logger->warning(
                    'Spartrak_Homepage: "' . $source . '" is ' . $sourceWidth . 'x' . $sourceHeight
                    . ', too large to resize safely, so it is served at full size.'
                );

                return null;
            }

            // Fit inside the box, preserve the aspect ratio, never enlarge: a
            // derivative wider than its source is more bytes for no more
            // detail.
            $scale = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight, 1.0);
            $width = max(1, (int) round($sourceWidth * $scale));
            $height = max(1, (int) round($sourceHeight * $scale));

            $cacheRelative = self::CACHE_PATH . '/' . $width . 'x' . $height . '/'
                . $source . '.' . $this->outputExtension($type);
            $cacheAbs = $media->getAbsolutePath($cacheRelative);

            $fresh = $media->isExist($cacheRelative) && @filemtime($cacheAbs) >= @filemtime($sourceAbs);

            if (!$fresh) {
                $media->create(dirname($cacheRelative));

                if (!$this->write($sourceAbs, $cacheAbs, $decoders[$type], $type, $sourceWidth, $sourceHeight, $width, $height)) {
                    return null;
                }
            }

            return [
                'url' => $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) . $cacheRelative,
                'width' => $width,
                'height' => $height,
            ];
        } catch (\Throwable $throwable) {
            $this->logger->warning(
                'Spartrak_Homepage: could not resize "' . $source . '": ' . $throwable->getMessage()
            );

            return null;
        }
    }

    /**
     * Decode, resample, encode, publish — atomically.
     *
     * The encode goes to a sibling temp file and is renamed into place, so a
     * second request arriving mid-render sees either the old derivative or
     * the new one and never a half-written image. rename() is atomic within a
     * filesystem, which pub/media is.
     *
     * @param callable $decoder one of the imagecreatefrom* functions
     */
    private function write(
        string $sourceAbs,
        string $cacheAbs,
        callable $decoder,
        int $type,
        int $sourceWidth,
        int $sourceHeight,
        int $width,
        int $height
    ): bool {
        $source = @$decoder($sourceAbs);

        if (!$source) {
            return false;
        }

        $target = null;

        try {
            $target = imagecreatetruecolor($width, $height);

            if (!$target) {
                return false;
            }

            // Transparency is set up before the copy, or PNG and WebP sources
            // come out on a black background.
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefilledrectangle($target, 0, 0, $width, $height, imagecolorallocatealpha($target, 0, 0, 0, 127));
            imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

            $temporary = $cacheAbs . '.' . getmypid() . '.tmp';

            if (!$this->encode($target, $temporary, $type)) {
                @unlink($temporary);

                return false;
            }

            if (!@rename($temporary, $cacheAbs)) {
                @unlink($temporary);

                return false;
            }

            return true;
        } finally {
            imagedestroy($source);

            if ($target) {
                imagedestroy($target);
            }
        }
    }

    private function encode(\GdImage $image, string $path, int $sourceType): bool
    {
        if (function_exists('imagewebp')) {
            return (bool) @imagewebp($image, $path, self::WEBP_QUALITY);
        }

        // No WebP on this host: keep the sizing win, keep the source format.
        if ($sourceType === IMAGETYPE_JPEG) {
            return (bool) @imagejpeg($image, $path, self::JPEG_QUALITY);
        }

        return (bool) @imagepng($image, $path, self::PNG_COMPRESSION);
    }

    private function outputExtension(int $sourceType): string
    {
        if (function_exists('imagewebp')) {
            return 'webp';
        }

        return $sourceType === IMAGETYPE_JPEG ? 'jpg' : 'png';
    }

    /**
     * @return array<int, callable-string>
     */
    private function decoders(): array
    {
        return array_filter(
            [
                IMAGETYPE_JPEG => 'imagecreatefromjpeg',
                IMAGETYPE_PNG => 'imagecreatefrompng',
                IMAGETYPE_GIF => 'imagecreatefromgif',
                IMAGETYPE_WEBP => 'imagecreatefromwebp',
            ],
            'function_exists'
        );
    }

    /**
     * A media-relative path with no way out of pub/media.
     */
    private function normalise(string $path): string
    {
        $path = ltrim(trim(str_replace('\\', '/', $path)), '/');

        if ($path === '' || str_contains($path, '../') || str_contains($path, "\0")) {
            return '';
        }

        return $path;
    }
}
