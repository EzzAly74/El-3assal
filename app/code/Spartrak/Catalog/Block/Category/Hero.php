<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Catalog\Block\Category;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Block\Category\View as CategoryView;
use Spartrak\Catalog\Setup\Patch\Data\AddCategoryHeroAttributes as HeroAttributes;
use Spartrak\Catalog\ViewModel\CategoryImage;

/**
 * The category page's hero band — Figma 488:12966.
 *
 * A full-bleed 1440x318 image carrying the category's headline and blurb,
 * bottom / inline-start, over a scrim (Figma 488:12967 / 488:12969).
 *
 * ===========================================================================
 * WHY THIS EXTENDS Magento\Catalog\Block\Category\View AND DECLARES NO
 * CONSTRUCTOR
 * ===========================================================================
 * Everything this block needs from the framework — the current category, the
 * cache identities that let the full-page-cache entry drop when that category
 * is saved — is already on that class, the same one Magento and Porto mount
 * for every other category block on this page. Extending it and adding NO
 * constructor means:
 *
 *   - getCurrentCategory() and getIdentities() are core's, not a
 *     reimplementation, so they keep working if core changes them;
 *   - Magento\Framework\Registry, which is deprecated, is never referenced by
 *     this module even though the category ultimately comes from it;
 *   - there is no constructor signature here to drift out of step with the
 *     parent's on an upgrade. DI resolves the parent's own arguments.
 *
 * The one collaborator this block DOES need — the category-image URL rule,
 * shared with the tile rail — arrives as a layout argument instead, which is
 * Magento's own answer for a block that cannot inject. See
 * ViewModel\CategoryImage.
 *
 * ===========================================================================
 * WHAT COMES FROM WHERE (CLAUDE.md section 7)
 * ===========================================================================
 *   Magento  ->  the artwork, the headline and the body copy. All three are
 *                category data, per store view. Nothing is hardcoded here and
 *                there is no fallback asset: a category with no hero image
 *                renders a title band, never a stand-in picture.
 *   Spartrak ->  the layout, the scrim, the type scale, the responsive
 *                behaviour, and which asset each viewport downloads.
 *
 * The body copy is the category's OWN `description`. A second "hero text"
 * field was deliberately NOT added: description is exactly this field, it is
 * already per store view, it already has the editor an admin expects, and a
 * duplicate would give two places to edit one paragraph. The layout removes
 * Porto's own description blocks on this handle so it renders once.
 *
 * ===========================================================================
 * LCP (CLAUDE.md section 12)
 * ===========================================================================
 * On a category page this image IS the LCP element: it is the first and
 * largest thing below the header. So in the template it is eager, never lazy,
 * and carries fetchpriority="high" — and it is the ONLY image on the page
 * allowed either. The tile rail below it is lazy to keep out of its way.
 */
class Hero extends CategoryView
{
    /**
     * Figma 488:12966 — the band is 1440 x 318 in the 1440 frame. Emitted as
     * a CSS aspect-ratio so the box is reserved before the image arrives
     * (CLAUDE.md section 11, CLS) while staying free to grow when the copy is
     * taller than the ratio.
     */
    public const RATIO_WIDTH = 1440;
    public const RATIO_HEIGHT = 318;

    /**
     * The ONE breakpoint at which the mobile asset takes over. <source media>
     * is evaluated by the preload scanner before any image is fetched, so
     * exactly one file is ever downloaded — no user-agent sniffing, no CSS
     * background swap, no JS src rewrite, all three of which fetch both.
     */
    public const MOBILE_MEDIA = '(max-width: 767px)';

    /**
     * The headline an admin typed, or '' — NO FALLBACK.
     *
     * ===========================================================================
     * WHY THIS DELIBERATELY DOES NOT FALL BACK TO THE CATEGORY NAME
     * ===========================================================================
     * It used to. The result was that every category printed its own name in
     * 40px white over the artwork whether or not anyone had asked for a
     * headline — so an unset hero looked identical to a deliberately-set one,
     * and the only way to get a clean banner was to invent a blanking value.
     * The field is optional, so empty has to mean empty.
     *
     * The <h1> is NOT lost when this returns '': see getDocumentTitle() and
     * the template, which keep exactly one heading in the document either way.
     */
    public function getHeading(): string
    {
        $category = $this->getCurrentCategory();

        if (!$category instanceof CategoryInterface) {
            return '';
        }

        return trim((string) $category->getData(HeroAttributes::ATTR_HEADING));
    }

