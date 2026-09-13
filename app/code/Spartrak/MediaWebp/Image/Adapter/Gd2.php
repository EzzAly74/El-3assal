<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\MediaWebp\Image\Adapter;

use Magento\Framework\Image\Adapter\Gd2 as CoreGd2;

/**
 * GD2 image adapter with WebP support.
 *
 * Magento 2.4.8 ships a GD2 adapter whose format table (create/output callback
 * per IMAGETYPE_*) covers GIF, JPEG, PNG, XBM and WBMP only. That table, and
 * the _getCallback() accessor that reads it, are BOTH private, so the supported
 * format set cannot be widened by subclassing or by DI - any WebP file makes
 * open() throw "Unsupported image format" long before an upload is accepted.
 *
 * This adapter therefore intercepts the four public entry points that consult
 * the private table and services IMAGETYPE_WEBP itself, delegating every other
 * format back to the core implementation unchanged. Nothing is copied from the
 * parent for the non-WebP path, so core fixes to it keep applying.
 *
 * Scope note - the parent's crop() and watermark() also read the private table.
 * They are deliberately NOT overridden: a WebP *watermark asset* cannot be
 * uploaded in the first place (Magento\Config\Model\Config\Backend\Image keeps
 * its own jpg/gif/png allow-list, which this module does not widen), and crop()
 * is not part of the catalog image pipeline. See README.md.
 */
class Gd2 extends CoreGd2
{
    /**
     * GD's documented "library default" quality for imagewebp().
     */
    private const DEFAULT_WEBP_QUALITY = -1;

    /**
     * Schemes Magento\Framework\Image\Adapter\Gd2::validateURLScheme() accepts.
     *
     * Mirrored here because that method is private; keeping the same list keeps
     * the WebP branch's rejection behaviour identical to every other format's.
     */
    private const ALLOWED_URL_SCHEMES = ['ftp', 'ftps', 'http', 'https'];

    /**
     * @inheritDoc
     */
    public function getSupportedFormats()
    {
        return array_merge(parent::getSupportedFormats(), ['webp']);
    }

    /**
     * @inheritDoc
     */
    public function open($filename)
    {
        if (!$this->isWebpFile($filename)) {
            parent::open($filename);

            return;
        }

        if (filesize($filename) === 0 || !$this->hasAllowedUrlScheme($filename)) {
            throw new \InvalidArgumentException('Wrong file');
        }

        $this->_fileName = $filename;
        $this->_reset();
        $this->getMimeType();
        $this->_getFileAttributes();

        if ($this->_isMemoryLimitReached()) {
            throw new \OverflowException('Memory limit has been reached.');
        }

        $this->destroyImageHandler();

        $imageHandler = imagecreatefromwebp($filename);

        if ($imageHandler === false) {
            throw new \InvalidArgumentException(
                sprintf('Unsupported image format. File: %s', $filename)
            );
        }

        // WebP carries a real alpha channel; keep it addressable for resize/save.
        imagealphablending($imageHandler, false);
        imagesavealpha($imageHandler, true);

        $this->_imageHandler = $imageHandler;
    }

    /**
     * Change the image size.
     *
     * The parent decides truecolor-vs-palette from _getTransparency(), which
     * only knows GIF/PNG/JPEG - an unknown type falls through to imagecreate(),
     * i.e. a 256-colour palette, which would visibly band every resized WebP
     * thumbnail. WebP is always truecolor, so it gets its own canvas here.
     *
     * @param null|int $frameWidth
     * @param null|int $frameHeight
     * @return void
     */
    public function resize($frameWidth = null, $frameHeight = null)
    {
        if (!$this->isWebpImageLoaded()) {
            parent::resize($frameWidth, $frameHeight);

            return;
        }

        // _adaptResizeValues() returns round()ed floats. Core gets away with
        // handing those straight to GD because it runs in coercive typing mode;
        // this file declares strict_types, so every dimension is cast here.
        $dims = $this->_adaptResizeValues($frameWidth, $frameHeight);
        $width = (int) $dims['frame']['width'];
        $height = (int) $dims['frame']['height'];

        $newImage = imagecreatetruecolor($width, $height);

        if ($this->keepsTransparency()) {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $background = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
        } else {
            list($red, $green, $blue) = $this->_backgroundColor ?: [0, 0, 0];
            $background = imagecolorallocate($newImage, $red, $green, $blue);
            imagealphablending($newImage, true);
            imagesavealpha($newImage, false);
        }

        imagefilledrectangle($newImage, 0, 0, $width - 1, $height - 1, $background);

        if ($this->_imageHandler) {
            imagecopyresampled(
                $newImage,
                $this->_imageHandler,
                (int) $dims['dst']['x'],
                (int) $dims['dst']['y'],
                (int) $dims['src']['x'],
                (int) $dims['src']['y'],
                (int) $dims['dst']['width'],
                (int) $dims['dst']['height'],
                (int) $this->_imageSrcWidth,
                (int) $this->_imageSrcHeight
            );
        }

        $this->destroyImageHandler();
        $this->_imageHandler = $newImage;
        $this->refreshImageDimensions();
        $this->_resized = true;
    }

