<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\ProductVideo\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * "Where is this video?" — the product form's source dropdown.
 *
 * TWO ANSWERS, NOT FOUR. `Model\Source\SourceType` has four values, but two of
 * them — youtube and vimeo — are things the SERVER works out from a pasted
 * link, not things a merchant should have to classify. Offering them here
 * would offer a way to get it wrong: a video labelled "YouTube" whose URL is an
 * MP4 is a broken player, and no reading of that mistake helps anybody.
 *
 * So the merchant answers the only question they actually know the answer to —
 * "is the file mine, or is it somewhere else?" — and
 * Model\Video\SourceNormalizer decides the rest, once, on save.
 */
class VideoSourceOptions implements OptionSourceInterface
{
    public const URL = 'url';

    public const UPLOAD = 'upload';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::URL, 'label' => __('Video URL (YouTube, Vimeo or CDN)')],
            ['value' => self::UPLOAD, 'label' => __('Upload a file')],
        ];
    }
}