    /**
     * What the page's single <h1> says — the admin headline when there is
     * one, otherwise the category's own name.
     *
     * ===========================================================================
     * THIS IS AN ACCESSIBILITY AND SEO REQUIREMENT, NOT A SECOND HEADLINE
     * ===========================================================================
     * The layout removes Magento's page.main.title on this handle because the
     * hero replaced it, which makes this block the ONLY thing on a category
     * page that can carry an <h1>. Letting the headline be optional therefore
     * cannot be allowed to leave the document with no heading at all: a
     * category page with no <h1> is a real defect for search engines and for
     * anyone navigating by headings in a screen reader.
     *
     * So the element is always emitted. What changes is whether it is SEEN:
     * with a headline set it renders as the Figma display type; with none it
     * is visually hidden and still read. Never `display: none` — that would
     * take it out of the accessibility tree too and defeat the point.
     */
    public function getDocumentTitle(): string
    {
        $heading = $this->getHeading();

        if ($heading !== '') {
            return $heading;
        }

        $category = $this->getCurrentCategory();

        return $category instanceof CategoryInterface ? trim((string) $category->getName()) : '';
    }

    /**
     * The raw category description. Rendered through Magento's own output
     * helper in the template — exactly as core's category/description.phtml
     * does — so admin WYSIWYG content, directives and escaping behave
     * identically to everywhere else on the platform.
     */
    public function getDescription(): string
    {
        $category = $this->getCurrentCategory();

        return $category instanceof CategoryInterface
            ? trim((string) $category->getData('description'))
            : '';
    }

    public function getDesktopImageUrl(): string
    {
        return $this->getImageResolver()->resolve($this->getCurrentCategory(), HeroAttributes::ATTR_IMAGE);
    }

    /**
     * The mobile asset, or '' when the admin has not uploaded one.
     *
     * DELIBERATELY NOT falling back to the desktop file. Returning '' makes
     * the template omit the <source> entirely, which is honest — one asset
     * was uploaded, so one asset is served. Emitting the desktop URL twice
     * would produce a <picture> that looks responsive while shipping a
     * 1440-wide crop to a phone.
     */
    public function getMobileImageUrl(): string
    {
        return $this->getImageResolver()->resolve($this->getCurrentCategory(), HeroAttributes::ATTR_IMAGE_MOBILE);
    }

    public function hasImage(): bool
    {
        return $this->getDesktopImageUrl() !== '' || $this->getMobileImageUrl() !== '';
    }

    /**
     * True when there is genuinely something to render.
     *
     * Reads getHeading() — the admin-set headline — and NOT
     * getDocumentTitle(), which always resolves to at least the category
     * name. Testing the latter would make this true on every category in the
     * catalogue and put an empty band at the top of all of them.
     *
     * When this is false the block renders nothing at all, and the page is
     * then genuinely without an <h1>. That is the correct trade: the layout
     * only takes page.main.title away because the hero stands in for it, so a
     * hero that is not there must not also suppress the title. See the
     * template for the one line that puts it back.
     */
    public function canShow(): bool
    {
        return $this->getHeading() !== '' || $this->getDescription() !== '' || $this->hasImage();
    }

    public function getMobileMedia(): string
    {
        return self::MOBILE_MEDIA;
    }

    /**
     * The reserved box, as a CSS custom-property payload. Two integers cast
     * through %d, so there is nothing admin-supplied in this attribute.
     */
    public function getRatioStyle(): string
    {
        return sprintf('--spartrak-hero-ratio:%d/%d;', self::RATIO_WIDTH, self::RATIO_HEIGHT);
    }

    /**
     * The shared category-image URL rule, handed in by layout.
     *
     * Typed accessor rather than reaching for getData() at four call sites:
     * a missing or wrong layout argument then fails here, once, with a clear
     * type error instead of four "call to a member function on null".
     */
    private function getImageResolver(): CategoryImage
    {
        $resolver = $this->getData('image_resolver');

        if (!$resolver instanceof CategoryImage) {
            throw new \LogicException(
                'Spartrak\Catalog\Block\Category\Hero requires an "image_resolver" layout argument '
                . 'of type ' . CategoryImage::class . '.'
            );
        }

        return $resolver;
    }
}
