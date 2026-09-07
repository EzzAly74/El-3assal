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
use Spartrak\ProductVideo\Model\ResourceModel\VideoChapter;
use Spartrak\ProductVideo\Model\ResourceModel\VideoSettings;
use Spartrak\ProductVideo\Model\Source\SourceType;
use Spartrak\ProductVideo\Model\Video\ChapterParser;
use Spartrak\ProductVideo\Model\Video\SourceNormalizer;


/**
 * Persists this module's per-video settings and chapters when a product saves.
 *
 * ===========================================================================
 * WHY THIS HOOKS WHERE MAGENTO'S OWN VIDEO PLUGIN HOOKS
 * ===========================================================================
 * Magento\Catalog\Model\Product\Gallery\CreateHandler is where a media gallery
 * entry gets its value_id. Magento_ProductVideo plugs the same method to write
 * its own video row (provider, url, title, description) and this writes the
 * columns that module has nowhere to put. Two plugins on one seam, each
 * owning its own table, both inside the product save transaction.
 *
 * The extra fields arrive on the same rows. They are put there by
 * Plugin\Catalog\Gallery\BuildVideoEntries, which translates the product
 * form's inline Product Videos fieldset into gallery entries a moment earlier
 * in the same save — so by the time this runs, a video looks exactly like one
 * Magento's own modal would have produced, and this only has to write the
 * columns Magento has nowhere to put.
 *
 * ===========================================================================
 * WHAT IT COSTS
 * ===========================================================================
 * A product with no videos: nothing. A product with videos: one
 * insertOnDuplicate for every setting row at once, one DELETE + INSERT per
 * video that has chapters, and one scoped DELETE to clear rows for entries
 * that stopped being videos. Nothing loops a query.
 *
 * ===========================================================================
 * VALIDATION FAILURES STOP THE SAVE
 * ===========================================================================
 * A bad URL or a malformed chapter list throws, which aborts the product save
 * and shows the merchant the message. That is deliberate: silently dropping a
 * video the admin believes they just configured is worse than refusing the
 * save, and the alternative — storing an unnormalised URL "for now" — is how
 * an unvalidated string ends up in an iframe six months later.
 */
class SaveVideoSettings
{
    public function __construct(
        private readonly VideoSettings $settingsResource,
        private readonly VideoChapter $chapterResource,
        private readonly SourceNormalizer $normalizer,
        private readonly ChapterParser $chapterParser
    ) {
    }

    /**
     * @return Product
     * @throws LocalizedException
     */
    public function afterExecute(CreateHandler $subject, Product $result, ...$args)
    {
        $attributeCode = $subject->getAttribute()->getAttributeCode();
        $mediaData = $result->getData($attributeCode);

        if (empty($mediaData['images']) || !is_array($mediaData['images'])) {
            return $result;
        }

        $settingRows = [];
        $chapterRows = [];
        $allValueIds = [];

        foreach ($mediaData['images'] as $entry) {
            $allValueIds[] = (int) ($entry['value_id'] ?? 0);

            if (($entry['media_type'] ?? '') !== ExternalVideoEntryConverter::MEDIA_TYPE_CODE) {
                continue;
            }

            $valueId = (int) ($entry['value_id'] ?? 0);

            // A row flagged `removed` still travels through the save so core
            // can delete it. Writing settings for it would resurrect nothing
            // useful and would race the cascade.
            if ($valueId <= 0 || !empty($entry['removed'])) {
                continue;
            }

            // The path is already final: Plugin\Catalog\Gallery\BuildVideoEntries
            // promoted the file out of the staging directory before this row
            // was built, because the row's video_url had to be its final URL.
            $source = $this->normalizer->normalize(
                (string) ($entry['video_url'] ?? ''),
                (string) ($entry['spartrak_video_path'] ?? '')
            );

            $settingRows[$valueId] = $source + [
                'autoplay' => $this->override($entry, 'spartrak_video_autoplay'),
                'is_loop' => $this->override($entry, 'spartrak_video_loop'),
                'muted' => $this->override($entry, 'spartrak_video_muted'),
                'controls' => $this->override($entry, 'spartrak_video_controls'),
                'allow_download' => $this->override($entry, 'spartrak_video_download'),
                'is_featured' => empty($entry['spartrak_video_featured']) ? 0 : 1,
            ];

            $chapterRows[$valueId] = $this->buildChapters($entry, $source['source_type']);
        }

        $this->settingsResource->deleteExcept($allValueIds, array_keys($settingRows));
        $this->settingsResource->saveMany($settingRows);

        foreach ($chapterRows as $valueId => $chapters) {
            $this->chapterResource->replaceForValueId($valueId, $chapters);
        }

        return $result;
    }

    /**
     * Reads one tri-state playback override off the posted row.
     *
     * NULL means "inherit the store default" and is a real, distinct answer —
     * see etc/db_schema.xml for why these columns are nullable. The dialog
     * posts an empty string for inherit, "0" for off and "1" for on.
     */
    private function override(array $entry, string $field): ?int
    {
        $value = $entry[$field] ?? '';

        if ($value === '' || $value === null) {
            return null;
        }

        return (int) $value === 1 ? 1 : 0;
    }

    /**
     * @return array<int, array{start_seconds: int, title_en: ?string, title_ar: ?string, sort_order: int}>
     * @throws LocalizedException
     */
    private function buildChapters(array $entry, string $sourceType): array
    {
        // Chapters are a native-player feature. Storing them for a YouTube or
        // Vimeo row would store something nothing can ever read — see
        // Model\Source\SourceType::NATIVE_TYPES.
        if (!SourceType::isNative($sourceType)) {
            return [];
        }

        $english = $this->chapterParser->parse((string) ($entry['spartrak_video_chapters_en'] ?? ''));
        $arabic = $this->chapterParser->parse((string) ($entry['spartrak_video_chapters_ar'] ?? ''));

        // The two lists line up BY POSITION, which is the one real constraint
        // of the textarea format and is stated in the field's own admin note.
        // Whichever list is longer sets the chapter count, so filling in only
        // one language still produces a complete set of markers.
        $count = max(count($english), count($arabic));
        $chapters = [];

        for ($index = 0; $index < $count; $index++) {
            $en = $english[$index] ?? null;
            $ar = $arabic[$index] ?? null;
            $primary = $en ?? $ar;

            if ($primary === null) {
                continue;
            }

            $chapters[] = [
                // The timecode comes from whichever list HAS this row, with
                // English winning when both do. A merchant who typed different
                // timecodes for the same chapter meant one of them, and the
                // marker can only be in one place.
                'start_seconds' => $primary['start_seconds'],
                'title_en' => $en['title'] ?? null,
                'title_ar' => $ar['title'] ?? null,
                'sort_order' => $index,
            ];
        }

        return $chapters;
    }
}
