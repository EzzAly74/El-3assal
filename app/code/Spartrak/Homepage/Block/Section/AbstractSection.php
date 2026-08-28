<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Block\Section;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Spartrak\Homepage\Model\LocaleContext;
use Spartrak\Homepage\Model\Section;

/**
 * What every homepage section shares: its row, its title, its position, and
 * its "view all" link.
 *
 * Everything here is section-type-agnostic on purpose — it is the reusable
 * half the brief asks for ("Prefer reusable entities/services/view
 * models/templates/components"), so a banner section, a tile section and a
 * product carousel all get identical title and header behaviour without three
 * copies of it.
 */
abstract class AbstractSection extends Template
{
    public function __construct(
        Context $context,
        // protected, not private: ProductPromoCarousel resolves its own
        // per-locale promo fields through the same resolver, and injecting a
        // second copy of it would be two constructor args of one type for one
        // shared instance.
        protected readonly LocaleContext $localeContext,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function setSection(Section $section): self
    {
        return $this->setData('section', $section);
    }

    public function getSection(): ?Section
    {
        $section = $this->getData('section');

        return $section instanceof Section ? $section : null;
    }

    /**
     * Where this section sits on the page. 0 is the first section.
     */
    public function getPosition(): int
    {
        return (int) $this->getData('position');
    }

    /**
     * True for the section that owns the page's largest above-the-fold paint.
     *
     * Only the FIRST section may claim it. Everything else lazy-loads, and
     * nothing else is allowed to set fetchpriority="high" — preload priority
     * is a scarce resource and spending it twice spends it on nothing
     * (CLAUDE.md section 11).
     */
    public function isAboveFold(): bool
    {
        return $this->getPosition() === 0;
    }

    /**
     * The current store language's value of a per-locale section column.
     *
     * Every bilingual field on a section follows the same `<base>_ar` /
     * `<base>_en` shape, so ONE resolver serves all of them: title, subtitle,
     * and the promo panel's badge, headline, body and artwork. This used to be
     * written out twice - once here for the title and once again inside
     * ProductPromoCarousel - which is exactly the duplication the brief rules
     * out.
     *
     * Falls back to the other language rather than returning nothing. A half
     * translated dashboard should leave a section looking unfinished, not
     * leave it with an empty <h2>, which is a genuine accessibility defect
     * rather than a cosmetic gap.
     */
    protected function localised(string $base): string
    {
        $section = $this->getSection();

        if ($section === null) {
            return '';
        }

        $value = trim((string) $section->getData(
            $base . '_' . $this->localeContext->getColumnSuffix()
        ));

        if ($value !== '') {
            return $value;
        }

        return trim((string) $section->getData(
            $base . '_' . $this->localeContext->getFallbackColumnSuffix()
        ));
    }

    /**
     * The section heading in the current store's language.
     */
    public function getTitle(): string
    {
        return $this->localised('title');
    }

    /**
     * The optional line under the heading, in the current store's language.
     *
     * Only the cascading finder draws one today (Figma 595:15847), but it is
     * declared here rather than on that block because it is section data like
     * any other - a second section that wants a standfirst gets it for free.
     */
    public function getSubtitle(): string
    {
        return $this->localised('subtitle');
    }

    /**
     * The "مشاهدة الكل" / "View all" destination, or '' when there is none.
     *
     * Precedence: the dashboard's explicit override first, then the section's
     * source category. Returns '' when neither is set, and the template then
     * omits the link entirely instead of rendering a dead control.
     */
    public function getViewAllUrl(): string
    {
        $section = $this->getSection();

        if ($section === null) {
            return '';
        }

        $override = trim((string) $section->getLinkUrl());

        if ($override !== '') {
            return $override;
        }

        return $this->getCategoryUrl();
    }

    /**
     * Resolved in the subclass that actually has a category to resolve.
     */
    protected function getCategoryUrl(): string
    {
        return '';
    }

    /**
     * The shared section header — title on one side, "view all" on the other.
     *
     * Figma renders the identical header on every section that has one
     * (595:15068, 595:15117, 595:14587, 595:14822), so it is authored ONCE in
     * section/head.phtml and every section template pulls it in from here.
     *
     * fetchView() rather than a child block on purpose: it renders that
     * template against THIS block's own context, so the header costs no extra
     * block instantiation per section and still keeps its markup in a .phtml
     * a theme can override.
     */
    public function getHeadHtml(): string
    {
        if ($this->getTitle() === '' && $this->getViewAllUrl() === '' && !$this->showsCarouselNav()) {
            return '';
        }

        return $this->fetchView($this->getTemplateFile('Spartrak_Homepage::section/head.phtml'));
    }

    /**
     * Whether the shared header carries this section's prev/next buttons.
     *
     * Figma puts them there for every product rail (595:15118, 595:14588,
     * 595:14823) and NOT for the tile section, which keeps its arrows over
     * the artwork instead. Default false; the carousel block turns it on only
     * when there is actually more than one item to move between.
     */
    public function showsCarouselNav(): bool
    {
        return false;
    }

    /**
     * Stable id for the section's <h2>, used by aria-labelledby.
     *
     * Built from the dashboard `code` because that column is unique and
     * admin-controlled — two sections can share a title, so a title-derived id
     * could collide and silently break the labelling.
     */
    public function getHeadingId(): string
    {
        return 'spartrak-home-' . preg_replace(
            '/[^a-z0-9_-]+/',
            '-',
            strtolower((string) $this->getSection()?->getCode())
        ) . '-title';
    }

    /**
     * A section that resolved to nothing renders NOTHING — not an empty
     * shell, not a heading with a blank rail under it.
     *
     * The brief's verification list calls for this explicitly ("Disabled
     * items/sections do not render"), and it matters for CLS too: an empty
     * section that still reserves its header height pushes everything below
     * it down for no content.
     */
    abstract public function hasContent(): bool;

    /**
     * Suppresses the whole block when there is nothing to show, so callers
     * never need to test before rendering.
     */
    protected function _toHtml(): string
    {
        if ($this->getSection() === null || !$this->hasContent()) {
            return '';
        }

        return parent::_toHtml();
    }
}
