<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Catalog\Model\Swatch;

use Magento\MediaStorage\Model\File\Validator\NotProtectedExtension;

/**
 * Lets `svg` past the protected-extension gate — for ONE uploader, nothing else.
 *
 * ===========================================================================
 * THE THING THAT WAS ACTUALLY BLOCKING SVG SWATCHES
 * ===========================================================================
 * Allowing the extension on the uploader is not enough, and this is why:
 *
 *     Magento\MediaStorage\Model\File\Uploader::checkAllowedExtension()
 *         if (!$this->_validator->isValid($extension)) { return false; }
 *         return parent::checkAllowedExtension($extension);
 *
 * The protected-extension validator runs FIRST and its verdict is final, so
 * `setAllowedExtensions(['svg'])` in the controller never even gets a say.
 * And `svg` IS on that list: Magento_Store/etc/config.xml ships
 * `general/file/protected_extensions` containing php, phtml, htaccess, … and
 * both `svg` and `svgz`, for exactly the reason CLAUDE.md section 17 gives —
 * an SVG is an executable document.
 *
 * ===========================================================================
 * WHY THIS AND NOT THE OBVIOUS ALTERNATIVE
 * ===========================================================================
 * The obvious fix is to take `svg` out of `general/file/protected_extensions`.
 * That would be a disaster: the list is GLOBAL, so SVG would immediately become
 * uploadable through the CMS media gallery, product images, category images,
 * customer file-upload options and every third-party uploader in the
 * installation — none of which sanitise anything. One requirement about brand
 * logos would have quietly unlocked SVG upload across the whole admin.
 *
 * So the list is left exactly as Magento ships it, and this subclass is wired
 * into ONE virtual uploader (see etc/adminhtml/di.xml) that ONE controller
 * uses. Every other uploader in the installation keeps the stock validator and
 * keeps refusing SVG.
 *
 * The safety that the protected list was providing is not simply dropped
 * either — it is replaced with something stronger for this one path.
 * SvgSanitizer parses the file, rejects anything that is not a plain SVG
 * document, and rewrites it without script, event handlers, foreign objects,
 * external references or a doctype. A blanket extension ban is a coarse
 * control; content sanitisation is the precise one.
 */
class SvgExtensionValidator extends NotProtectedExtension
{
    /**
     * The two extensions this validator, and only this validator, permits.
     */
    private const PERMITTED = ['svg', 'svgz'];

    /**
     * Nothing on the parent is touched on the permitted path — no setValue(),
     * no message state. NotProtectedExtension extends Laminas's
     * AbstractValidator, whose bookkeeping exists to explain a FAILURE, and
     * this branch is not one. `checkAllowedExtension()` reads nothing but the
     * boolean.
     *
     * @param string $value Extension of file
     * @return bool
     */
    public function isValid($value)
    {
        if (in_array(strtolower(trim((string) $value)), self::PERMITTED, true)) {
            return true;
        }

        // Everything else still gets the stock verdict — php, phtml, htaccess
        // and the rest of the list are as protected here as anywhere.
        return parent::isValid($value);
    }
}
