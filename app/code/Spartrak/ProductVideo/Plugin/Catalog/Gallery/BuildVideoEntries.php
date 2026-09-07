<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\ProductVideo\Plugin\Catalog\Gallery;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Gallery\CreateHandler;
use Magento\Framework\Exception\LocalizedException;
use Magento\ProductVideo\Model\Product\Attribute\Media\ExternalVideoEntryConverter;
use Spartrak\ProductVideo\Model\Source\VideoSourceOptions;
use Spartrak\ProductVideo\Model\Video\Storage;
use Spartrak\ProductVideo\Model\Video\Uploader;

/**
 * Turns the product form's Product Videos rows into media gallery entries.
 *
 * ===========================================================================
 * THIS IS WHAT LETS THE ADMIN BE INLINE WITHOUT LOSING THE GALLERY
 * ===========================================================================
 * Magento only creates video gallery entries through its "Add Video" modal.
 * This module does not use that modal — a merchant adds a video in a fieldset
 * on the product form, with no dialog — but a video still has to BE a gallery
 * entry, because that is what makes it appear among the product's gallery
 * thumbnails on the storefront, ordered against the photographs, with its
 * poster resized by Magento's own image pipeline.
 *
 * So this runs BEFORE Magento's gallery handler and hands it exactly the rows
 * the modal would have produced. Everything downstream — the move out of tmp,
 * the value_id, the video table, the cache invalidation, product duplicate,
 * import/export — is then core's, unmodified.
 *
 * ===========================================================================
 * IT MERGES, IT DOES NOT REPLACE
 * ===========================================================================
 * `media_gallery.images` already carries the product's photographs, posted by
 * the gallery UI above — and, for a video that already exists, that video's own
 * row too. Rows are therefore matched by `value_id` and MERGED: the gallery
 * keeps owning position, the poster file and the hidden flag, this fieldset
 * owns everything about the video itself.
 *
 * Overwriting the array instead would delete every product photo the first
 * time anyone touched a video.
 *
 * ===========================================================================
 * THE UPLOADED FILE IS PROMOTED HERE, NOT LATER
 * ===========================================================================
 * A freshly uploaded video sits in `spartrak/product-video/tmp/` and is moved
 * to its permanent home by this plugin, before the row is built — because the
 * row's `video_url` has to be the file's FINAL URL. Doing it afterwards would
 * have written a tmp URL into Magento's video table and left the storefront
 * pointing at a file that had already moved.
 */
class BuildVideoEntries
{
    private const ROWS_KEY = 'spartrak_videos';

    /**
     * Row field => the gallery/side-table key it becomes. Declared once so the
     * form, this translation and Plugin\Catalog\Gallery\SaveVideoSettings
     * cannot drift apart.
     */
    private const FLAGS = [
        'autoplay' => 'spartrak_video_autoplay',
        'loop' => 'spartrak_video_loop',
        'muted' => 'spartrak_video_muted',
        'controls' => 'spartrak_video_controls',
        'allow_download' => 'spartrak_video_download',
    ];

