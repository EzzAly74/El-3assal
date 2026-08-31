<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\InstaPay\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Math\Random;

/**
 * Takes a proof-of-transfer upload and puts it somewhere safe.
 *
 * ===========================================================================
 * WHY var/ AND NOT pub/media/
 * ===========================================================================
 * A transfer receipt is a screenshot of somebody's banking app. It shows an
 * account, a name, an amount and a time.
 *
 * Anything written under pub/media is served directly by the web server, with
 * no PHP and therefore no session, no ACL and no logging. A single leaked or
 * guessed URL exposes the file to anyone, permanently, and Magento's media
 * directory is routinely made browsable by misconfigured deployments.
 *
 * So these go to var/spartrak/instapay, which is outside the document root and
 * unreachable by URL at all. Admins see them through
 * Controller\Adminhtml\Proof\View, which checks the ACL and streams the bytes.
 * That is one controller's worth of work in exchange for the file never being
 * public (CLAUDE.md section 17).
 *
 * ===========================================================================
 * WHY THE STORED NAME IS GENERATED
 * ===========================================================================
 * The uploaded name is attacker-controlled. `../../../app/etc/env.php`,
 * `receipt.php`, a 4000-character name, a name with a null byte - all of them
 * are things people actually send. Magento's Uploader defends against most of
 * it, but the cheapest defence is not to use the value at all: the file is
 * stored under a random 32-character name plus an extension derived from the
 * VERIFIED type, and the original is kept in a database column where it is
 * data rather than a path.
 *
 * Files are sharded into a two-level directory tree by the first characters of
 * that name, the same way Magento shards media, so a busy store does not end up
 * with one directory holding a hundred thousand entries.
 */
class ProofStorage
{
    private const BASE = 'spartrak/instapay';

    /**
     * Figma's own list (586:13015): `صيغة الصور المتاحه هي JPG,JPEG,HIECH`.
     *
     * HEIC is what an iPhone produces by default, and a shopper photographing
     * their banking app on an iPhone is the single most likely way this feature
     * is used - so refusing it would break the common case.
     *
     * PDF is NOT accepted. A PDF can carry script and is a well-trodden route
     * to stored XSS when something later renders it inline; a receipt is an
     * image, and keeping the list to images keeps the attack surface to image
     * parsers.
     */
    private const ALLOWED = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'heic' => ['image/heic', 'image/heif'],
    ];

    /**
     * 10 MB. A phone screenshot is well under 1 MB and a photograph of a screen
     * is a few. The cap is here so an upload cannot be used to fill the disk.
     */
    public const MAX_BYTES = 10 * 1024 * 1024;

    private ?WriteInterface $varDirectory = null;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Random $random
    ) {
    }

    /**
     * @return string[] extensions, for the file input's accept attribute
     */
    public function getAllowedExtensions(): array
    {
        return array_keys(self::ALLOWED);
    }

    /**
     * Store one uploaded file and return its path relative to the base.
     *
     * @param array{name?: string, type?: string, tmp_name?: string, size?: int, error?: int} $file
     *        one entry of $_FILES
     * @throws LocalizedException when the upload is missing, too large, or not
     *         an accepted image
     */
    public function store(array $file): string
    {
        $this->assertUploadSucceeded($file);

        $extension = $this->resolveExtension($file);

        $name = $this->random->getRandomString(32, Random::CHARS_LOWERS . Random::CHARS_DIGITS);
        $relative = sprintf(
            '%s/%s/%s/%s.%s',
            self::BASE,
            substr($name, 0, 2),
            substr($name, 2, 2),
            $name,
            $extension
        );

        $directory = $this->getVarDirectory();
        $directory->create(dirname($relative));

        $contents = file_get_contents($file['tmp_name']);

        if ($contents === false) {
            throw new LocalizedException(__('We could not read the uploaded file. Please try again.'));
        }

        $directory->writeFile($relative, $contents);

        // Strip the base back off: the database column is documented as relative
        // to var/spartrak/instapay, so a future move of the base directory is a
        // change in one constant rather than an UPDATE across the table.
        return substr($relative, strlen(self::BASE) + 1);
    }

    /**
     * The absolute path of a stored proof, for streaming to an admin.
     *
     * @throws LocalizedException when the path escapes the base directory
     */
    public function getAbsolutePath(string $relative): string
    {
        $full = self::BASE . '/' . ltrim($relative, '/');
        $directory = $this->getVarDirectory();
        $absolute = $directory->getAbsolutePath($full);

        /**
         * Defence in depth. The stored name is generated, so it cannot contain
         * traversal - but this method takes a string from a database row, and a
         * row can be wrong for reasons that have nothing to do with this class
         * (a bad import, a manual edit, a future bug). Resolving both paths and
         * comparing prefixes is what makes "read the file named in this column"
         * safe regardless of what the column says.
         */
        $baseReal = realpath($directory->getAbsolutePath(self::BASE));
        $fileReal = realpath($absolute);

        if ($baseReal === false || $fileReal === false || !str_starts_with($fileReal, $baseReal)) {
            throw new LocalizedException(__('That file is not available.'));
        }

        return $fileReal;
    }

    /**
     * @param array<string, mixed> $file
     * @throws LocalizedException
     */
    private function assertUploadSucceeded(array $file): void
    {
        if (empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new LocalizedException(__('Please choose an image of your transfer.'));
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new LocalizedException(__('The file could not be uploaded. Please try again.'));
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new LocalizedException(
                __('That image is too large. Please upload one under %1 MB.', self::MAX_BYTES / 1024 / 1024)
            );
        }
    }

    /**
     * Decide the extension from what the file IS, not what it is called.
     *
     * The browser-supplied name and Content-Type are both trivially forged, so
     * the type is taken from the bytes. getimagesize() covers JPEG; HEIC is
     * newer than getimagesize() and is identified by its ISO-BMFF brand instead,
     * which is a fixed marker at a known offset.
     *
     * @param array<string, mixed> $file
     * @throws LocalizedException
     */
    private function resolveExtension(array $file): string
    {
        $path = (string) $file['tmp_name'];

        $info = @getimagesize($path);

        if (is_array($info) && ($info['mime'] ?? '') === 'image/jpeg') {
            return 'jpg';
        }

        if ($this->looksLikeHeic($path)) {
            return 'heic';
        }

        throw new LocalizedException(
            __('Please upload a %1 image.', strtoupper(implode(', ', $this->getAllowedExtensions())))
        );
    }

    /**
     * HEIC/HEIF files are ISO base media files whose `ftyp` box, at byte 4,
     * carries a brand of `heic`, `heix`, `mif1` or `msf1`.
     */
    private function looksLikeHeic(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $header = (string) fread($handle, 12);
        fclose($handle);

        if (strlen($header) < 12 || substr($header, 4, 4) !== 'ftyp') {
            return false;
        }

        return in_array(substr($header, 8, 4), ['heic', 'heix', 'mif1', 'msf1'], true);
    }

    private function getVarDirectory(): WriteInterface
    {
        if ($this->varDirectory === null) {
            $this->varDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        }

        return $this->varDirectory;
    }
}
