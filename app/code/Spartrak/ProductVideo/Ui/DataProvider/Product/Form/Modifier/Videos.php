<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\ProductVideo\Ui\DataProvider\Product\Form\Modifier;

use Magento\Catalog\Model\Locator\LocatorInterface;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\ProductVideo\Model\Product\Attribute\Media\ExternalVideoEntryConverter;
use Magento\Ui\DataProvider\Modifier\ModifierInterface;
use Spartrak\ProductVideo\Model\ResourceModel\VideoChapter;
use Spartrak\ProductVideo\Model\Source\SourceType;
use Spartrak\ProductVideo\Model\Source\VideoSourceOptions;
use Spartrak\ProductVideo\Model\Video\ChapterParser;
use Spartrak\ProductVideo\Model\Video\Storage;

/**
 * Fills the Product Videos fieldset from what the product already has.
 *
 * ===========================================================================
 * DATA ONLY — THE FIELDS THEMSELVES ARE XML
 * ===========================================================================
 * `modifyMeta()` returns its argument untouched. The fieldset, its rows and
 * every field in them are declared in view/adminhtml/ui_component/product_form.xml,
 * where they can be read, diffed and overridden by a merchant's own module.
 * Building form structure in PHP is how a form becomes unreadable.
 *
 * ===========================================================================
 * IT READS THE RAW GALLERY ROWS, NOT getMediaGalleryImages()
 * ===========================================================================
 * That method SKIPS entries flagged `disabled` (Product.php:1554). On the
 * storefront that is exactly right. In the admin it would mean a video the
 * merchant hid from the product page vanishing from the form that hid it, with
 * no way to bring it back — so this reads `media_gallery.images` directly,
 * which is every row.
 *
 * ===========================================================================
 * NO EXTRA QUERIES FOR THE SETTINGS
 * ===========================================================================
 * The `spartrak_*` columns are already on each row: they are joined into the
 * gallery's own select by Plugin\Catalog\Gallery\JoinVideoSettings, which runs
 * on the admin load exactly as it does on the storefront. Only chapters need
 * fetching, and that is one `IN (...)` for the whole product — the same query
 * the PDP would issue, skipped entirely when no row is a native video.
 */
