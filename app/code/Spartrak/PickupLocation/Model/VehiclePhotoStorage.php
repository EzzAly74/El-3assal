<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Math\Random;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Stores the admin's photograph of the vehicle carrying an order.
 *
 * ===========================================================================
 * pub/media, NOT var/ — AND WHY THAT IS THE OPPOSITE OF InstaPay
 * ===========================================================================
 * Spartrak_InstaPay's ProofStorage puts its uploads under var/ and streams them
 * through an ACL-checked controller, because a transfer receipt is a screenshot
 * of somebody's banking app.
 *
 * This is a photograph of the outside of a microbus. Its whole purpose
 * (BUSINESS.md section 12, §4) is to be shown to the CUSTOMER so they can pick
 * the right vehicle out of a yard full of near-identical ones — so it has to be
 * servable to a shopper, and putting it behind an admin ACL would defeat the
 * feature. Under pub/media it is served by the web server with no PHP, gets
 * cache and CDN headers for free, and does not put a PHP request in the path of
 * an image on the order page.
 *
 * The filename is still a random 32 characters. Not because the picture is
 * secret, but because a guessable path (`consignment/1234.jpg`) would let
 * anyone enumerate how many station orders the shop has dispatched, and because
 * the uploaded name is attacker-controlled and is never used as a path — see
 * ProofStorage for the full list of what people actually send.
 *
 * ===========================================================================
 * WHAT IS ACCEPTED
 * ===========================================================================
 * A photograph taken on a phone, which in practice means JPEG, PNG or — on any
 * recent iPhone — HEIC. HEIC is accepted for the same reason InstaPay accepts
 * it: refusing the default format of the most likely camera would break the
 * common case. PDF is not an image and is not accepted.
 *
 * The type is taken from the file's own bytes via getimagesize(), not from the
 * client-supplied MIME type or the extension, both of which are trivially
 * forged. HEIC is the exception — getimagesize() does not recognise it in most
 * PHP builds — so it is validated by its ISO-BMFF brand instead.
 */
class VehiclePhotoStorage
{
    /**
     * Relative to pub/media. `spartrak/pickup` groups it with anything else
     * this module ever needs to publish.
     */
    public const BASE = 'spartrak/pickup/consignment';

    /**
     * Extension => the image type getimagesize() must report for it.
     */
    private const ALLOWED_IMAGE_TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
    ];

    /**
     * ISO base-media-file-format brands that mean "this is a HEIF/HEIC image".
     */
    private const HEIC_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'mif1', 'msf1'];

    /**
     * 12 MB. A modern phone photograph is 2-6 MB; the cap exists so an upload
     * cannot be used to fill the disk.
     */
    public const MAX_BYTES = 12 * 1024 * 1024;

    private ?WriteInterface $mediaDirectory = null;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Random $random,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return string[] extensions, for the file input's accept attribute
     */
    public function getAllowedExtensions(): array
    {
        // HEIC is appended rather than being a member of ALLOWED_IMAGE_TYPES,
        // because that map is keyed by the IMAGETYPE_* constant getimagesize()
        // returns and there is no such constant for HEIC — which is exactly why
        // it needs the separate brand sniff below.
        return [...array_values(self::ALLOWED_IMAGE_TYPES), 'heic'];
    }

    /**
     * Store one uploaded photograph and return its path relative to BASE.
     *
     * @param array{name?: string, type?: string, tmp_name?: string, size?: int, error?: int} $file
     *        one entry of $_FILES
     * @throws LocalizedException when the upload failed, is too large, or is not
     *         a photograph
     */
    public function store(array $file): string
    {
        $this->assertUploadSucceeded($file);

        $extension = $this->resolveExtension((string) $file['tmp_name']);
        $name = $this->random->getRandomString(32, Random::CHARS_LOWERS . Random::CHARS_DIGITS);

        $relative = sprintf(
            '%s/%s/%s.%s',
            substr($name, 0, 2),
            substr($name, 2, 2),
            $name,
            $extension
        );

        $directory = $this->getMediaDirectory();
        $full = self::BASE . '/' . $relative;
        $directory->create(dirname($full));

        $contents = file_get_contents($file['tmp_name']);

        if ($contents === false) {
            throw new LocalizedException(__('We could not read the uploaded photo. Please try again.'));
        }

        $directory->writeFile($full, $contents);

        return $relative;
    }

    /**
     * The public URL of a stored photograph, for the storefront card.
     */
    public function getUrl(string $relative): string
    {
        $relative = ltrim($relative, '/');

        if ($relative === '') {
            return '';
        }

        return $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA)
            . self::BASE . '/' . $relative;
    }

    /**
     * Remove a photograph that has been replaced.
     *
     * Failure is swallowed on purpose, and this is the one place in the module
     * where that is right: the database row has already been repointed at the
     * new file, so a stale byte-blob on disk is litter, not a fault, and
     * throwing here would fail an admin's save for something they cannot act on.
     */
    public function deleteQuietly(string $relative): void
    {
        $relative = ltrim($relative, '/');

        if ($relative === '') {
            return;
        }

        try {
            $this->getMediaDirectory()->delete(self::BASE . '/' . $relative);
        } catch (\Exception) {
            return;
        }
    }

    /**
     * @param array{name?: string, type?: string, tmp_name?: string, size?: int, error?: int} $file
     * @throws LocalizedException
     */
    private function assertUploadSucceeded(array $file): void
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new LocalizedException(__('That photo is too large. Please upload one under 12 MB.'));
        }

        if ($error !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new LocalizedException(__('Please attach a photo of the vehicle.'));
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new LocalizedException(__('That photo is too large. Please upload one under 12 MB.'));
        }
    }

    /**
     * The extension to store the file under, decided from its own bytes.
     *
     * @throws LocalizedException
     */
    private function resolveExtension(string $tmpName): string
    {
        $info = @getimagesize($tmpName);

        if (is_array($info) && isset(self::ALLOWED_IMAGE_TYPES[$info[2]])) {
            return self::ALLOWED_IMAGE_TYPES[$info[2]];
        }

        if ($this->isHeic($tmpName)) {
            return 'heic';
        }

        throw new LocalizedException(
            __('That file is not a photo. Please upload a JPG, PNG or HEIC image of the vehicle.')
        );
    }

    /**
     * ISO-BMFF brand sniff: bytes 4-8 are 'ftyp', 8-12 are the major brand.
     */
    private function isHeic(string $tmpName): bool
    {
        $handle = @fopen($tmpName, 'rb');

        if ($handle === false) {
            return false;
        }

        $header = (string) fread($handle, 12);
        fclose($handle);

        return strlen($header) === 12
            && substr($header, 4, 4) === 'ftyp'
            && in_array(strtolower(substr($header, 8, 4)), self::HEIC_BRANDS, true);
    }

    private function getMediaDirectory(): WriteInterface
    {
        if ($this->mediaDirectory === null) {
            $this->mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        }

        return $this->mediaDirectory;
    }
}
