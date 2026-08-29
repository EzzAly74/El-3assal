<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Catalog\Model\Swatch;

use Magento\Framework\Exception\LocalizedException;

/**
 * Makes an uploaded SVG safe to store and serve.
 *
 * ===========================================================================
 * WHY THIS CLASS HAS TO EXIST
 * ===========================================================================
 * Brand logos on this storefront are attribute swatch images, and the brief is
 * that they be uploadable as SVG so the marks stay sharp at every size. An SVG
 * is not an image file in the sense the other four formats are — it is an XML
 * DOCUMENT, and the SVG spec gives it <script>, event-handler attributes,
 * <foreignObject> (which can contain arbitrary XHTML), external references and
 * an XML doctype that can declare entities. Stored under the media directory
 * and served from the storefront's own origin, an unfiltered SVG is a
 * stored-XSS primitive and an XXE one.
 *
 * TWO CONTROLS, NOT ONE
 *
 *   1. Where the logos are RENDERED, they are `<img src="...svg">`. Script
 *      inside an SVG referenced by <img> does not execute in any browser — the
 *      image is loaded in a restricted mode with no script, no external loads
 *      and no interactivity. So the rendering path is already inert, and
 *      nothing in the theme needs to change.
 *
 *   2. What is left is someone opening the .svg URL DIRECTLY, in a tab, on the
 *      site's own origin, where it IS a document and script DOES run. That is
 *      what this class removes, at upload time, once — not on every render.
 *
 * ALLOW-LIST, NOT DENY-LIST, FOR ATTRIBUTES. Every `on*` attribute goes,
 * whether or not it is one we have heard of, and every URL-bearing attribute
 * must resolve to a same-document fragment or an inline data: image. That way a
 * handler or a scheme this code does not know about still cannot survive.
 *
 * THE FILE IS REWRITTEN, NOT JUST CHECKED. Rejecting a suspicious upload and
 * storing the rest verbatim would leave whatever the parser did not think to
 * look for. Serialising the cleaned DOM back over the file means what is stored
 * is what this class produced.
 */
class SvgSanitizer
{
    /**
     * Elements with no legitimate place in a logo, each of which can execute or
     * fetch something.
     */
    private const FORBIDDEN_ELEMENTS = [
        'script',
        'foreignobject',
        'iframe',
        'embed',
        'object',
        'audio',
        'video',
        'handler',
        'listener',
        'animate',
        'animatemotion',
        'animatetransform',
        'set',
    ];

    /**
     * Attributes that carry a URL. Anything here is kept only if it points at a
     * fragment in this same document or at an inline image.
     */
    private const URL_ATTRIBUTES = ['href', 'xlink:href', 'src', 'from', 'to', 'values', 'begin'];

    /**
     * Parse, clean, and write the file back — in place.
     *
     * This is the uploader's validate callback. Magento invokes it against
     * PHP's own upload temp before anything is copied (see
     * Uploader::_validateFile), so throwing here refuses the upload outright,
     * and rewriting here means every later copy carries the cleaned bytes. One
     * pass, inside Magento's upload path, with no second write of its own.
     *
     * @throws LocalizedException
     */
    public function sanitizeFile(string $filePath): void
    {
        $document = $this->load($filePath);

        $this->clean($document->documentElement);

        $cleaned = $document->saveXML();

        if ($cleaned === false || file_put_contents($filePath, $cleaned) === false) {
            throw new LocalizedException(__('The SVG could not be processed. Please try another file.'));
        }
    }

