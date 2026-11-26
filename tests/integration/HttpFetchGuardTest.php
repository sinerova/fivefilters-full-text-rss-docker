<?php

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real HumbleHttpAgent fetch path against a local fixture server.
 *
 * Requires the fixture server (see tests/README.md). The test allow-list
 * permits only 127.0.0.1:FTR_FIXTURE_PORT; everything else is blocked by policy.
 */
#[Group('integration')]
final class HttpFetchGuardTest extends TestCase
{
    private static function base(): string
    {
        return 'http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT;
    }

    protected function setUp(): void
    {
        SafeHttpUrl::$testAllowedHosts = array(FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT);
        if (!self::fixtureReachable()) {
            $this->markTestSkipped('Fixture server not running on ' . self::base() . ' (see tests/README.md).');
        }
    }

    protected function tearDown(): void
    {
        SafeHttpUrl::$testAllowedHosts = null;
        if (property_exists(SafeHttpUrl::class, 'testHostIpMap')) {
            SafeHttpUrl::$testHostIpMap = null;
        }
    }

    private static function fixtureReachable(): bool
    {
        $fp = @fsockopen(FTR_FIXTURE_HOST, FTR_FIXTURE_PORT, $errno, $errstr, 1);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }

    private static function agent(int $timeout = 5): HumbleHttpAgent
    {
        $agent = new HumbleHttpAgent(array('timeout' => $timeout), HumbleHttpAgent::METHOD_CURL_MULTI);
        return $agent;
    }

    public function testFetchesArticleFromAllowedFixture(): void
    {
        $r = self::agent()->get(self::base() . '/article.html');
        $this->assertIsArray($r);
        $this->assertSame(200, $r['status_code']);
        $this->assertStringContainsString('Fixture Article', $r['body']);
    }

    public function testOversizedResponseIsAbortedNotBuffered(): void
    {
        // 20 MiB body against a 10 MiB cap must fail, not return a truncated or empty "success".
        $r = self::agent()->get(self::base() . '/big.bin');
        $this->assertFalse($r, 'oversized body must be treated as a failed fetch');
    }

    public function testRedirectToNonAllowedInternalTargetIsNotFollowed(): void
    {
        $agent = self::agent();
        $agent->fetchAll(array(self::base() . '/redirect-internal'));
        $this->assertNotContains(
            'http://127.0.0.1:9/secret',
            array_column(array_filter(array_values((array)self::requestsOf($agent))), 'effective_url'),
            'redirect to internal target must not be requested'
        );
    }

    public function testRedirectToFileSchemeIsRejected(): void
    {
        // file:// must never be fetched, even as a redirect target.
        $agent = self::agent();
        $agent->fetchAll(array(self::base() . '/redirect-file'));
        $requests = self::requestsOf($agent);
        foreach ($requests as $req) {
            $this->assertStringStartsNotWith('file:', (string)($req['effective_url'] ?? ''));
        }
        $this->assertSame(false, self::agent()->validateUrl('file:///etc/passwd'));
    }

    public function testFollowsSafeRedirect(): void
    {
        $r = self::agent()->get(self::base() . '/redirect-ok');
        $this->assertIsArray($r);
        $this->assertStringContainsString('Fixture Article', (string)$r['body']);
    }

    public function testTimeoutFailsFastInsteadOfHanging(): void
    {
        $start = microtime(true);
        $r = self::agent(1)->get(self::base() . '/slow');
        $elapsed = microtime(true) - $start;
        $this->assertTrue($r === false || ($r['status_code'] === 0 && $r['body'] === ''), 'slow response must time out');
        $this->assertLessThan(4.0, $elapsed, 'timeout must cut the 5s response short');
    }

    public function testNonAllowedHostIsNeverRequested(): void
    {
        // Real public-looking name is not on the test allow-list and must be refused before any connection.
        $this->assertFalse(self::agent()->validateUrl('http://169.254.169.254/latest/meta-data/'));
        $this->assertFalse(self::agent()->validateUrl('http://localhost:' . FTR_FIXTURE_PORT . '/article.html'));
    }

