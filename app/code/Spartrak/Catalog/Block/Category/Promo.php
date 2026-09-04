<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Catalog\Block\Category;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Block\Category\View as CategoryView;
use Spartrak\Catalog\Setup\Patch\Data\AddCategoryPromoAttributes as PromoAttributes;
use Spartrak\Catalog\ViewModel\CategoryImage;

/**
 * The category page's promo band — the second admin-managed banner, rendered
 * directly below the "تسوق بالمنتجات" tile rail.
 *
 * ===========================================================================
 * NOT IN FIGMA. A BUSINESS REQUIREMENT, IMPLEMENTED IN THE DESIGN'S LANGUAGE.
 * ===========================================================================
 * The category frame (488:10810) has nothing between the tile rail and the
 * product area. So there is no frame to match here, and none is invented:
 * this band reuses the hero's exact construction — full bleed, admin artwork,
 * one asset per viewport, box reserved before it paints — and introduces no
 * new visual treatment of its own. Flagged rather than left to look designed
 * (CLAUDE.md section 3).
 *
 * ===========================================================================
 * HOW IT DIFFERS FROM THE HERO, AND WHY THAT IS NOT DUPLICATION
 * ===========================================================================
 *   hero   artwork + heading + description, no link. It is the page's
 *          masthead: it carries the <h1> and is not clickable.
 *   promo  artwork only, plus a destination and alt text. It is an
 *          advertisement: it links somewhere and has no DOM text at all.
 *
 * Two content models, so two blocks — but ONE URL rule (ViewModel\
 * CategoryImage, shared), one `<picture>` pattern and one full-bleed
 * technique. What is shared is shared; only the parts that genuinely differ
 * are written twice.
 *
 * The alt text is the sharpest difference and the reason this block has a
 * field the hero does not. The hero's heading and blurb are real text a
 * screen reader already reads, which makes its artwork decorative (alt="").
 * This band has no text, so its entire meaning is in the picture and section
 * 15 requires a real alt.
 *
 * ===========================================================================
 * IT IS NOT THE LCP ELEMENT, AND IS TREATED ACCORDINGLY
 * ===========================================================================
 * It sits below the hero and below a rail, so it is below the fold on every
 * viewport the design covers. Lazy, no fetchpriority, decoding async — the
 * hero above it is the page's LCP and nothing here may compete with it for
 * the connection (CLAUDE.md section 12).
 *
 * No constructor, same as the hero: everything needed comes from
 * Magento\Catalog\Block\Category\View, and the one collaborator arrives as a
 * layout argument. See that class for the full reasoning.
 */
class Promo extends CategoryView
{
    /**
     * The ONE breakpoint at which the mobile asset takes over. Evaluated by
     * the preload scanner before any image is fetched, so exactly one file is
     * ever downloaded.
     */
    public const MOBILE_MEDIA = '(max-width: 767px)';

    public function getDesktopImageUrl(): string
    {
        return $this->getImageResolver()->resolve($this->getCurrentCategory(), PromoAttributes::ATTR_IMAGE);
    }

    /**
     * The mobile asset, or '' when none was uploaded.
     *
     * DELIBERATELY NOT falling back to the desktop file — see the hero for
     * the full reasoning. '' makes the template omit the <source> entirely,
     * which is the honest outcome of one upload.
     */
    public function getMobileImageUrl(): string
    {
        return $this->getImageResolver()->resolve($this->getCurrentCategory(), PromoAttributes::ATTR_IMAGE_MOBILE);
    }

    /*
     * THE BAND IS NOT A LINK, so there is no getLinkUrl() here any more.
     *
     * It briefly was one. Two reasons it is gone, and neither is cosmetic:
     *   - the band's call to action is the "البحث السريع" button on top of
     *     it, which opens the quick-search dialog. A link on the wrapper
     *     would be a second, competing destination for the same click target;
     *   - a <button> nested inside an <a> is invalid HTML and unusable with a
     *     keyboard — the anchor swallows the activation.
     *
     * Also worth recording for anyone adding a method here: the name `getUrl`
     * is RESERVED. AbstractBlock declares `getUrl($route = '', $params = [])`
     * as the URL builder, and redeclaring it with a narrower signature is a
     * fatal at DI compile time, not a runtime surprise.
     */

    /**
     * What the banner says.
     *
     * Falls back to '' and NOT to the category name. A wrong alt is worse
     * than an empty one: "المحرك" on a banner advertising a discount tells a
     * screen-reader user something untrue, whereas alt="" correctly marks the
     * image as carrying nothing they are missing. The admin field's note says
     * as much.
     */
    public function getAlt(): string
    {
        $category = $this->getCurrentCategory();

        return $category instanceof CategoryInterface
            ? trim((string) $category->getData(PromoAttributes::ATTR_ALT))
            : '';
    }

    /**
     * The desktop asset's real pixel dimensions, or null.
     *
     * Read off the file rather than assumed, because this band has no
     * designed ratio to reserve it at — see ViewModel\CategoryImage::
     * dimensions(). The template turns them into width/height attributes, and
     * the browser derives the aspect ratio from those and reserves the box
     * before the image arrives.
     *
     * @return array{0: int, 1: int}|null
     */
    public function getDesktopDimensions(): ?array
    {
        return $this->getImageResolver()->dimensions($this->getCurrentCategory(), PromoAttributes::ATTR_IMAGE);
    }

    public function canShow(): bool
    {
        return $this->getDesktopImageUrl() !== '' || $this->getMobileImageUrl() !== '';
    }

    public function getMobileMedia(): string
    {
        return self::MOBILE_MEDIA;
    }

    /**
     * The shared category-image rule, handed in by layout.
     *
     * Typed accessor rather than getData() at five call sites: a missing or
     * wrong layout argument then fails here, once, with a clear message.
     */
    private function getImageResolver(): CategoryImage
    {
        $resolver = $this->getData('image_resolver');

        if (!$resolver instanceof CategoryImage) {
            throw new \LogicException(
                'Spartrak\Catalog\Block\Category\Promo requires an "image_resolver" layout argument '
                . 'of type ' . CategoryImage::class . '.'
            );
        }

        return $resolver;
    }
}
