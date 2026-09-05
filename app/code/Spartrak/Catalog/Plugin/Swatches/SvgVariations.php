<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Catalog\Plugin\Swatches;

use Magento\Swatches\Helper\Media as SwatchMedia;
use Spartrak\Catalog\Model\Swatch\RasterWrappedSvg;

/**
 * Keeps SVG swatches out of the raster resize pipeline.
 *
 * ===========================================================================
 * THE TWO PLACES SVG WOULD OTHERWISE BREAK
 * ===========================================================================
 * Magento_Swatches assumes every swatch is a raster file:
 *
 *   generateSwatchVariations()  builds a Magento\Framework\Image for the file
 *                               and resizes it into swatch_image/<WxH>/ and
 *                               swatch_thumb/<WxH>/. Image is GD underneath
 *                               and throws on an SVG.
 *
 *   getSwatchAttributeImage()   returns the URL of one of those generated
 *                               files, and in hash mode it will TRY to
 *                               generate one that is missing — so for an SVG
 *                               it either throws or returns an empty string,
 *                               and the logo silently disappears.
 *
 * ===========================================================================
 * WHY THERE IS NOTHING TO GENERATE
 * ===========================================================================
 * A resize exists to stop a 3000px photograph being sent to a 135px box. An
 * SVG has no pixel dimensions to reduce: it is a few kilobytes of geometry that
 * the browser rasterises at whatever size it is drawn, which is the entire
 * reason the brief asked for SVG brand marks in the first place. Generating a
 * 270x180 PNG from one would take the sharpness back out again.
 *
 * So the variation step is skipped and the ORIGINAL file is served. That is
 * fewer files, fewer bytes and one fewer processing step than the raster path,
 * not a workaround for one.
 *
 * Raster swatches are untouched: both methods fall straight through to core
 * for anything that is not a .svg.
 *
 * ===========================================================================
 * THE ASSUMPTION ABOVE IS ONLY HALF TRUE (added 2026-09-06)
 * ===========================================================================
 * "An SVG has no pixel dimensions to reduce" holds for a real vector file. It
 * does not hold for a Figma export of a shape with an IMAGE FILL, which is an
 * SVG wrapper around one base64 raster — and all seven of this storefront's
 * brand marks are exactly that, 623,248 bytes of them, two of which are the
 * heaviest resources on the homepage.
 *
 * Skipping core's variation step is still right: those variations would be
 * PNGs regenerated at fixed swatch dimensions, and there is nothing to gain
 * from a second raster pipeline. What was missing is that the file being
 * served instead is not a few kilobytes of geometry. Model\Swatch\
 * RasterWrappedSvg rewrites the payload inside it and leaves every attribute
 * alone; see that class for the measurements and why it is lossless.
 */
class SvgVariations
{
    public function __construct(
        private readonly RasterWrappedSvg $rasterWrappedSvg
    ) {
    }

    /**
     * Nothing for core to resize — but this is the one moment an SVG swatch is
     * known to have just been uploaded, so the derivative is built HERE rather
     * than leaving the first shopper to pay for it.
     *
     * Returns the helper unchanged, as core's own method does, so a caller
     * chaining off it still works.
     */
    public function aroundGenerateSwatchVariations(
        SwatchMedia $subject,
        callable $proceed,
        $imageUrl
    ) {
        if (!$this->isSvg((string) $imageUrl)) {
            return $proceed($imageUrl);
        }

        // Return value ignored deliberately: a failure here is not an upload
        // failure. The class logs it, the storefront serves the original, and
        // the next render retries.
        $this->rasterWrappedSvg->optimise(
            $this->mediaRelativePath($subject, (string) $imageUrl)
        );

        return $subject;
    }

    /**
     * The optimised copy's URL where one exists, and the original file's own
     * URL otherwise — never a generated rendition's.
     *
     * getSwatchMediaUrl() is core's base ("…/media/attribute/swatch") and
     * getAttributeSwatchPath() is core's own path builder for the stored file,
     * so this is assembled entirely from core's methods and stays correct if
     * either of them moves.
     *
     * The optimise() call is lazy rather than assumed-warm: the upload hook
     * above covers new files, but a cleared pub/media, a restored backup or a
     * swatch that predates this code all reach here with no derivative, and
     * rebuilding on demand is what stops any of those silently costing 623 KB
     * again. It is a filesystem stat on the warm path, and nothing more.
     */
    public function aroundGetSwatchAttributeImage(
        SwatchMedia $subject,
        callable $proceed,
        $swatchType,
        $file
    ) {
        if (!$this->isSvg((string) $file)) {
            return $proceed($swatchType, $file);
        }

        $relative = $this->mediaRelativePath($subject, (string) $file);
        $optimised = $this->rasterWrappedSvg->optimise($relative);

        return $this->mediaUrl($subject, $optimised ?? $relative);
    }

    /**
     * The stored file as a path relative to pub/media.
     *
     * Built from core's own getAttributeSwatchPath()/getSwatchMediaPath() pair
     * rather than by string-joining "attribute/swatch", so it follows core if
     * the layout of that directory ever changes.
     */
    private function mediaRelativePath(SwatchMedia $subject, string $file): string
    {
        return ltrim($subject->getAttributeSwatchPath($file), '/');
    }

    /**
     * A media-relative path as a storefront URL.
     *
     * getSwatchMediaUrl() already ends with the media path that a swatch path
     * also starts with, so the shared segment is taken off rather than
     * repeated.
     */
    private function mediaUrl(SwatchMedia $subject, string $relative): string
    {
        $root = trim($subject->getSwatchMediaPath(), '/');
        $tail = $relative;

        if ($root !== '' && str_starts_with($relative, $root)) {
            $tail = substr($relative, strlen($root));
        }

        return rtrim($subject->getSwatchMediaUrl(), '/') . '/' . ltrim($tail, '/');
    }

    private function isSvg(string $file): bool
    {
        return strtolower((string) pathinfo($file, PATHINFO_EXTENSION)) === 'svg';
    }
}
