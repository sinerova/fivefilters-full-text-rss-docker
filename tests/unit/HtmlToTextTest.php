<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once FTR_APP_DIR . '/libraries/html5php/autoloader.php';
require_once FTR_APP_DIR . '/libraries/HtmlToText.php';

/** &content=text and &content=text80 (3.9, 3.9.1). */
final class HtmlToTextTest extends TestCase
{
    #[DataProvider('parameters')]
    public function testWidthFromParameter($value, ?int $expected): void
    {
        $this->assertSame($expected, HtmlToText::widthFromParameter($value));
    }

    public static function parameters(): array
    {
        return [
            'text' => ['text', 70],
            'text80' => ['text80', 80],
            'text100' => ['text100', 100],
            'clamped low' => ['text5', 20],
            'clamped high' => ['text9999', 500],
            '0' => ['0', null],
            '1' => ['1', null],
            'html5' => ['html5', null],
            'junk' => ['text80x', null],
            'upper case is not text' => ['TEXT', null],
            'array' => [array('text'), null],
            'null' => [null, null],
        ];
    }

    public function testParagraphsAndInlineMarkup(): void
    {
        $out = HtmlToText::convert('<p>First <b>bold</b> and <em>emphasis</em>.</p><p>Second   paragraph.</p>');
        $this->assertSame("First bold and emphasis.\n\nSecond paragraph.", $out);
    }

    public function testNeverEmitsMarkupOrScriptContent(): void
    {
        $out = HtmlToText::convert('<p onclick="x()">Hi</p><script>alert(1)</script><style>p{color:red}</style><iframe src="http://e/"></iframe><!-- c --><svg><text>vec</text></svg><p>Bye</p>');
        $this->assertSame("Hi\n\nBye", $out);
        $this->assertStringNotContainsString('<', $out);
    }

    public function testEntitiesAreDecodedButStayText(): void
    {
        $out = HtmlToText::convert('<p>Tom &amp; Jerry &lt;b&gt;not bold&lt;/b&gt; caf&eacute;</p>');
        $this->assertSame('Tom & Jerry <b>not bold</b> café', $out);
    }

    public function testWrapsAtDefaultAndGivenWidth(): void
    {
        $words = implode(' ', array_fill(0, 60, 'word'));
        foreach (array(70, 80) as $w) {
            $out = HtmlToText::convert("<p>$words</p>", $w);
            foreach (explode("\n", $out) as $line) {
                $this->assertLessThanOrEqual($w, strlen($line));
            }
            $this->assertGreaterThan(strlen($words), strlen($out) + 1, 'newlines replaced some spaces');
            $this->assertSame($words, str_replace("\n", ' ', $out), 'no words lost');
        }
        $narrow = count(explode("\n", HtmlToText::convert("<p>$words</p>", 40)));
        $wide = count(explode("\n", HtmlToText::convert("<p>$words</p>", 200)));
        $this->assertGreaterThan($wide, $narrow);
    }

    public function testLongUrlsAreNotBroken(): void
    {
        $url = 'http://example.com/' . str_repeat('a', 120);
        $out = HtmlToText::convert("<p>See $url now</p>", 40);
        $this->assertStringContainsString($url, $out);
    }

    public function testWrapCountsCharactersNotBytes(): void
    {
        $out = HtmlToText::convert('<p>' . implode(' ', array_fill(0, 30, 'ééééé')) . '</p>', 30);
        foreach (explode("\n", $out) as $line) {
            $this->assertLessThanOrEqual(30, mb_strlen($line, 'UTF-8'));
        }
    }

    public function testLinksShowTheirTargetOnlyWhenUseful(): void
    {
        $out = HtmlToText::convert('<p><a href="http://example.com/a">a link</a>, <a href="http://example.com/b">http://example.com/b</a>, <a href="javascript:alert(1)">bad</a>, <a href="/relative">rel</a>, <a href="#top">top</a></p>');
        $this->assertSame('a link [http://example.com/a], http://example.com/b, bad, rel, top', $out);
    }

    public function testImagesShowTheirAltText(): void
    {
        $this->assertSame('See [A chart] here', HtmlToText::convert('<p>See <img src="http://e/a.png" alt="A chart"> here</p>'));
        $this->assertSame('No alt', HtmlToText::convert('<p>No alt<img src="http://e/a.png"></p>'));
    }

    public function testLists(): void
    {
        $out = HtmlToText::convert('<p>Intro</p><ul><li>one</li><li>two<ul><li>nested</li></ul></li></ul><ol><li>first</li><li>second</li></ol>');
        $this->assertSame("Intro\n\n* one\n* two\n  * nested\n\n1. first\n2. second", $out);
    }

    public function testBlockquoteAndHeadingsAndBreaks(): void
    {
        $out = HtmlToText::convert('<h1>Title</h1><blockquote>Quoted text<br>second line</blockquote><p>line one<br>line two</p><hr><p>end</p>');
        $this->assertSame("Title\n\n> Quoted text\n> second line\n\nline one\nline two\n\n----------------------------------------\n\nend", $out);
    }

    public function testPreKeepsWhitespace(): void
    {
        $out = HtmlToText::convert("<p>Code:</p><pre>if (x) {\n    run();\n}</pre>");
        $this->assertSame("Code:\n\nif (x) {\n    run();\n}", $out);
    }

    public function testTableCellsStaySeparated(): void
    {
        $out = HtmlToText::convert('<table><tr><td>a</td><td>b</td></tr><tr><td>c</td><td>d</td></tr></table>');
        $this->assertSame("a b\n\nc d", $out);
    }

    public function testEmptyAndWhitespaceInput(): void
    {
        $this->assertSame('', HtmlToText::convert(''));
        $this->assertSame('', HtmlToText::convert("  \n <p> </p> <div></div> "));
        $this->assertSame('plain text only', HtmlToText::convert('plain text only'));
    }

    public function testVeryDeepNestingDoesNotBlowTheStack(): void
    {
        $html = str_repeat('<div>', 400) . 'deep text' . str_repeat('</div>', 400);
        $this->assertStringContainsString('deep text', HtmlToText::convert($html));
    }
}
