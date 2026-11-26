<?php

use PHPUnit\Framework\TestCase;

/**
 * SSRF guard: pure policy checks, no network access.
 */
final class SafeHttpUrlTest extends TestCase
{
    protected function setUp(): void
    {
        SafeHttpUrl::$testAllowedHosts = null;
        if (property_exists(SafeHttpUrl::class, 'dnsResolver')) {
            SafeHttpUrl::$dnsResolver = null;
        }
    }

    protected function tearDown(): void
    {
        SafeHttpUrl::$testAllowedHosts = null;
        if (property_exists(SafeHttpUrl::class, 'dnsResolver')) {
            SafeHttpUrl::$dnsResolver = null;
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockedUrls')]
    public function testBlocksInternalAndMalformedTargets(string $url): void
    {
        $this->assertFalse(SafeHttpUrl::resolve($url), "should block: $url");
    }

    public static function blockedUrls(): array
    {
        return [
            'loopback v4' => ['http://127.0.0.1/'],
            'loopback v4 other' => ['http://127.5.6.7/'],
            'localhost name' => ['http://localhost/'],
            'localhost subdomain' => ['http://a.localhost/'],
            'loopback v6' => ['http://[::1]/'],
            'private 10/8' => ['http://10.0.0.1/'],
            'private 172.16/12' => ['http://172.16.5.4/'],
            'private 192.168/16' => ['http://192.168.1.1/'],
            'link-local / cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'cgnat 100.64/10' => ['http://100.64.0.1/'],
            'unspecified 0.0.0.0' => ['http://0.0.0.0/'],
            'multicast' => ['http://224.0.0.1/'],
            'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/'],
            'ipv4-mapped private' => ['http://[::ffff:10.0.0.1]/'],
            'ipv6 ula' => ['http://[fd00::1]/'],
            'ipv6 link-local' => ['http://[fe80::1]/'],
            'nat64 to private' => ['http://[64:ff9b::a00:1]/'],
            '6to4 embedding private' => ['http://[2002:0a00:0001::1]/'],
            'decimal ip for loopback' => ['http://2130706433/'],
            'hex ip for loopback' => ['http://0x7f000001/'],
            'gcp metadata name' => ['http://metadata.google.internal/'],
            'internal tld' => ['http://db.internal/'],
            'local mdns' => ['http://printer.local/'],
            'file scheme' => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://example.com/'],
            'ftp scheme' => ['ftp://example.com/'],
            'javascript scheme' => ['javascript:alert(1)'],
            'credentials in url' => ['http://user:pass@example.com/'],
            'non-web port' => ['http://example.com:22/'],
            'redis port' => ['http://example.com:6379/'],
            'empty string' => [''],
            'no host' => ['http:///path'],
            'control char' => ["http://example.com/\x00"],
            'space in url' => ['http://example.com/a b'],
            'too long' => ['http://example.com/' . str_repeat('a', 3000)],
        ];
    }

    public function testAllowsPublicLiteralAddresses(): void
    {
        $r = SafeHttpUrl::resolve('https://1.1.1.1/');
        $this->assertIsArray($r);
        $this->assertSame('1.1.1.1', $r['ip']);
        $this->assertSame(443, $r['port']);
    }

    public function testAllowsPublicIpv6Literal(): void
    {
        $r = SafeHttpUrl::resolve('http://[2001:4860:4860::8888]/');
        $this->assertIsArray($r);
        $this->assertSame('2001:4860:4860::8888', $r['ip']);
    }

    public function testRejectsAnyNonPublicAddressInAResolvedSet(): void
    {
        // A host whose DNS answer includes a private address must be refused outright,
        // not just filtered, so a mixed answer cannot be used to reach internal space.
        $this->assertFalse(SafeHttpUrl::isPublicIp('10.1.2.3'));
        $this->assertFalse(SafeHttpUrl::isPublicIp('192.168.0.9'));
        $this->assertFalse(SafeHttpUrl::isPublicIp('127.0.0.1'));
        $this->assertFalse(SafeHttpUrl::isPublicIp('169.254.169.254'));
        $this->assertTrue(SafeHttpUrl::isPublicIp('93.184.216.34'));
    }

    public function testAllowedPortsOnly(): void
    {
        $this->assertIsArray(SafeHttpUrl::resolve('http://1.1.1.1:8080/'));
        $this->assertFalse(SafeHttpUrl::resolve('http://1.1.1.1:23/'));
    }

    public function testTestSeamDoesNotLeakIntoProductionPolicy(): void
    {
        SafeHttpUrl::$testAllowedHosts = array('127.0.0.1:' . FTR_FIXTURE_PORT);
        $this->assertIsArray(SafeHttpUrl::resolve('http://127.0.0.1:' . FTR_FIXTURE_PORT . '/x'));
        // Any other loopback target stays blocked even with the seam active.
        $this->assertFalse(SafeHttpUrl::resolve('http://127.0.0.1:9/'));
        $this->assertFalse(SafeHttpUrl::resolve('http://169.254.169.254/'));
        SafeHttpUrl::$testAllowedHosts = null;
        $this->assertFalse(SafeHttpUrl::resolve('http://127.0.0.1:' . FTR_FIXTURE_PORT . '/x'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockedReservedIpv6Urls')]
    public function testBlocksSpecialUseIpv6RangesWithinGlobalUnicast(string $url): void
    {
        $this->assertFalse(SafeHttpUrl::resolve($url), "should block special-use IPv6: $url");
    }

    public static function blockedReservedIpv6Urls(): array
    {
        return [
            'teredo 2001::/32' => ['http://[2001:0:4136:e378:8000:63bf:3fff:fdd2]/'],
            'documentation 2001:db8::/32' => ['http://[2001:db8::1]/'],
            'benchmarking 2001:2::/48' => ['http://[2001:2::1]/'],
            'orchid 2001:10::/28' => ['http://[2001:10::1]/'],
            'orchid v2 2001:20::/28' => ['http://[2001:20::1]/'],
            'nat64 local 64:ff9b:1::/48' => ['http://[64:ff9b:1::10.0.0.1]/'],
            'discard 100::/64' => ['http://[100::1]/'],
        ];
    }

    public function testDualStackResolutionKeepsAllPublicAddressesAndFormatsResolveEntry(): void
    {
        $this->assertTrue(property_exists(SafeHttpUrl::class, 'dnsResolver'), 'SafeHttpUrl::$dnsResolver seam required');
        SafeHttpUrl::$dnsResolver = function (string $host): array {
            $this->assertSame('dualstack.example', $host);
            return array('2606:4700:4700::1111', '93.184.216.34', '93.184.216.34');
        };
        $target = SafeHttpUrl::resolve('https://dualstack.example/feed.xml');
        $this->assertIsArray($target);
        $this->assertSame(array('2606:4700:4700::1111', '93.184.216.34'), $target['ips'] ?? null);
        $this->assertSame('dualstack.example:443:[2606:4700:4700::1111],93.184.216.34', SafeHttpUrl::formatResolveEntry($target));

        $v6Literal = SafeHttpUrl::resolve('http://[2606:4700:4700::1111]:8080/feed.xml');
        $this->assertIsArray($v6Literal);
        $this->assertSame('[2606:4700:4700::1111]:8080:[2606:4700:4700::1111]', SafeHttpUrl::formatResolveEntry($v6Literal));
    }

    public function testMixedPublicAndPrivateDnsAnswerIsRejectedOutright(): void
    {
        $this->assertTrue(property_exists(SafeHttpUrl::class, 'dnsResolver'));
        SafeHttpUrl::$dnsResolver = fn(string $h) => array('93.184.216.34', '10.0.0.5');
        $this->assertFalse(SafeHttpUrl::resolve('http://mixed-v4.example/'));

        SafeHttpUrl::$dnsResolver = fn(string $h) => array('2606:4700:4700::1111', '::1');
        $this->assertFalse(SafeHttpUrl::resolve('http://mixed-v6.example/'));
    }

    public function testLegacyUnpinnableTransportsAreDisabled(): void
    {
        try {
            new HumbleHttpAgent(null, HumbleHttpAgent::METHOD_FILE_GET_CONTENTS);
            $this->fail('METHOD_FILE_GET_CONTENTS must be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('METHOD_CURL_MULTI', $e->getMessage());
        }

        try {
            new HumbleHttpAgent(null, HumbleHttpAgent::METHOD_REQUEST_POOL);
            $this->fail('METHOD_REQUEST_POOL must be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('METHOD_CURL_MULTI', $e->getMessage());
        }
    }
}
