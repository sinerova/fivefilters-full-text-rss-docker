<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once FTR_APP_DIR . '/libraries/Srcset.php';

/** 3.9.5: relative srcset URLs are rewritten to absolute form. */
final class SrcsetTest extends TestCase
{
    #[DataProvider('cases')]
    public function testAbsolutize(string $in, string $expected): void
    {
        $base = new SimplePie_IRI('https://example.com/blog/post/');
        $this->assertSame($expected, Srcset::absolutize($in, $base));
    }

    public static function cases(): array
    {
        return [
            'relative paths' => ['a.jpg 1x, /b.jpg 2x', 'https://example.com/blog/post/a.jpg 1x, https://example.com/b.jpg 2x'],
            'width descriptors' => ['small.jpg 320w, big.jpg 1024w', 'https://example.com/blog/post/small.jpg 320w, https://example.com/blog/post/big.jpg 1024w'],
            'already absolute kept' => ['https://cdn.example.net/x.jpg 2x', 'https://cdn.example.net/x.jpg 2x'],
            'protocol relative' => ['//cdn.example.net/x.jpg 2x', 'https://cdn.example.net/x.jpg 2x'],
            'no descriptor' => ['/only.jpg', 'https://example.com/only.jpg'],
            'comma inside url survives' => ['https://img.example.net/w_300,h_200/a.jpg 300w, /b.jpg 600w', 'https://img.example.net/w_300,h_200/a.jpg 300w, https://example.com/b.jpg 600w'],
            'no space after comma' => ['a.jpg 1x,b.jpg 2x', 'https://example.com/blog/post/a.jpg 1x, https://example.com/blog/post/b.jpg 2x'],
            'newlines and tabs' => ["a.jpg\t1x,\n  b.jpg\n2x", 'https://example.com/blog/post/a.jpg 1x, https://example.com/blog/post/b.jpg 2x'],
            'decimal density' => ['a.jpg 1.5x', 'https://example.com/blog/post/a.jpg 1.5x'],
            'javascript scheme dropped' => ['javascript:alert(1) 1x, ok.jpg 2x', 'https://example.com/blog/post/ok.jpg 2x'],
            'data scheme dropped' => ['data:image/png;base64,AAAA 1x, ok.jpg 2x', 'https://example.com/blog/post/ok.jpg 2x'],
            'bad descriptor dropped' => ['a.jpg onerror=alert(1), b.jpg 2x', 'https://example.com/blog/post/b.jpg 2x'],
            'nothing usable' => ['javascript:alert(1)', ''],
            'empty' => ['', ''],
        ];
    }

    public function testOversizedValueIsDropped(): void
    {
        $this->assertSame('', Srcset::absolutize(str_repeat('a.jpg 1x, ', 3000), 'https://example.com/'));
    }

    public function testCandidateCountIsCapped(): void
    {
        $in = implode(', ', array_map(fn($i) => "i$i.jpg {$i}w", range(1, 200)));
        $out = Srcset::absolutize($in, 'https://example.com/');
        $this->assertSame(Srcset::MAX_CANDIDATES, count(explode(', ', $out)));
    }
}
