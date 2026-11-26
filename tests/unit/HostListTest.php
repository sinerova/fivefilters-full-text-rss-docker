<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once FTR_APP_DIR . '/libraries/HostList.php';

/** allowed_hosts matching: exact host or subdomain at a label boundary, never a substring. */
final class HostListTest extends TestCase
{
    #[DataProvider('matching')]
    public function testMatches(string $url, array $list, bool $expected): void
    {
        $this->assertSame($expected, HostList::urlMatches($url, $list), $url);
    }

    public static function matching(): array
    {
        return [
            'exact' => ['http://example.com/a', ['example.com'], true],
            'subdomain' => ['https://www.example.com/a', ['example.com'], true],
            'deep subdomain' => ['https://a.b.example.com/', ['example.com'], true],
            'case ignored' => ['http://WWW.Example.COM/', ['example.COM'], true],
            'trailing dot' => ['http://example.com./', ['example.com'], true],
            'leading dot entry' => ['http://www.example.com/', ['.example.com'], true],
            'port ignored' => ['http://example.com:8080/', ['example.com'], true],
            'second entry' => ['http://example.org/', ['example.com', 'example.org'], true],
            'suffix without dot is not a subdomain' => ['http://notexample.com/', ['example.com'], false],
            'host appears in query' => ['http://evil.net/?u=example.com', ['example.com'], false],
            'host appears in path' => ['http://evil.net/example.com', ['example.com'], false],
            'host appears in userinfo' => ['http://example.com@evil.net/', ['example.com'], false],
            'host appears in subdomain prefix' => ['http://example.com.evil.net/', ['example.com'], false],
            'parent of entry is not allowed' => ['http://com/', ['example.com'], false],
            'no host' => ['/relative/path', ['example.com'], false],
            'empty list entry ignored' => ['http://example.com/', ['', '.'], false],
            'empty list' => ['http://example.com/', [], false],
        ];
    }
}
