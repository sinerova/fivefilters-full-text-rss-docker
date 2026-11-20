<?php

use Masterminds\HTML5;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the bundled masterminds/html5 (2.12.0).
 * Covers the cases from GHSA-6c7p-58mg-f5jv and GHSA-pj59-fvjc-74pp, plus the
 * way Readability and make_html() use the library (disable_html_ns).
 */
final class Html5ParserTest extends TestCase
{
    private static function html5(): HTML5
    {
        require_once FTR_APP_DIR . '/libraries/html5php/autoloader.php';
        return new HTML5(array('disable_html_ns' => true));
    }

    private static function roundTrip(string $html): string
    {
        $h = self::html5();
        return $h->saveHTML($h->loadHTML($html));
    }

    public function testBundledVersionFileNamesTheFixedRelease(): void
    {
        $v = file_get_contents(FTR_APP_DIR . '/libraries/html5php/VERSION.txt');
        $this->assertStringContainsString('2.12.0', $v);
        $this->assertStringContainsString('15e16663bd6f512352209d0e3a789c0a691c0d9b', $v);
    }

    public function testFormFeedInAttributeValueDoesNotCreateNewAttribute(): void
    {
        $out = self::roundTrip("<img src=x alt='\x0c" . "onerror=alert()\x0c'>");
        $dom = self::html5()->loadHTML($out);
        $img = $dom->getElementsByTagName('img')->item(0);
        $this->assertNotNull($img);
        $this->assertFalse($img->hasAttribute('onerror'), 'form feed must not split an attribute: ' . $out);
    }

    public function testNamespaceDeclarationValueIsEscapedOnSerialisation(): void
    {
        $out = self::roundTrip("<p xmlns:x='a\"><img src=x onerror=alert(1)>'>x</p>");
        $dom = self::html5()->loadHTML($out);
        $this->assertSame(0, $dom->getElementsByTagName('img')->length, 'no injected element: ' . $out);
    }

    public function testInvalidUtf8IsReplacedNotDeleted(): void
    {
        $dom = self::html5()->loadHTML("<html><body><scr\xFFipt>alert(1)</scr\xFFipt><p>ok</p></body></html>");
        $this->assertSame(0, $dom->getElementsByTagName('script')->length, 'token must not be rejoined into <script>');
    }

    public function testManyParseErrorsDoNotCostQuadraticTime(): void
    {
        // 2.3.0 needs ~3 s for 156 KB and grows quadratically; 2.12.0 needs ~0.1 s.
        $input = '<p>' . str_repeat('&;', 80000) . '</p>';
        $start = microtime(true);
        self::html5()->loadHTML($input);
        $this->assertLessThan(1.0, microtime(true) - $start, '156 KB of error-heavy input must parse quickly');
    }

    public function testNormalDocumentRoundTripsWithEntitiesAndUtf8(): void
    {
        $out = self::roundTrip('<!DOCTYPE html><html><body><article><p>caf&eacute; na&iuml;ve &amp; ü</p></article></body></html>');
        $this->assertStringContainsString('café naïve &amp; ü', $out);
        $this->assertStringContainsString('<article>', $out);
    }

    public function testDisableHtmlNsKeepsXpathQueriesWorking(): void
    {
        $dom = self::html5()->loadHTML('<html><body><div id="a"><p class="x">hi</p></div></body></html>');
        $xp = new DOMXPath($dom);
        $this->assertSame(1, $xp->query('//p[@class="x"]')->length, 'Readability relies on non-namespaced XPath');
    }
}