    /**
     * @inheritDoc
     */
    public function save($destination = null, $newName = null)
    {
        if (!$this->isWebpImageLoaded()) {
            parent::save($destination, $newName);

            return;
        }

        $fileName = $this->_prepareDestination($destination, $newName);

        imagesavealpha($this->_imageHandler, $this->keepsTransparency());
        imagewebp($this->_imageHandler, $fileName, $this->getWebpQuality());
    }

    /**
     * @inheritDoc
     */
    public function getImage()
    {
        if (!$this->isWebpImageLoaded()) {
            return parent::getImage();
        }

        ob_start();
        imagesavealpha($this->_imageHandler, $this->keepsTransparency());
        imagewebp($this->_imageHandler, null, $this->getWebpQuality());

        return ob_get_clean();
    }

    /**
     * Whether the alpha channel has to survive this operation.
     *
     * _keepTransparency is left uninitialised by the core adapter and several
     * callers (Cms\Model\Wysiwyg\Images\Storage::resizeFile among them) never
     * set it. The core PNG path preserves alpha in that case anyway - via
     * _saveAlpha(), which does not consult the flag - so WebP, an equally
     * alpha-capable format, defaults the same way. Only an explicit false
     * flattens the image onto _backgroundColor.
     *
     * @return bool
     */
    private function keepsTransparency(): bool
    {
        return $this->_keepTransparency ?? true;
    }

    /**
     * Whether the currently opened image is a WebP.
     *
     * @return bool
     */
    private function isWebpImageLoaded(): bool
    {
        return $this->_fileType === IMAGETYPE_WEBP;
    }

    /**
     * Whether the given path points at a readable WebP file.
     *
     * Signature-based, not extension-based: a mis-named .png that is really a
     * WebP must take the WebP branch, and vice versa.
     *
     * @param mixed $filename
     * @return bool
     */
    private function isWebpFile($filename): bool
    {
        if (!is_string($filename) || $filename === '' || !is_file($filename)) {
            return false;
        }

        $imageInfo = getimagesize($filename);

        return is_array($imageInfo) && ($imageInfo[2] ?? null) === IMAGETYPE_WEBP;
    }

    /**
     * Reject stream wrappers (phar://, data:// ...) the core adapter also rejects.
     *
     * @param string $filename
     * @return bool
     */
    private function hasAllowedUrlScheme(string $filename): bool
    {
        $url = parse_url($filename);

        return !($url && isset($url['scheme']) && !in_array($url['scheme'], self::ALLOWED_URL_SCHEMES, true));
    }

    /**
     * Output quality for imagewebp().
     *
     * Honours the admin's Quality setting (Stores > Configuration > Catalog >
     * Catalog > Storefront > Quality) the way the JPEG branch does, and falls
     * back to GD's own default when nothing is configured.
     *
     * @return int
     */
    private function getWebpQuality(): int
    {
        $quality = $this->quality();

        return $quality === null ? self::DEFAULT_WEBP_QUALITY : (int) $quality;
    }

    /**
     * Free the current GD handle.
     *
     * The parent's imageDestroy() is private, so the WebP branch carries its own.
     *
     * @return void
     */
    private function destroyImageHandler(): void
    {
        if ($this->_imageHandler instanceof \GdImage) {
            imagedestroy($this->_imageHandler);
            $this->_imageHandler = null;
        }
    }
}