    public function testInvalidFeedContentIsReturnedVerbatimForCallerToHandle(): void
    {
        // The fetcher is not responsible for parsing; it must deliver the body unchanged.
        $r = self::agent()->get(self::base() . '/broken-feed.xml');
        $this->assertIsArray($r);
        $this->assertStringContainsString('<rss><channel><title>Unclosed', $r['body']);
    }

    public function testValidGzipResponseDecompressesCleanly(): void
    {
        $r = self::agent()->get(self::base() . '/gzip-ok.html');
        $this->assertIsArray($r);
        $this->assertSame(200, $r['status_code']);
        $this->assertStringContainsString('Fixture Article', $r['body']);
    }

    public function testGzipBombExceedingDecompressedLimitFailsClosed(): void
    {
        // ~15 KiB over the wire expands to 15 MiB (> 10 MiB cap): must fail closed without OOM.
        $r = self::agent()->get(self::base() . '/gzip-bomb.html');
        $this->assertFalse($r, 'decompression bomb exceeding cap must fail closed');
    }

    public function testCorruptGzipResponseFailsClosedInsteadOfReturningBinaryGarbage(): void
    {
        $r = self::agent()->get(self::base() . '/gzip-corrupt.html');
        $this->assertFalse($r, 'corrupt gzip body must not be returned as valid HTML');
    }

    public function testMetaRefreshToBlockedTargetsIsNeverQueued(): void
    {
        $agent = self::agent();
        $agent->fetchAll(array(
            self::base() . '/meta-refresh-internal.html',
            self::base() . '/meta-refresh-file.html',
        ));
        $requests = self::requestsOf($agent);
        foreach ($requests as $url => $req) {
            $eff = (string)($req['effective_url'] ?? '');
            $this->assertStringNotContainsString(':9/pwn', $eff);
            $this->assertStringStartsNotWith('file:', $eff);
        }
    }

    public function testDualStackFallsBackToSecondPinnedAddressWhenFirstIsUnreachable(): void
    {
        $this->assertTrue(property_exists(SafeHttpUrl::class, 'testHostIpMap'), 'SafeHttpUrl::$testHostIpMap seam required');
        SafeHttpUrl::$testHostIpMap = array(
            'dualstack.test:' . FTR_FIXTURE_PORT => array('2606:4700:4700::1111', '127.0.0.1'),
        );
        $r = self::agent(3)->get('http://dualstack.test:' . FTR_FIXTURE_PORT . '/article.html');
        $this->assertIsArray($r, 'dual-stack host must fall back to reachable pinned IPv4');
        $this->assertSame(200, $r['status_code']);
        $this->assertStringContainsString('Fixture Article', $r['body']);
    }

    public function testRedirectLoopStopsAtConfiguredMaxRedirects(): void
    {
        $agent = new HumbleHttpAgent(array('timeout' => 3, 'max_redirects' => 3), HumbleHttpAgent::METHOD_CURL_MULTI);
        $r = $agent->get(self::base() . '/redirect-loop');
        $this->assertIsArray($r);
        $this->assertSame(302, $r['status_code']);
        $this->assertStringContainsString('h=3', (string)$r['effective_url']);
        $this->assertStringNotContainsString('h=5', (string)$r['effective_url']);
    }

    public function testProxyConfigurationNeverBypassesSsrfChecks(): void
    {
        SafeHttpUrl::$testAllowedHosts = null;
        $proxied = new HumbleHttpAgent(
            array('timeout' => 2, 'proxyhost' => '127.0.0.1:1'),
            HumbleHttpAgent::METHOD_CURL_MULTI
        );
        $this->assertFalse($proxied->validateUrl('http://127.0.0.1/admin'));
        $this->assertFalse($proxied->validateUrl('http://169.254.169.254/latest/meta-data/'));
        $this->assertFalse($proxied->validateUrl('file:///etc/passwd'));
    }

    /** Access the agent's per-URL request map for assertions. */
    private static function requestsOf(HumbleHttpAgent $agent): array
    {
        $prop = new ReflectionProperty(HumbleHttpAgent::class, 'requests');
        return (array)$prop->getValue($agent);
    }
}
