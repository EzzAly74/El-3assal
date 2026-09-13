<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\MediaWebp\Plugin\MediaStorage;

use Magento\Framework\Image\Factory as ImageFactory;
use Magento\MediaStorage\Model\File\Validator\Image as ImageValidator;

/**
 * Extends Magento's "can this file actually be decoded?" upload check to WebP.
 *
 * Magento\MediaStorage\Model\File\Validator\Image only opens a file when its
 * detected MIME type appears in a PRIVATE map that stops at png/jpe/jpeg/jpg/
 * gif/bmp/ico - anything else is waved through unopened. With WebP uploads
 * enabled that leaves one gap the other formats do not have: a file with a
 * plausible RIFF/WEBP header but a corrupt payload passes the MIME sniff every
 * upload endpoint performs, gets written to pub/media, and only then blows up
 * when the thumbnailer tries to open it - leaving an orphaned file behind.
 *
 * This closes that gap without touching the private map: WebP files are opened
 * through the same image factory core uses, everything else keeps core's
 * verdict untouched.
 */
class ValidateWebpIsDecodable
{
    /**
     * @var ImageFactory
     */
    private $imageFactory;

    /**
     * @param ImageFactory $imageFactory
     */
    public function __construct(ImageFactory $imageFactory)
    {
        $this->imageFactory = $imageFactory;
    }

    /**
     * Reject WebP uploads that no decoder can read.
     *
     * @param ImageValidator $subject
     * @param bool $result
     * @param string $filePath
     * @return bool
     */
    public function afterIsValid(ImageValidator $subject, bool $result, $filePath): bool
    {
        if (!$result || !$this->isWebp($filePath)) {
            return $result;
        }

        try {
            $this->imageFactory->create($filePath)->open();
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * Whether the file claims, by signature, to be a WebP.
     *
     * @param mixed $filePath
     * @return bool
     */
    private function isWebp($filePath): bool
    {
        if (!is_string($filePath) || $filePath === '' || !is_file($filePath)) {
            return false;
        }

        $imageInfo = getimagesize($filePath);

        return is_array($imageInfo) && ($imageInfo[2] ?? null) === IMAGETYPE_WEBP;
    }
}
