<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Catalog\Plugin\Swatches;

use Magento\Swatches\Helper\Media as SwatchMedia;

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
 */
class SvgVariations
{
    /**
     * Nothing to resize — return the helper unchanged, as core's own method
     * does, so a caller chaining off it still works.
     */
    public function aroundGenerateSwatchVariations(
        SwatchMedia $subject,
        callable $proceed,
        $imageUrl
    ) {
        if ($this->isSvg((string) $imageUrl)) {
            return $subject;
        }

        return $proceed($imageUrl);
    }

    /**
     * The original file's own URL, rather than a generated rendition's.
     *
     * getSwatchMediaUrl() is core's base ("…/media/attribute/swatch") and
     * getAttributeSwatchPath() is core's own path builder for the stored file,
     * so this is assembled entirely from core's methods and stays correct if
     * either of them moves.
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

        $path = $subject->getAttributeSwatchPath($file);

        // getSwatchMediaUrl() already ends with the media path that
        // getAttributeSwatchPath() also starts with, so the shared segment is
        // taken off rather than repeated.
        return rtrim($subject->getSwatchMediaUrl(), '/')
            . '/'
            . ltrim(substr($path, strlen($subject->getSwatchMediaPath())), '/');
    }

    private function isSvg(string $file): bool
    {
        return strtolower((string) pathinfo($file, PATHINFO_EXTENSION)) === 'svg';
    }
}
