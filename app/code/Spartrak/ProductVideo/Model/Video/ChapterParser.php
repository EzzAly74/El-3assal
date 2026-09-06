<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\ProductVideo\Model\Video;

use Magento\Framework\Exception\LocalizedException;

/**
 * Reads a chapter list written the way people already write chapter lists.
 *
 * ===========================================================================
 * THE FORMAT IS YOUTUBE'S, ON PURPOSE
 * ===========================================================================
 *      0:00 Unboxing
 *      1:24 Fitting the pump
 *      12:05 اختبار التشغيل
 *
 * One chapter per line: a timecode, whitespace, then a title. `mm:ss` or
 * `hh:mm:ss`. That is the convention every video platform uses in a
 * description box, so it needs no explaining to a merchant, and it pastes
 * straight in from a YouTube description that already has one.
 *
 * WHY A TEXTAREA RATHER THAN A ROW REPEATER
 * ===========================================================================
 * A dynamicRows UI component would validate per field and look more like a
 * form. It would also need its own UI component, its own JS, and — because
 * Magento's video dialog is a legacy `Widget\Form`, not a UI component — it
 * could not live inside the dialog where the rest of a video's settings are.
 * Chapters would have become a SECOND place in the product form to configure
 * one video. One field that admins already know how to fill in beats two
 * surfaces that do not agree where a video lives.
 *
 * The storage is relational either way (see etc/db_schema.xml): this class is
 * the only thing that ever sees the text format, and it converts to seconds
 * on save. A future dynamicRows UI, or a CSV import, replaces this parser and
 * nothing else.
 *
 * ===========================================================================
 * BILINGUAL, WITH THE SAME RULE AS EVERY OTHER SPARTRAK TEXT COLUMN
 * ===========================================================================
 * The dialog carries an Arabic and an English textarea and this is called once
 * per language, so `title_ar` and `title_en` line up by POSITION. That is the
 * one real constraint of the format and it is stated in the field's own admin
 * note: the two lists must have the same chapters in the same order. Where one
 * language is left empty the storefront falls back to the other, exactly as
 * Spartrak\Locale\Model\StoreLanguage prescribes — a half-translated chapter
 * list shows the other language rather than a blank marker.
 */
class ChapterParser
{
    /** Refuses to store an unbounded list; a marker rail stops being readable long before this. */
    private const MAX_CHAPTERS = 50;

    private const LINE = '/^\s*(?:(\d{1,2}):)?(\d{1,3}):([0-5]\d)\s+(.+?)\s*$/u';

    /**
     * @return array<int, array{start_seconds: int, title: string, sort_order: int}>
     * @throws LocalizedException
     */
    public function parse(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        $chapters = [];
        $lineNumber = 0;

        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $lineNumber++;

            if (trim($line) === '') {
                continue;
            }

            if (preg_match(self::LINE, $line, $m) !== 1) {
                throw new LocalizedException(
                    __(
                        'Chapter line %1 is not in the expected format. Write each chapter as '
                        . '"1:24 Fitting the pump" - a timecode, a space, then the title.',
                        $lineNumber
                    )
                );
            }

            $chapters[] = [
                'start_seconds' => ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3],
                'title' => $m[4],
                'sort_order' => 0,
            ];
        }

        if (count($chapters) > self::MAX_CHAPTERS) {
            throw new LocalizedException(
                __('A video can have at most %1 chapters.', self::MAX_CHAPTERS)
            );
        }

        // Sorted by timecode rather than by the order they were typed, so a
        // chapter added at the end of the box still lands in the right place
        // on the timeline. sort_order is then just the index — the storefront
        // orders by it and never re-sorts.
        usort($chapters, static fn (array $a, array $b): int => $a['start_seconds'] <=> $b['start_seconds']);

        foreach ($chapters as $index => $chapter) {
            $chapters[$index]['sort_order'] = $index;
        }

        return $chapters;
    }

    /**
     * The inverse, for re-populating the admin field from stored rows.
     *
     * @param array<int, array{start_seconds: int|string, title: string|null}> $chapters
     */
    public function format(array $chapters): string
    {
        $lines = [];

        foreach ($chapters as $chapter) {
            $seconds = (int) $chapter['start_seconds'];
            $title = trim((string) ($chapter['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $lines[] = $this->formatTimecode($seconds) . ' ' . $title;
        }

        return implode("\n", $lines);
    }

    /**
     * `mm:ss` under an hour, `h:mm:ss` over it - what a player's own scrubber
     * shows, so the field reads back the way the video looks.
     */
    public function formatTimecode(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainder = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $remainder)
            : sprintf('%d:%02d', $minutes, $remainder);
    }
}
