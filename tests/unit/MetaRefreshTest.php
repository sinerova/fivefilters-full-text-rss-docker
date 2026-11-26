<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Meta refresh parsing (the 3.9.5 "Google Alert feeds not producing results" area). */
final class MetaRefreshTest extends TestCase
{
    #[DataProvider('pages')]
    public function testParse(string $html, $expected): void
    {
        $this->assertSame($expected, HumbleHttpAgent::parseMetaRefresh($html));
    }

    public static function pages(): array
    {
        return [
            'classic' => ['<meta HTTP-EQUIV="REFRESH" content="0; url=http://example.com/a?id=9">', 'http://example.com/a?id=9'],
            'attribute order swapped' => ['<meta content="0;URL=http://example.com/b" http-equiv="refresh">', 'http://example.com/b'],
            'multi-digit delay' => ['<meta http-equiv="refresh" content="10; url=/next">', '/next'],
            'decimal delay' => ['<meta http-equiv="refresh" content="1.5; url=/next">', '/next'],
            'single quotes' => ["<meta http-equiv='refresh' content='0; url=/q'>", '/q'],
            'quoted url inside content' => ['<meta http-equiv="refresh" content="0; url=\'http://example.com/c\'">', 'http://example.com/c'],
            'comma separator' => ['<meta http-equiv="refresh" content="0, url=/d">', '/d'],
            'no url keyword' => ['<meta http-equiv="refresh" content="0; /e">', '/e'],
            'self-closing xhtml' => ['<meta http-equiv="refresh" content="0;url=/f" />', '/f'],
            'unquoted attributes' => ['<meta http-equiv=refresh content=0;url=/g>', '/g'],
            'entity encoded query kept raw' => ['<meta http-equiv="refresh" content="0; url=/h?a=1&amp;b=2">', '/h?a=1&amp;b=2'],
            'second meta tag wins when first is unrelated' => ['<meta charset="utf-8"><meta http-equiv="refresh" content="0; url=/i">', '/i'],
            'inside IE conditional comment is ignored' => ['<!--[if lte IE 9]><meta http-equiv="refresh" content="1;url=/unsupported"><![endif]--><p>x</p>', false],
            'inside plain comment is ignored' => ['<!-- <meta http-equiv="refresh" content="0;url=/x"> -->', false],
            'reload without url is not a redirect' => ['<meta http-equiv="refresh" content="30">', false],
            'other http-equiv' => ['<meta http-equiv="content-type" content="text/html; charset=utf-8">', false],
            'not a meta tag' => ['<p>refresh content="0; url=/x"</p>', false],
            'empty' => ['', false],
        ];
    }

    public function testOnlyScansTheStartOfHugeDocuments(): void
    {
        $html = str_repeat('x', 200000) . '<meta http-equiv="refresh" content="0; url=/late">';
        $this->assertFalse(HumbleHttpAgent::parseMetaRefresh($html));
    }
}
