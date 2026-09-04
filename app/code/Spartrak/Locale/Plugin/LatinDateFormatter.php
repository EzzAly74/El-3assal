<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Locale\Plugin;

use Magento\Framework\Stdlib\DateTime\Intl\DateFormatterFactory;
use Spartrak\Locale\Model\Numbering;

/**
 * SEAM 4 — DATES.
 *
 * ===========================================================================
 * WHY THE FIRST THREE SEAMS DID NOT COVER THIS
 * ===========================================================================
 * Seams 1-3 (see the module README) all pin the NUMBER formatter. Dates go
 * through a different ICU class entirely — \IntlDateFormatter — and it takes
 * its numbering system from the same locale, so in `ar_EG` every date Magento
 * renders came out as "٣٠ يوليو ٢٠٢٦" and every time as "٠٨:١٠".
 *
 * That was invisible for as long as the storefront printed no dates. It stopped
 * being invisible with the My Account section: the order card, the order page
 * header and the order-details panel all print an estimated arrival and an
 * order date, which is three Arabic-Indic dates per screen on the one page a
 * shopper checks most often.
 *
 * ===========================================================================
 * WHY THIS SEAM, AND NOT A TRANSLITERATION OF THE OUTPUT
 * ===========================================================================
 * The tempting fix is to map ٠-٩ back to 0-9 on the formatted string. It is
 * also the wrong shape: it processes every date twice, it would silently
 * rewrite any Arabic-Indic digit that legitimately belongs to CONTENT (a plate
 * number, say, which BUSINESS.md section 12 writes as `م ص ر ١٢٣٤`), and it
 * treats the symptom rather than telling ICU what was wanted.
 *
 * Magento\Framework\Stdlib\DateTime\Intl\DateFormatterFactory::create() is the
 * single point at which every \IntlDateFormatter in the platform is built —
 * Timezone::formatDateTime(), ::formatDate(), ::getDateFormat(),
 * ::getTimeFormat() and ::getDateTimeFormat() all go through it. Rewriting the
 * locale there applies the same one ICU keyword the other three seams apply,
 * at the same kind of place: where the formatter is constructed.
 *
 * ===========================================================================
 * WHY IT DOES NOT BREAK THE FACTORY'S OWN LOCALE LOGIC
 * ===========================================================================
 * The factory compares `$locale` against its CUSTOM_DATE_FORMATS map, whose
 * only key is `ar_SA`. An `ar_SA` store would stop matching that entry once the
 * keyword is appended — so `ar_SA` is passed through untouched. Every other
 * locale, `ar_EG` included, never matched that map to begin with, so there is
 * nothing to preserve.
 *
 * Numbering::latin() also leaves alone any locale that already names a
 * numbering system, so an explicit caller still wins.
 */
class LatinDateFormatter
{
    /**
     * The one locale whose date PATTERN the factory special-cases. Left as-is
     * so appending a keyword cannot cost it that pattern.
     */
    private const PRESERVE = ['ar_SA'];

    /**
     * @param DateFormatterFactory $subject
     * @param string $locale
     * @param int $dateStyle
     * @param int $timeStyle
     * @param string|null $timeZone
     * @param bool $useFourDigitsForYear
     * @return array{0: string, 1: int, 2: int, 3: string|null, 4: bool}
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeCreate(
        DateFormatterFactory $subject,
        string $locale,
        int $dateStyle,
        int $timeStyle,
        ?string $timeZone = null,
        bool $useFourDigitsForYear = true
    ): array {
        if (!in_array($locale, self::PRESERVE, true)) {
            $locale = (string) Numbering::latin($locale);
        }

        return [$locale, $dateStyle, $timeStyle, $timeZone, $useFourDigitsForYear];
    }
}
