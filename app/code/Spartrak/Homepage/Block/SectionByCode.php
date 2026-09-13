<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Homepage\Block;

use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Spartrak\Homepage\Block\Section\AbstractSection;
use Spartrak\Homepage\Model\Section;
use Spartrak\Homepage\Model\SectionRenderers;
use Spartrak\Homepage\Model\SectionRepository;

/**
 * ONE dashboard-managed section, mounted on a page that is not the homepage.
 *
 * ===========================================================================
 * WHY THIS EXISTS
 * ===========================================================================
 * Sections were only ever reachable through Block\Sections, which renders the
 * WHOLE homepage in dashboard order. The /brands landing page needs exactly
 * one of them — the "بتدور علي ايه؟" cascading finder — behaving identically
 * to the way it behaves on the homepage: same block, same template, same
 * admin row, same options, same AJAX endpoint, same JS widget.
 *
 * The three ways NOT to do that, and why:
 *
 *   copy the template          two finders to keep in step, and the brief
 *                              asked for "the same one", not "one like it";
 *   render Block\Sections      loads every section and throws all but one
 *                              away — an N+1 in reverse on a page whose
 *                              first requirement is LCP;
 *   hardcode the markup        puts admin-managed content back in a theme,
 *                              which CLAUDE.md section 7 rules out outright.
 *
 * So the section stays exactly where it is and this block simply MOUNTS it by
 * its dashboard `code`. Any future page wanting any section type gets it with
 * one layout node and no new PHP.
 *
 * ===========================================================================
 * IT IS DELIBERATELY CODE-DRIVEN, NOT ID-DRIVEN
 * ===========================================================================
 * `code` is the column db_schema.xml describes as the "stable identifier used
 * by layout/tests" and it carries a UNIQUE constraint. `section_id` is an
 * autoincrement that differs between the developer's database and the
 * client's, so a layout file naming an id would mount the wrong section — or
 * nothing — on the machine that matters.
 *
 * ===========================================================================
 * WHAT HAPPENS WHEN THE SECTION IS DISABLED OR GONE
 * ===========================================================================
 * Nothing renders. Not an empty shell, not a heading with a blank card under
 * it — the same contract AbstractSection::_toHtml() already enforces, for the
 * same CLS reason. Turning the finder off in the dashboard therefore removes
 * it from the homepage AND from this page in one action, which is the
 * behaviour a merchant expects from one switch.
 */
class SectionByCode extends Template implements IdentityInterface
{
    /**
     * Matches Block\Sections. Content changes do NOT wait for it — they
     * arrive through getIdentities() below — so this only decides how long an
     * entry survives when nothing has changed. A day is Magento's own default
     * full-page-cache TTL, so the block entry and the page entry containing it
     * expire on the same clock.
     */
    private const CACHE_LIFETIME = 86400;

    /**
     * In-request memo, NOT a cache.
     *
     * The block tree asks for the section twice — once to render it, once to
     * report its identities — and `false` distinguishes "not looked up yet"
     * from "looked up, and there is none", so a disabled section costs one
     * query per request rather than two. Same reasoning Model\SectionList
     * records for its own memo.
     *
     * @var Section|null|false
     */
    private $section = false;

    public function __construct(
        Context $context,
        private readonly SectionRepository $sectionRepository,
        private readonly SectionRenderers $sectionRenderers,
        private readonly HttpContext $httpContext,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * The dashboard `code` of the section to mount, from the layout argument.
     */
    public function getSectionCode(): string
    {
        return trim((string) $this->getData('section_code'));
    }

    /**
     * The section this block mounts, or null when it is missing or disabled.
     */
    private function getSection(): ?Section
    {
        if ($this->section !== false) {
            return $this->section;
        }

        $code = $this->getSectionCode();

        if ($code === '') {
            // A layout node that forgot its argument. Logged rather than
            // fatal, and loudly enough to be findable — it is a deploy-time
            // mistake, not a content state.
            $this->_logger->warning('Spartrak_Homepage: SectionByCode mounted with no section_code argument.');

            return $this->section = null;
        }

        return $this->section = $this->sectionRepository->getByCode($code);
    }

    /**
     * The rendered section, or '' when there is nothing to render.
     */
    public function renderSection(): string
    {
        $section = $this->getSection();

        if ($section === null) {
            // Disabled, deleted, or never configured. A normal state — see
            // the class header. No log line: this is not a fault.
            return '';
        }

        return $this->renderResolved($section, $this->getSectionCode());
    }

    private function renderResolved(Section $section, string $code): string
    {
        $type = (string) $section->getType();
        $renderer = $this->sectionRenderers->get($type);

        if ($renderer === null) {
            $this->_logger->warning(
                'Spartrak_Homepage: section "' . $code . '" has unknown type "' . $type . '".'
            );

            return '';
        }

        [$blockClass, $template] = $renderer;

        try {
            /** @var AbstractSection $child */
            $child = $this->getLayout()->createBlock($blockClass);
            $child->setTemplate($template);
            $child->setSection($section);
            // Position 0 is "first on the page", which is what unlocks
            // fetchpriority on a section that paints an above-the-fold image
            // (AbstractSection::isAboveFold). This block mounts a section that
            // is NOT first on its host page — the brand grid is — so it is
            // given a non-zero position and stays lazy. Spending the priority
            // twice on one page spends it on nothing (CLAUDE.md section 11).
            $child->setPosition(1);

            return $child->toHtml();
        } catch (\Exception $exception) {
            $this->_logger->error(
                'Spartrak_Homepage: section "' . $code . '" failed to render: ' . $exception->getMessage(),
                ['exception' => $exception]
            );

            return '';
        }
    }

    /**
     * An AbstractBlock only consults the block cache when a lifetime is set —
     * `_loadCache()` returns false while getCacheLifetime() is null, so tags
     * alone would do nothing here. See Block\Sections for the measurement
     * that established this.
     */
    protected function getCacheLifetime()
    {
        return self::CACHE_LIFETIME;
    }

    /**
     * @return array<int, mixed>
     */
    public function getCacheKeyInfo(): array
    {
        return [
            'SPARTRAK_SECTION_BY_CODE',
            $this->getSectionCode(),
            $this->_storeManager->getStore()->getId(),
            // Same two axes the full-page cache varies on, read from
            // Http\Context rather than the customer session — touching the
            // session from a cached block is how a block quietly becomes
            // uncacheable.
            $this->httpContext->getValue(CustomerContext::CONTEXT_GROUP),
            $this->httpContext->getValue(CustomerContext::CONTEXT_AUTH),
        ];
    }

    /**
     * The section's own tag, so saving it in the dashboard drops every cached
     * page that showed it — this one included, not just the homepage.
     *
     * Resolved from the row rather than assumed: an absent section has no
     * identity to publish, and tagging the page with a guess would keep it
     * pinned to something that does not exist.
     *
     * @return string[]
     */
    public function getIdentities(): array
    {
        $section = $this->getSection();

        return $section === null ? [] : $section->getIdentities();
    }
}
