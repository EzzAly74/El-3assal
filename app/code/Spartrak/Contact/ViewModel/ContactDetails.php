<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Contact\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Spartrak\Contact\Model\Config;

/**
 * Read-only projection of the contact details for the storefront dialog.
 *
 * The template gets rows of plain scalars and nothing else: no config paths, no
 * knowledge of which field is a phone and which is an email, and no string
 * munging of its own (CLAUDE.md §8 — .phtml carries no business logic).
 *
 * A ROW WITH NO VALUE DOES NOT EXIST. Every getter below omits an unset field
 * rather than returning an empty string for the template to test, so the dialog
 * cannot render a labelled card with nothing under the label. That is also what
 * makes the admin comment "leave a field empty to drop its row" true.
 *
 * FPC note: every value is store-scoped configuration and full-page cache is
 * already keyed by store, so the dialog's block stays cacheable. Nothing
 * customer-specific passes through here.
 */
class ContactDetails implements ArgumentInterface
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * The four "Channels" rows — Figma I1179:24007;1098:25419 — in the order
     * the design lists them.
     *
     * Each row is:
     *     label   string, already translated
     *     values  a list of ['text' => string, 'href' => string, 'unbreakable' => bool]
     *
     * `values` is a LIST and not a single value because the showroom row
     * carries two numbers on one line (Figma prints them separated by a
     * middot). Modelling every row the same way is what keeps the template a
     * single loop instead of a special case for that one row.
     *
     * `unbreakable` says the value is a telephone number. A phone number is
     * one token to a reader even though it is written with spaces in it, and
     * MEASURED at 375px the showroom row otherwise wrapped between "+20 2 2576"
     * and "1246" — two half-numbers on two lines. It is a fact about the datum
     * rather than a style choice, which is why it is stated here and not
     * inferred from the href by the template.
     *
     * @return array<int, array{label: string, values: array<int, array{text: string, href: string, unbreakable: bool}>}>
     */
    public function getChannels(): array
    {
        $rows = [];

        $whatsApp = $this->config->getWhatsApp();

        if ($whatsApp !== '') {
            $rows[] = [
                'label' => (string) __('WhatsApp'),
                // wa.me takes the international number with no plus and no
                // separators. `false` keeps the leading + off.
                'values' => [[
                    'text' => $whatsApp,
                    'href' => 'https://wa.me/' . $this->digits($whatsApp, false),
                    'unbreakable' => true,
                ]],
            ];
        }

        $hotline = $this->config->getHotline();

        if ($hotline !== '') {
            $rows[] = [
                'label' => (string) __('Hotline'),
                'values' => [[
                    'text' => $hotline,
                    'href' => 'tel:' . $this->digits($hotline),
                    'unbreakable' => true,
                ]],
            ];
        }

        $showroom = $this->config->getShowroomPhones();

        if ($showroom !== []) {
            $rows[] = [
                'label' => (string) __('Showroom phone'),
                'values' => array_map(
                    fn (string $number): array => [
                        'text' => $number,
                        'href' => 'tel:' . $this->digits($number),
                        'unbreakable' => true,
                    ],
                    $showroom
                ),
            ];
        }

        $email = $this->config->getEmail();

        if ($email !== '') {
            $rows[] = [
                'label' => (string) __('Email'),
                // NOT unbreakable: an address is a single long token with no
                // spaces in it, and omar@elassalparts.com at 16px does not fit
                // a 320px phone — it is the one value that MUST be allowed to
                // break. See the card-value rule in components/_dialog.less.
                'values' => [['text' => $email, 'href' => 'mailto:' . $email, 'unbreakable' => false]],
            ];
        }

        return $rows;
    }

    /**
     * The hotline on its own, for the footer's mixed-type "Hotline 12384" row
     * (Figma 693:45700), which is not one of the cards above and cannot use
     * getChannels().
     */
    public function getHotline(): string
    {
        return $this->config->getHotline();
    }

    public function getEmail(): string
    {
        return $this->config->getEmail();
    }

    /**
     * The one number the footer's bottom bar prints beside a handset icon.
     *
     * The first SHOWROOM line, not the hotline: the hotline already has its own
     * row a few centimetres above it in the same footer, and printing it twice
     * says less than printing two different ways to call. It falls back to the
     * hotline, and then to WhatsApp, so a store that has only filled in one
     * number still gets a working row rather than a blank one.
     */
    public function getPrimaryPhone(): string
    {
        $showroom = $this->config->getShowroomPhones();

        return $showroom[0] ?? ($this->config->getHotline() ?: $this->config->getWhatsApp());
    }

    /**
     * `tel:` for any of the numbers above, with the separators a human reads
     * them by removed — a space is not part of a telephone URI.
     */
    public function getTelHref(string $number): string
    {
        return 'tel:' . $this->digits($number);
    }

    public function getAddress(): string
    {
        return $this->config->getAddress();
    }

    public function getDirectionsUrl(): string
    {
        return $this->config->getDirectionsUrl();
    }

    public function getHours(): string
    {
        return $this->config->getHours();
    }

    /**
     * True when there is something to show.
     *
     * The dialog is wired into the header and the footer on every page, so a
     * store that has cleared every field must render no dialog at all rather
     * than an empty panel with a title and a close button.
     */
    public function hasContent(): bool
    {
        return $this->getChannels() !== []
            || $this->getAddress() !== ''
            || $this->getHours() !== '';
    }

    /**
     * A number reduced to what a `tel:` or wa.me URL accepts.
     *
     * Spaces, dashes and brackets are how a human reads a phone number and are
     * not part of it; the plus is, for `tel:`, and is not for wa.me. Anything
     * else an admin may have typed is dropped rather than escaped into the URL.
     */
    private function digits(string $number, bool $keepPlus = true): string
    {
        $digits = preg_replace('/[^0-9]/', '', $number) ?? '';

        return $keepPlus && str_starts_with(ltrim($number), '+') ? '+' . $digits : $digits;
    }
}