class Videos implements ModifierInterface
{
    public function __construct(
        private readonly LocatorInterface $locator,
        private readonly VideoChapter $chapterResource,
        private readonly ChapterParser $chapterParser,
        private readonly Storage $storage,
        private readonly MediaConfig $mediaConfig,
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function modifyMeta(array $meta): array
    {
        return $meta;
    }

    /**
     * @param array<int|string, mixed> $data
     * @return array<int|string, mixed>
     */
    public function modifyData(array $data): array
    {
        $product = $this->locator->getProduct();
        $productId = $product->getId();

        if (!$productId) {
            return $data;
        }

        $gallery = $product->getData('media_gallery');
        $entries = is_array($gallery) && isset($gallery['images']) && is_array($gallery['images'])
            ? $gallery['images']
            : [];

        $videos = $this->collectVideoEntries($entries);

        if ($videos === []) {
            return $data;
        }

        $chapters = $this->loadChapters($videos);
        $rows = [];

        foreach ($videos as $entry) {
            $rows[] = $this->toRow($entry, $chapters[(int) $entry['value_id']] ?? []);
        }

        $data[$productId]['spartrak_videos'] = $rows;

        return $data;
    }

    /**
     * @param array<int|string, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function collectVideoEntries(array $entries): array
    {
        $videos = [];

        foreach ($entries as $entry) {
            if (($entry['media_type'] ?? '') !== ExternalVideoEntryConverter::MEDIA_TYPE_CODE) {
                continue;
            }

            if (empty($entry['value_id'])) {
                continue;
            }

            $videos[] = $entry;
        }

        // Gallery order, so the fieldset lists videos the way the gallery shows
        // them rather than in whatever order the rows came back.
        usort(
            $videos,
            static fn (array $a, array $b): int => ((int) ($a['position'] ?? 0)) <=> ((int) ($b['position'] ?? 0))
        );

        return $videos;
    }

    /**
     * @param array<int, array<string, mixed>> $videos
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function loadChapters(array $videos): array
    {
        $ids = [];

        foreach ($videos as $entry) {
            if (SourceType::isNative((string) ($entry['spartrak_source_type'] ?? ''))) {
                $ids[] = (int) $entry['value_id'];
            }
        }

        return $ids === [] ? [] : $this->chapterResource->loadByValueIds($ids);
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<int, array<string, mixed>> $chapters
     * @return array<string, mixed>
     */
    private function toRow(array $entry, array $chapters): array
    {
        $srcPath = (string) ($entry['spartrak_src_path'] ?? '');
        $isUpload = $srcPath !== '';

        return [
            'value_id' => (int) $entry['value_id'],
            // Derived, not stored: a video with an uploaded path IS an upload.
            // One less column, and one less thing that can contradict the data.
            'source' => $isUpload ? VideoSourceOptions::UPLOAD : VideoSourceOptions::URL,
            'video_url' => $isUpload ? '' : (string) ($entry['video_url'] ?? ''),
            'video_file' => $isUpload ? $this->uploadedVideoValue($srcPath) : [],
            'poster' => $this->posterValue((string) ($entry['file'] ?? '')),
            'title' => (string) ($entry['video_title'] ?? ''),
            'description' => (string) ($entry['video_description'] ?? ''),
            'autoplay' => $this->flag($entry, 'spartrak_autoplay'),
            'loop' => $this->flag($entry, 'spartrak_is_loop'),
            'muted' => $this->flag($entry, 'spartrak_muted'),
            'controls' => $this->flag($entry, 'spartrak_controls'),
            'allow_download' => $this->flag($entry, 'spartrak_allow_download'),
            'is_featured' => (string) (int) ($entry['spartrak_is_featured'] ?? 0),
            'disabled' => (string) (int) ($entry['disabled'] ?? 0),
            'chapters_ar' => $this->formatChapters($chapters, 'title_ar'),
            'chapters_en' => $this->formatChapters($chapters, 'title_en'),
        ];
    }

    /**
     * The shape Magento's fileUploader expects: a single-element array of
     * `{name, url, size}`. Anything else renders as an empty picker, which
     * reads as "the file is gone".
     *
     * @return array<int, array<string, mixed>>
     */
    private function uploadedVideoValue(string $srcPath): array
    {
        return [[
            'name' => basename($srcPath),
            'url' => $this->storage->getUrl($srcPath),
            'size' => $this->fileSize($srcPath),
            'type' => 'video',
        ]];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function posterValue(string $file): array
    {
        if ($file === '') {
            return [];
        }

        return [[
            'name' => basename($file),
            'url' => $this->mediaConfig->getMediaUrl($file),
            // Magento's own gallery posts the catalog-relative path back under
            // this key; the save side reads it to recognise an UNCHANGED
            // poster and leave the existing gallery file alone.
            'file' => $file,
            'size' => 0,
            'type' => 'image',
        ]];
    }

    /**
     * Best effort. A missing size makes the picker look odd; a missing FILE is
     * the merchant's problem to see, not a reason to fail the whole form.
     */
    private function fileSize(string $mediaRelativePath): int
    {
        try {
            $media = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            $relative = $this->storage->getRelativePath($mediaRelativePath);

            return $media->isExist($relative) ? (int) $media->stat($relative)['size'] : 0;
        } catch (\Exception $exception) {
            return 0;
        }
    }

    /**
     * NULL stays an empty string, which is the "Use store default" option's
     * own value — so a video with no opinion reopens showing that it has none.
     *
     * @param array<string, mixed> $entry
     */
    private function flag(array $entry, string $column): string
    {
        $value = $entry[$column] ?? null;

        return $value === null || $value === '' ? '' : (string) (int) $value;
    }

    /**
     * @param array<int, array<string, mixed>> $chapters
     */
    private function formatChapters(array $chapters, string $column): string
    {
        $rows = [];

        foreach ($chapters as $chapter) {
            $rows[] = [
                'start_seconds' => $chapter['start_seconds'],
                'title' => $chapter[$column] ?? '',
            ];
        }

        return $this->chapterParser->format($rows);
    }
}