    /**
     * Parse the file, refusing anything that is not a plain SVG document.
     *
     * @throws LocalizedException
     */
    private function load(string $filePath): \DOMDocument
    {
        $contents = file_get_contents($filePath);

        if ($contents === false || trim($contents) === '') {
            throw new LocalizedException(__('The SVG file is empty or could not be read.'));
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        // LIBXML_NONET blocks network fetches during parsing. LIBXML_NOENT is
        // deliberately NOT passed: it SUBSTITUTES entities, which is the XXE
        // foot-gun its name suggests the opposite of.
        $loaded = $document->loadXML($contents, LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $document->documentElement === null) {
            throw new LocalizedException(__('That file is not valid SVG.'));
        }

        // A doctype is the only way to declare an entity, and a logo has no use
        // for one. Refused outright rather than stripped, because a file that
        // has one is not a file we were expecting.
        if ($document->doctype !== null) {
            throw new LocalizedException(__('SVG files with a document type declaration are not accepted.'));
        }

        if (strtolower($document->documentElement->localName) !== 'svg') {
            throw new LocalizedException(__('That file is not valid SVG.'));
        }

        return $document;
    }

    /**
     * Depth-first, and it walks the child list BACKWARDS so removing a node
     * cannot shift the ones still to be visited.
     */
    private function clean(\DOMElement $element): void
    {
        for ($i = $element->childNodes->length - 1; $i >= 0; $i--) {
            $child = $element->childNodes->item($i);

            if ($child instanceof \DOMElement) {
                if (in_array(strtolower($child->localName), self::FORBIDDEN_ELEMENTS, true)) {
                    $element->removeChild($child);
                    continue;
                }

                // <style> is kept — a logo may legitimately carry one — but
                // its TEXT is scrubbed. CSS cannot run script in any current
                // browser, but @import and url() can still reach off-origin and
                // turn a static logo into a beacon.
                if (strtolower($child->localName) === 'style') {
                    $child->nodeValue = $this->scrubCss($child->textContent);
                    continue;
                }

                $this->clean($child);
                continue;
            }

            // A processing instruction can carry an xml-stylesheet reference.
            if ($child instanceof \DOMProcessingInstruction) {
                $element->removeChild($child);
            }
        }

        $this->cleanAttributes($element);
    }

    private function cleanAttributes(\DOMElement $element): void
    {
        if (!$element->hasAttributes()) {
            return;
        }

        for ($i = $element->attributes->length - 1; $i >= 0; $i--) {
            $attribute = $element->attributes->item($i);

            if (!$attribute instanceof \DOMAttr) {
                continue;
            }

            $name = strtolower($attribute->nodeName);
            $value = $attribute->nodeValue ?? '';

            // Every event handler, known or not.
            if (str_starts_with($name, 'on')) {
                $element->removeAttributeNode($attribute);
                continue;
            }

            if (in_array($name, self::URL_ATTRIBUTES, true) && !$this->isSafeUrl($value)) {
                $element->removeAttributeNode($attribute);
                continue;
            }

            // `style` can reach a URL too, and IE's expression() is still worth
            // refusing on a file that will outlive this comment.
            if ($name === 'style' && preg_match('/expression\s*\(|url\s*\(\s*[\'"]?\s*(?!data:image\/)/i', $value)) {
                $element->removeAttributeNode($attribute);
            }
        }
    }

    /**
     * Strip anything in a <style> block that reaches outside the document.
     */
    private function scrubCss(string $css): string
    {
        // Whole @import rules, then any remaining url() that is not inline data.
        $css = preg_replace('/@import[^;]*;?/i', '', $css) ?? '';

        return preg_replace_callback(
            '~url\s*\(\s*[\x22\x27]?([^)\x22\x27]*)[\x22\x27]?\s*\)~i',
            static function (array $match): string {
                return preg_match('~^data:image/~i', trim($match[1])) ? $match[0] : 'none';
            },
            $css
        ) ?? '';
    }

    /**
     * A same-document fragment, or an inline raster image. Nothing else.
     */
    private function isSafeUrl(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || str_starts_with($value, '#')) {
            return true;
        }

        // Animation attributes legitimately hold plain values ("0;1", "5s")
        // rather than URLs. Those carry no scheme and no slash, so they pass.
        if (!str_contains($value, ':') && !str_contains($value, '/')) {
            return true;
        }

        return (bool) preg_match('#^data:image/(png|jpeg|gif|webp);base64,#i', $value);
    }
}
