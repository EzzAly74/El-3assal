<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\ProductVideo\Plugin\Catalog\Gallery;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Gallery\ReadHandler;
use Spartrak\ProductVideo\Model\ResourceModel\VideoChapter;
use Spartrak\ProductVideo\Model\Source\SourceType;

/**
 * Attaches chapter markers to the gallery entries that can use them.
 *
 * ===========================================================================
 * THE ONE QUERY THIS MODULE ADDS, AND THE THREE CONDITIONS THAT AVOID IT
 * ===========================================================================
 * Everything else arrives joined into the gallery's own select (see
 * JoinVideoSettings). Chapters cannot: they are one-to-many, and joining them
 * would multiply every gallery row by its chapter count and make Magento's own
 * de-duplication the caller's problem.
 *
 * So they get one `WHERE value_id IN (...)` — and it is skipped entirely
 * unless all three of these hold:
 *
 *   1. the product has media gallery entries at all;
 *   2. at least one of them is a video;
 *   3. at least one of those is a NATIVE video — chapters are meaningless for
 *      a YouTube or Vimeo embed, which carries its own.
 *
 * A product with no videos, or with only YouTube videos, therefore adds
 * NOTHING. A product with ten native videos and a hundred chapters adds one
 * query. The cost does not scale with either count.
 *
 * ===========================================================================
 * WHY ONLY THIS PATH, AND NOT THE COLLECTION PATH TOO
 * ===========================================================================
 * `ReadHandler::execute()` is the single-product load — the PDP. The rails on
 * the homepage go through `Collection::addMediaGalleryData()` instead, which
 * never calls this method.
 *
 * That asymmetry is deliberate rather than an oversight. A chapter rail is a
 * player control, and the homepage showcase does not render one: it shows a
 * poster and mounts a player on click. Fetching chapters for twelve products
 * to render none of them would be a query bought for nothing on the page whose
 * LCP matters most.
 */
class LoadVideoChapters
{
    public function __construct(
        private readonly VideoChapter $chapterResource
    ) {
    }

    /**
     * @return Product
     */
    public function afterExecute(ReadHandler $subject, Product $result, ...$args)
    {
        $attributeCode = $subject->getAttribute()->getAttributeCode();
        $mediaData = $result->getData($attributeCode);

        if (empty($mediaData['images']) || !is_array($mediaData['images'])) {
            return $result;
        }

        $valueIds = $this->collectNativeVideoIds($mediaData['images']);

        if ($valueIds === []) {
            return $result;
        }

        $chapters = $this->chapterResource->loadByValueIds($valueIds);

        if ($chapters === []) {
            return $result;
        }

        foreach ($mediaData['images'] as $key => $entry) {
            $valueId = (int) ($entry['value_id'] ?? 0);

            if (isset($chapters[$valueId])) {
                $mediaData['images'][$key]['spartrak_video_chapters'] = $chapters[$valueId];
            }
        }

        $result->setData($attributeCode, $mediaData);

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $images
     * @return int[]
     */
    private function collectNativeVideoIds(array $images): array
    {
        $ids = [];

        foreach ($images as $entry) {
            // The source type is already on the row — joined in by
            // JoinVideoSettings — so deciding this costs no query of its own.
            if (!SourceType::isNative((string) ($entry['spartrak_source_type'] ?? ''))) {
                continue;
            }

            $valueId = (int) ($entry['value_id'] ?? 0);

            if ($valueId > 0) {
                $ids[] = $valueId;
            }
        }

        return $ids;
    }
}