    public function __construct(
        private readonly Uploader $uploader,
        private readonly Storage $storage
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     * @throws LocalizedException
     */
    public function beforeExecute(CreateHandler $subject, Product $product, array $arguments = []): array
    {
        $rows = $product->getData(self::ROWS_KEY);

        if (!is_array($rows) || $rows === []) {
            return [$product, $arguments];
        }

        $attributeCode = $subject->getAttribute()->getAttributeCode();
        $gallery = $product->getData($attributeCode);
        $images = is_array($gallery) && isset($gallery['images']) && is_array($gallery['images'])
            ? $gallery['images']
            : [];

        // Existing rows are addressed by value_id; the gallery keys them by an
        // opaque file_id, so an index is built once rather than searched per row.
        $byValueId = [];

        foreach ($images as $key => $image) {
            if (!empty($image['value_id'])) {
                $byValueId[(int) $image['value_id']] = $key;
            }
        }

        $position = $this->highestPosition($images);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $valueId = (int) ($row['value_id'] ?? 0);
            $key = $valueId > 0 ? ($byValueId[$valueId] ?? null) : null;

            if (!empty($row['removed'])) {
                // Deleting the row deletes the gallery entry. The side tables
                // go with it through their foreign key.
                if ($key !== null) {
                    $images[$key]['removed'] = 1;
                }

                continue;
            }

            $built = $this->buildRow($row, $key !== null ? $images[$key] : [], ++$position);

            if ($key !== null) {
                $images[$key] = $built;
            } else {
                $images['spartrak_video_' . count($images)] = $built;
            }
        }

        $gallery['images'] = $images;
        $gallery['values'] = $gallery['values'] ?? [];
        $product->setData($attributeCode, $gallery);

        return [$product, $arguments];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    private function buildRow(array $row, array $existing, int $fallbackPosition): array
    {
        $poster = $this->posterFile($row, $existing);

        if ($poster === '') {
            throw new LocalizedException(
                __('Every product video needs a preview image. Add one to "%1".', (string) ($row['title'] ?? ''))
            );
        }

        $isUpload = ($row['source'] ?? VideoSourceOptions::URL) === VideoSourceOptions::UPLOAD;
        $srcPath = $isUpload ? $this->resolveUploadedPath($row, $existing) : '';
        $videoUrl = $isUpload ? $this->storage->getUrl($srcPath) : trim((string) ($row['video_url'] ?? ''));

        $built = $existing;

        // ---- what the gallery owns -------------------------------------
        $built['file'] = $poster;
        $built['media_type'] = ExternalVideoEntryConverter::MEDIA_TYPE_CODE;
        $built['position'] = $existing['position'] ?? $fallbackPosition;
        $built['disabled'] = (int) ($row['disabled'] ?? 0);
        $built['label'] = (string) ($row['title'] ?? '');
        $built['removed'] = '';

        // A poster that changed on an EXISTING entry has to be moved out of tmp
        // like a new one. `recreate` is core's own flag for exactly that
        // (CreateHandler.php:220).
        if (!empty($existing['file']) && $existing['file'] !== $poster) {
            $built['recreate'] = 1;
        }

        // ---- what Magento_ProductVideo owns ----------------------------
        $built['video_url'] = $videoUrl;
        $built['video_title'] = (string) ($row['title'] ?? '');
        $built['video_description'] = (string) ($row['description'] ?? '');
        // Left to this module's normaliser, which resolves it from the URL on
        // save. Magento only stores it; nothing reads it back on this store.
        $built['video_provider'] = $existing['video_provider'] ?? '';

        // ---- what this module owns -------------------------------------
        // Read by Plugin\Catalog\Gallery\SaveVideoSettings::afterExecute, once
        // core has given the row its value_id.
        $built['spartrak_video_path'] = $srcPath;
        $built['spartrak_video_featured'] = (int) ($row['is_featured'] ?? 0);
        $built['spartrak_video_chapters_ar'] = (string) ($row['chapters_ar'] ?? '');
        $built['spartrak_video_chapters_en'] = (string) ($row['chapters_en'] ?? '');

        foreach (self::FLAGS as $field => $key) {
            $built[$key] = (string) ($row[$field] ?? '');
        }

        return $built;
    }

    /**
     * The poster's catalog-relative path.
     *
     * The picker posts Magento's own upload response, so `file` is either a
     * path under the catalog tmp directory (a new upload) or the entry's
     * existing path (untouched). Either way it is the value core's handler
     * expects, and passing it through is all this has to do.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $existing
     */
    private function posterFile(array $row, array $existing): string
    {
        $poster = $row['poster'] ?? null;

        if (is_array($poster) && isset($poster[0]) && is_array($poster[0])) {
            $file = (string) ($poster[0]['file'] ?? $poster[0]['name'] ?? '');

            if ($file !== '') {
                return $file;
            }
        }

        return (string) ($existing['file'] ?? '');
    }

    /**
     * Promotes a freshly uploaded video out of the staging directory, or keeps
     * the one already stored.
     *
     * The prefix test is what distinguishes them: a file that is still in tmp
     * has just been uploaded and must be moved; one that is not has been in its
     * permanent home since an earlier save, and moving it again would be moving
     * a file that is not there.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $existing
     * @throws LocalizedException
     */
    private function resolveUploadedPath(array $row, array $existing): string
    {
        $file = $row['video_file'] ?? null;
        $path = '';

        if (is_array($file) && isset($file[0]) && is_array($file[0])) {
            $path = (string) ($file[0]['spartrak_tmp_path'] ?? '');

            if ($path === '' && !empty($file[0]['name'])) {
                // The uploader's response shape is this module's own, but a
                // form re-post can lose the extra key while keeping the name.
                // Rebuilding the staged path from the constant is safe because
                // the name is all the tmp directory is keyed on.
                $path = Storage::BASE_TMP_PATH . '/' . ltrim((string) $file[0]['name'], '/');
            }
        }

        if ($path === '') {
            $path = (string) ($existing['spartrak_src_path'] ?? '');
        }

        if ($path === '') {
            throw new LocalizedException(
                __('Choose a video file, or switch the source to a URL, for "%1".', (string) ($row['title'] ?? ''))
            );
        }

        if (!str_starts_with($path, Storage::BASE_TMP_PATH . '/')) {
            return $path;
        }

        return $this->uploader->moveFileFromTmp(substr($path, strlen(Storage::BASE_TMP_PATH) + 1));
    }

    /**
     * @param array<int|string, array<string, mixed>> $images
     */
    private function highestPosition(array $images): int
    {
        $highest = 0;

        foreach ($images as $image) {
            $highest = max($highest, (int) ($image['position'] ?? 0));
        }

        return $highest;
    }
}
