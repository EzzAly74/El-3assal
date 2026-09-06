<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\ProductVideo\Model\Video;

use Magento\Framework\DataObject;
use Magento\ProductVideo\Model\Product\Attribute\Media\ExternalVideoEntryConverter;
use Spartrak\Locale\Model\StoreLanguage;
use Spartrak\ProductVideo\Model\Config;
use Spartrak\ProductVideo\Model\Source\SourceType;

/**
 * Turns one media gallery entry into the descriptor a player can be built from.
 *
 * ===========================================================================
 * ONE BUILDER, EVERY SURFACE
 * ===========================================================================
 * Two places on this storefront play a product's video, and there will be
 * more:
 *
 *   the PDP gallery        Block\Product\View\Video
 *   the homepage showcase  Spartrak\Homepage\Block\Section\ProductCarousel
 *
 * They render completely differently — one overlays Fotorama's stage, the
 * other is a card in a rail — but the QUESTION they ask is identical: what is
 * this video, where does it come from, and how should it play? Answering it
 * twice is how the two would end up disagreeing about, say, whether a muted
 * flag beats an autoplay flag (CLAUDE.md section 9).
 *
 * So the answer lives here and the templates only lay it out.
 *
 * ===========================================================================
 * NO QUERIES
 * ===========================================================================
 * Every value this reads is already on the entry: the video columns come from
 * Magento_ProductVideo's join, this module's columns from
 * Plugin\Catalog\Gallery\JoinVideoSettings, and chapters — where they were
 * loaded at all — from Plugin\Catalog\Gallery\LoadVideoChapters. This class
 * touches no resource model and issues nothing, which is what makes it safe to
 * call in a loop over a rail of twelve products.
 */
class DescriptorBuilder
{
    public function __construct(
        private readonly Config $config,
        private readonly Storage $storage,
        private readonly StoreLanguage $storeLanguage
    ) {
    }

    /**
     * @return array<string, mixed>|null null when the entry is not a playable video
     */
    public function build(DataObject $entry, ?int $storeId = null): ?array
    {
        if ($entry->getData('media_type') !== ExternalVideoEntryConverter::MEDIA_TYPE_CODE) {
            return null;
        }

        $sourceType = (string) $entry->getData('spartrak_source_type');

        if ($sourceType === '') {
            // A video added before this module existed. It still shows in the
            // gallery as a poster; it simply has no player. Half a descriptor
            // would be worse than none.
            return null;
        }

        $isNative = SourceType::isNative($sourceType);
        $src = $isNative ? $this->resolveNativeSrc($entry, $sourceType) : '';
        $providerId = (string) $entry->getData('spartrak_provider_id');

        // Nothing playable. A YouTube row with no id, or a file row whose file
        // has gone, is better never offered than offered and broken.
        if ($isNative ? $src === '' : $providerId === '') {
            return null;
        }

        return [
            // ---- identity -------------------------------------------------
            // Matched against a gallery frame by VALUE, never by index — see
            // Block\Product\View\Video for what indexes cost on an RTL store.
            'key' => (string) $entry->getData('video_url'),
            // Present on the PDP, where the gallery block decorates entries
            // with image-role URLs; empty on the collection path, which does
            // not, and where the consumer has the product's own image anyway.
            'poster' => (string) $entry->getData('medium_image_url'),

            // ---- source ---------------------------------------------------
            'type' => $sourceType,
            // For youtube/vimeo this is a VALIDATED ID, never a URL. The
            // player composes the embed address from a hardcoded prefix, so no
            // merchant-supplied string can reach an iframe `src`. See
            // Model\Video\SourceNormalizer.
            'id' => $providerId,
            'src' => $src,
            'mime' => (string) $entry->getData('spartrak_mime_type'),

            // ---- playback -------------------------------------------------
            'autoplay' => $this->resolve($entry, 'spartrak_autoplay', $this->config->getDefaultAutoplay($storeId)),
            'loop' => $this->resolve($entry, 'spartrak_is_loop', $this->config->getDefaultLoop($storeId)),
            'muted' => $this->resolve($entry, 'spartrak_muted', $this->config->getDefaultMuted($storeId)),
            'controls' => $this->resolve($entry, 'spartrak_controls', $this->config->getDefaultControls($storeId)),
            // Only ever offered for a file this storefront serves. A download
            // link on a YouTube embed would be a link to nothing.
            'download' => $isNative
                && $this->resolve($entry, 'spartrak_allow_download', $this->config->getDefaultAllowDownload($storeId)),
            'featured' => (int) $entry->getData('spartrak_is_featured') === 1,

            // ---- content --------------------------------------------------
            'title' => (string) ($entry->getData('video_title') ?: $entry->getData('label')),
            'chapters' => $isNative ? $this->buildChapters($entry) : [],
        ];
    }

    /**
     * An uploaded file resolves through media storage; a direct URL is already
     * absolute and passes through as stored — having been validated as http(s)
     * and as a real video container on the way in.
     */
    private function resolveNativeSrc(DataObject $entry, string $sourceType): string
    {
        if ($sourceType === SourceType::FILE) {
            return $this->storage->getUrl((string) $entry->getData('spartrak_src_path'));
        }

        return (string) $entry->getData('video_url');
    }

    /**
     * Chapter markers, in the store's language.
     *
     * `[seconds, label]` pairs rather than objects: this is the one part of the
     * payload whose size scales with content, and a product with several long
     * videos can carry a hundred of them.
     *
     * @return array<int, array{0: int, 1: string}>
     */
    private function buildChapters(DataObject $entry): array
    {
        $rows = $entry->getData('spartrak_video_chapters');

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $suffix = $this->storeLanguage->getColumnSuffix();
        $fallback = $this->storeLanguage->getFallbackColumnSuffix();
        $chapters = [];

        foreach ($rows as $row) {
            // Falls back to the other language rather than rendering a marker
            // with no name — the rule every bilingual Spartrak field follows.
            $label = trim((string) ($row['title_' . $suffix] ?? ''));

            if ($label === '') {
                $label = trim((string) ($row['title_' . $fallback] ?? ''));
            }

            if ($label === '') {
                continue;
            }

            $chapters[] = [(int) $row['start_seconds'], $label];
        }

        return $chapters;
    }

    /**
     * A per-video override, or the store default when the video has no opinion.
     *
     * NULL is a real value here and means "inherit" — see etc/db_schema.xml for
     * why these columns are nullable rather than NOT NULL DEFAULT 0.
     */
    private function resolve(DataObject $entry, string $key, bool $default): bool
    {
        $value = $entry->getData($key);

        return $value === null || $value === '' ? $default : (int) $value === 1;
    }
}
