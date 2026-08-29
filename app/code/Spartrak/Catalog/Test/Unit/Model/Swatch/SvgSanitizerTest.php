<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Catalog\Test\Unit\Model\Swatch;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Spartrak\Catalog\Model\Swatch\SvgSanitizer;

/**
 * SVG swatch uploads are a deliberate, requested exception to this project's
 * "no SVG in the media directory" position (CLAUDE.md section 17), and the
 * sanitiser is the whole of what makes that exception safe. It is the one piece
 * of this module where "it looked right when I read it" is not good enough, so
 * its behaviour is pinned here.
 *
 * @see \Spartrak\Catalog\Model\Swatch\SvgSanitizer
 */
class SvgSanitizerTest extends TestCase
{
    private SvgSanitizer $sanitizer;

    private string $file;

    protected function setUp(): void
    {
        $this->sanitizer = new SvgSanitizer();
        $this->file = tempnam(sys_get_temp_dir(), 'spartrak-svg') ?: '';
    }

    protected function tearDown(): void
    {
        if ($this->file !== '' && file_exists($this->file)) {
            unlink($this->file);
        }
    }

    /**
     * One well-formed file carrying every vector at once. Well-formed on
     * purpose: malformed XML is refused by the parser before any of this runs,
     * so testing with malformed input would prove nothing about the cleaning.
     */
    public function testEveryExecutableVectorIsRemovedAndTheArtworkSurvives(): void
    {
        $this->sanitize(
            '<?xml version="1.0"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"'
            . ' width="100" height="100" onload="alert(1)">'
            . '<script>alert(document.cookie)</script>'
            . '<style>@import url(//evil.test/x.css); .a{fill:url(//evil.test/y.svg#f)}</style>'
            . '<foreignObject><body xmlns="http://www.w3.org/1999/xhtml">'
            . '<img src="x" onerror="alert(2)" /></body></foreignObject>'
            . '<a xlink:href="javascript:alert(3)">'
            . '<rect width="10" height="10" onclick="alert(4)" fill="#f00"/></a>'
            . '<image href="https://evil.test/track.png" width="10" height="10"/>'
            . '<use xlink:href="https://evil.test/z.svg#a"/>'
            . '<circle cx="50" cy="50" r="40" fill="#0af"/>'
            . '</svg>'
        );

        $result = (string) file_get_contents($this->file);

        foreach (['<script', 'foreignObject', 'onload', 'onclick', 'onerror', 'javascript:', '@import', 'evil.test'] as $vector) {
            $this->assertStringNotContainsStringIgnoringCase(
                $vector,
                $result,
                sprintf('"%s" survived sanitisation', $vector)
            );
        }

        // The point of the exercise: it is still the logo afterwards.
        $this->assertStringContainsString('<circle', $result);
        $this->assertStringContainsString('#0af', $result);
    }

    /**
     * A real asset from this theme, so the test fails if the sanitiser ever
     * starts eating legitimate drawings.
     */
    public function testARealExportedAssetPassesThroughIntact(): void
    {
        $source = __DIR__ . '/../../../../../../../design/frontend/Spartrak/spartrak/web/images/icons/arrow-next.svg';

        if (!is_file($source)) {
            $this->markTestSkipped('The reference asset has moved; update the path in this test.');
        }

        $original = (string) file_get_contents($source);
        $this->sanitize($original);

        $result = (string) file_get_contents($this->file);

        $this->assertSame(
            preg_match_all('/<path/', $original),
            preg_match_all('/<path/', $result),
            'Sanitising dropped drawing instructions from a clean asset'
        );
        $this->assertStringContainsString('stroke-linecap', $result);
    }

    /**
     * @dataProvider refusedFilesProvider
     */
    public function testFilesThatAreNotPlainSvgAreRefused(string $payload): void
    {
        $this->expectException(LocalizedException::class);
        $this->sanitize($payload);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedFilesProvider(): array
    {
        return [
            'a doctype, which is the entity-declaration vector' => [
                '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
                . '<svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>',
            ],
            'HTML wearing a .svg name' => ['<html><body><script>alert(1)</script></body></html>'],
            'a PNG wearing a .svg name' => ["\x89PNG\r\n\x1a\n" . str_repeat("\0", 32)],
            'an empty file' => [''],
        ];
    }

    private function sanitize(string $payload): void
    {
        file_put_contents($this->file, $payload);
        $this->sanitizer->sanitizeFile($this->file);
    }
}
