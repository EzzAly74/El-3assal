<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Payment\Model;

use Magento\Framework\View\Asset\Repository as AssetRepository;

/**
 * The catalogue of payment brand marks this store can display.
 *
 * ===========================================================================
 * WHY THE MARKS LIVE IN THIS MODULE AND NOT IN THE THEME
 * ===========================================================================
 * The theme owns how a payment row LOOKS - its border, padding, type ramp and
 * the circular tile the mark sits in. This module owns WHICH marks exist and
 * which payment method each one belongs to, and that is the half a merchant
 * edits. Keeping the files next to the mapping means enabling a new brand is
 * one module, not a module plus a theme deploy.
 *
 * ===========================================================================
 * THESE ARE THIRD-PARTY TRADEMARKS
 * ===========================================================================
 * Every file here was exported from the Figma source at its original colours
 * and is rendered through an <img>, never a CSS mask. That is deliberate: a
 * mask would flatten Visa, Meeza and Vodafone Cash to a single tint, which is
 * both wrong visually and a misuse of someone else's trademark. Nothing in
 * this module or the theme may re-tint them.
 *
 * The rasters were downscaled to 88px on their shorter edge - twice the 44px
 * tile they render in, so they stay sharp at 2x DPR without shipping the
 * 1400px originals Figma exports.
 *
 * ===========================================================================
 * WHY A CLASS AND NOT A CONFIG LIST
 * ===========================================================================
 * A merchant chooses which marks appear on which row; they do not upload new
 * trademarks through the admin, because a mark has to be the real artwork from
 * the brand owner. So the SET is code (it changes when a designer adds an
 * asset) and the MAPPING is configuration (it changes when a merchant enables
 * a method). See Spartrak\Payment\Model\PresentationCatalog for the other half.
 */
class BrandMarks
{
    /**
     * key => [file, label]
     *
     * `label` is the alt text. It is a real brand name rather than a decorative
     * empty string because the row's own title says "pay by bank card" and the
     * marks are what tell a shopper WHICH cards - that is information, not
     * decoration, so a screen reader must get it.
     *
     * @var array<string, array{file: string, label: string}>
     */
    private const MARKS = [
        'mastercard'    => ['file' => 'mastercard.svg',     'label' => 'Mastercard'],
        'visa'          => ['file' => 'visa.svg',           'label' => 'Visa'],
        'meeza'         => ['file' => 'meeza.png',          'label' => 'Meeza'],
        'etisalat_cash' => ['file' => 'etisalat-cash.png',  'label' => 'Etisalat Cash'],
        'orange_cash'   => ['file' => 'orange-cash.png',    'label' => 'Orange Cash'],
        'vodafone_cash' => ['file' => 'vodafone-cash.png',  'label' => 'Vodafone Cash'],
        'instapay'      => ['file' => 'instapay.png',       'label' => 'InstaPay'],
        'tru'           => ['file' => 'tru.jpg',            'label' => 'Tru'],
        'souhoola'      => ['file' => 'souhoola.png',       'label' => 'Souhoola'],
        'valu'          => ['file' => 'valu.jpg',           'label' => 'valU'],
    ];

    public function __construct(
        private readonly AssetRepository $assetRepository
    ) {
    }

    /**
     * @return array<string, string> key => label, for an admin multiselect
     */
    public function getOptionLabels(): array
    {
        return array_map(static fn (array $m): string => $m['label'], self::MARKS);
    }

    /**
     * Resolve a list of keys to renderable marks.
     *
     * Unknown keys are dropped rather than throwing: a stored configuration can
     * outlive the asset it names (someone removes a brand), and a checkout that
     *500s because one logo went missing is a far worse outcome than a row that
     * renders with one mark fewer.
     *
     * @param string[] $keys
     * @return array<int, array{url: string, label: string}>
     */
    public function resolve(array $keys): array
    {
        $out = [];

        foreach ($keys as $key) {
            if (!isset(self::MARKS[$key])) {
                continue;
            }

            $out[] = [
                'url'   => $this->assetRepository->getUrl(
                    'Spartrak_Payment::images/payment/' . self::MARKS[$key]['file']
                ),
                'label' => self::MARKS[$key]['label'],
            ];
        }

        return $out;
    }

    public function has(string $key): bool
    {
        return isset(self::MARKS[$key]);
    }
}
