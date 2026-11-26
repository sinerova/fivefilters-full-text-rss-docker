<?php

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Runs makefulltextfeed.php against the local fixture feed and checks the RSS output.
 * Runs in a child PHP process so the endpoint's globals and exit() cannot affect the suite.
 */
#[Group('integration')]
final class FeedOutputTest extends TestCase
{
    private static function fixtureReachable(): bool
    {
        $fp = @fsockopen(FTR_FIXTURE_HOST, FTR_FIXTURE_PORT, $errno, $errstr, 1);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }

    private static ?string $staged = null;
    private static ?string $errorLog = null;

    private static function errorLogPath(): string
    {
        if (self::$errorLog === null) {
            self::$errorLog = sys_get_temp_dir() . '/ftrfeed-errors-' . bin2hex(random_bytes(4)) . '.log';
        }
        return self::$errorLog;
    }

    /** App copy plus the shipped custom_config.php, laid out as in the image. */
    public static function setUpBeforeClass(): void
    {
        $dir = sys_get_temp_dir() . '/ftrfeed-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(FTR_APP_DIR, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $e) {
            $t = $dir . substr($e->getPathname(), strlen(FTR_APP_DIR));
            $e->isDir() ? mkdir($t, 0777, true) : copy($e->getPathname(), $t);
        }
        copy(dirname(FTR_APP_DIR) . '/docker/custom_config.php', $dir . '/custom_config.php');
        self::$staged = $dir;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$staged === null) return;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::$staged, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $e) {
            $e->isDir() ? rmdir($e->getPathname()) : unlink($e->getPathname());
        }
        rmdir(self::$staged);
        self::$staged = null;
    }

    private static function render(
        string $feedUrl,
        array $params = array(),
        array $serverOverrides = array(),
        array $envOverrides = array(),
        string $entryScript = 'makefulltextfeed.php'
    ): string {
        $script = tempnam(sys_get_temp_dir(), 'ftr');
        $allowed = var_export(array(FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT), true);
        $app = var_export(self::$staged, true);
        $query = var_export(array_merge(array('url' => $feedUrl), $params), true);
        $server = var_export($serverOverrides, true);
        $envSetup = '';
        foreach ($envOverrides as $k => $v) {
            $envSetup .= 'putenv(' . var_export($k . '=' . $v, true) . ");\n";
        }
        $target = var_export('/' . ltrim($entryScript, '/'), true);
        file_put_contents($script, <<<PHP
<?php
{$envSetup}require {$app} . '/libraries/SafeHttpUrl.php';
SafeHttpUrl::\$testAllowedHosts = {$allowed};
\$_GET = \$_REQUEST = {$query};
\$_SERVER['HTTP_HOST'] = 'localhost';
\$_SERVER['SERVER_NAME'] = 'localhost';
\$_SERVER['SCRIPT_NAME'] = {$target};
\$_SERVER['REQUEST_URI'] = {$target};
foreach ({$server} as \$_k => \$_v) {
    \$_SERVER[\$_k] = \$_v;
}
chdir({$app});
include {$app} . {$target};
PHP);
        $devnull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        // Log every PHP diagnostic (including deprecations) so tearDown() can fail the test on any.
        $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d error_reporting=E_ALL -d log_errors=1 -d error_log='
            . escapeshellarg(self::errorLogPath()) . ' ' . escapeshellarg($script) . ' 2>' . $devnull;
        $out = (string)shell_exec($cmd);
        unlink($script);
        return $out;
    }

    protected function tearDown(): void
    {
        $log = is_file(self::errorLogPath()) ? (string)file_get_contents(self::errorLogPath()) : '';
        @unlink(self::errorLogPath());
        $this->assertDoesNotMatchRegularExpression(
            '/PHP (Deprecated|Warning|Notice|Fatal error|Parse error)/',
            $log,
            'The endpoint must not raise PHP diagnostics'
        );
    }

    protected function setUp(): void
    {
        @unlink(self::errorLogPath());
        if (!self::fixtureReachable()) {
            $this->markTestSkipped('Fixture server not running (see tests/README.md).');
        }
    }

    public function testFeedTitleIsEncodedOnceAndKeepsUtf8(): void
    {
        $xml = self::render('http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT . '/feed.xml');
        $this->assertStringContainsString('<title>Fix &amp; Feed ü</title>', $xml);
        $this->assertStringNotContainsString('&amp;amp;', $xml, 'title must not be double-encoded');
    }

    public function testItemTitleIsEncodedOnce(): void
    {
        $xml = self::render('http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT . '/feed.xml');
        $this->assertStringContainsString('<title>Item ü &amp; one</title>', $xml);
    }

    public function testOutputIsWellFormedXml(): void
    {
        $xml = self::render('http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT . '/feed.xml');
        $dom = new DOMDocument();
        $ok = $dom->loadXML($xml);
        $this->assertTrue($ok, 'feed output must parse as XML');
    }

    /** The XSS filter is on by default (custom_config.php), with no xss parameter needed. */
    public function testScriptsAndEventHandlersAreStrippedByDefault(): void
    {
        $xml = self::render('http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT . '/feed.xml');
        $this->assertStringNotContainsString('onclick', $xml);
        $this->assertStringNotContainsString('steal()', $xml);
        $this->assertStringNotContainsString('&lt;script', $xml);
        $this->assertStringNotContainsString('alert(', $xml);
    }

    public function testNormalContentSurvivesFiltering(): void
    {
        $xml = self::render('http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT . '/feed.xml');
        $this->assertStringContainsString('First paragraph of a long enough article body', $xml);
        $this->assertStringContainsString('Third paragraph with an event handler', $xml);
        $this->assertStringContainsString('&lt;p&gt;', $xml, 'paragraph markup is kept');
    }

    public function testExplicitXssZeroDoesNotDisableTheFilter(): void
    {
        $xml = self::render('http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT . '/feed.xml', array('xss' => '0'));
        $this->assertStringNotContainsString('onclick', $xml);
    }
    public function testRejectsUnsupportedSchemeWithoutFetching(): void
    {
        $out = self::render('file:///etc/passwd');
        $this->assertSame('Invalid URL supplied', trim($out));
    }

    public function testBlockedTargetsGetGenericErrorNotEmptyFeed(): void
    {
        foreach (array('http://127.0.0.1:9/x', 'http://169.254.169.254/latest/meta-data/', 'http://10.0.0.5/feed', 'http://[::1]/feed') as $target) {
            $out = trim(self::render($target));
            $this->assertSame('This URL cannot be fetched', $out, $target);
        }
    }

    public function testBlockedMessageDoesNotDiscloseNetworkDetails(): void
    {
        $out = self::render('http://10.0.0.5/feed');
        $this->assertStringNotContainsString('10.0.0.5', $out);
        $this->assertStringNotContainsString('private', strtolower($out));
        $this->assertStringNotContainsString('resolve', strtolower($out));
    }
    private static function nastyFeed(): string
    {
        return 'http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT . '/nasty-feed.xml';
    }

    public function testHtml5ParserOptionExtractsContent(): void
    {
        $xml = self::render(self::nastyFeed(), array('parser' => 'html5php'));
        $this->assertStringContainsString('First paragraph of a long enough article body', $xml);
        $this->assertStringContainsString('Second paragraph, also long enough', $xml);
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'output must be well-formed XML');
    }

    public function testHtml5ParserKeepsEntitiesAndUtf8(): void
    {
        $xml = self::render(self::nastyFeed(), array('parser' => 'html5php'));
        $this->assertStringContainsString('café', $xml);
        $this->assertStringContainsString('naïve', $xml);
        // The description is HTML inside XML, so a literal ampersand is escaped twice on the wire
        // and must decode to a single &amp; entity in the HTML.
        $this->assertStringContainsString('an ampersand &amp;amp; entity', $xml);
        $this->assertStringNotContainsString('&amp;amp;amp;', $xml);
    }

    public function testHtml5ParserNamespaceAndFormFeedPayloadsAreInert(): void
    {
        $xml = self::render(self::nastyFeed(), array('parser' => 'html5php'));
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
        $desc = $dom->getElementsByTagName('description');
        $body = '';
        foreach ($desc as $d) {
            $body .= $d->textContent;
        }
        $inner = new DOMDocument();
        @$inner->loadHTML('<?xml encoding="utf-8"?>' . $body);
        $this->assertSame(0, $inner->getElementsByTagName('script')->length);
        foreach ($inner->getElementsByTagName('*') as $el) {
            foreach ($el->attributes as $attr) {
                $this->assertStringStartsNotWith('on', $attr->name, 'no event handler survives: ' . $attr->name);
            }
        }
    }

    public function testHtml5ParserAndLibxmlBothExtractTheSameArticleText(): void
    {
        $a = self::render(self::nastyFeed(), array('parser' => 'html5php'));
        $b = self::render(self::nastyFeed(), array('parser' => 'libxml'));
        foreach (array($a, $b) as $xml) {
            $this->assertStringContainsString('First paragraph of a long enough article body', $xml);
            $this->assertStringContainsString('Final paragraph with ordinary content', $xml);
        }
    }

    public function testHtml5ParserIsRejectedWhenNotAllowedByConfig(): void
    {
        // allowed_parsers is a server setting; an unlisted value falls back to the default parser.
        $xml = self::render(self::nastyFeed(), array('parser' => 'nonexistent'));
        $this->assertStringContainsString('First paragraph of a long enough article body', $xml);
    }
    public function testDcIdentifierIsPerItemNotStaleFromPreviousItem(): void
    {
        $feed = 'http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT . '/identifier-feed.xml';
        $xml = self::render($feed);
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'output must be well-formed XML');
        $items = $dom->getElementsByTagName('item');
        $this->assertSame(3, $items->length);
        $base = 'http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT;
        $expected = array(
            $base . '/article.html',
            $base . '/gone.html',
            $base . '/nasty.html',
        );
        foreach ($items as $n => $item) {
            $ids = array();
            foreach ($item->getElementsByTagName('identifier') as $id) {
                $ids[] = $id->textContent;
            }
            $this->assertSame(array($expected[$n]), $ids, 'item ' . ($n + 1) . ' must have exactly its own dc:identifier');
        }
    }

    public function testRss2StructureMatchesFreshRssReaderExpectations(): void
    {
        $xml = self::render('http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT . '/feed.xml');
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'output must be well-formed XML');
        $root = $dom->documentElement;
        $this->assertSame('rss', $root->nodeName);
        $this->assertSame('2.0', $root->getAttribute('version'));
        $channel = $root->getElementsByTagName('channel');
        $this->assertSame(1, $channel->length);
        $items = $channel->item(0)->getElementsByTagName('item');
        $this->assertGreaterThan(0, $items->length);
        foreach ($items as $item) {
            foreach (array('title', 'link', 'guid', 'description') as $tag) {
                $this->assertGreaterThan(0, $item->getElementsByTagName($tag)->length, "item lacks <$tag>");
            }
        }
    }

    public function testRss2CarriesOnlyOneAtomSelfLinkAndTheXslReference(): void
    {
        $xml = self::render('http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT . '/feed.xml');
        $this->assertStringContainsString('<?xml-stylesheet type="text/xsl" href="css/feed.xsl"?>', $xml);
        $this->assertSame(1, substr_count($xml, 'rel="self"'));
    }

    public function testJsonOutputHasRssChannelAndItemShape(): void
    {
        $json = self::render('http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT . '/feed.xml', array('format' => 'json'));
        $doc = json_decode($json, true);
        $this->assertIsArray($doc, 'JSON output must decode');
        $this->assertSame('2.0', $doc['rss']['@attributes']['version']);
        $this->assertArrayHasKey('channel', $doc['rss']);
        $this->assertArrayHasKey('title', $doc['rss']['channel']);
        $this->assertArrayHasKey('item', $doc['rss']['channel']);
    }

    public function testJsonOutputUsesIdentifierKeysWithoutColons(): void
    {
        $json = self::render('http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT . '/identifier-feed.xml', array('format' => 'json'));
        $this->assertStringContainsString('"dc_identifier"', $json);
        $this->assertStringNotContainsString('"dc:identifier"', $json);
    }

    public function testRssOutputOmitsOpenGraphAndTwitterTags(): void
    {
        $xml = self::render('http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT . '/feed.xml');
        $this->assertDoesNotMatchRegularExpression('/<(og|twitter):/', $xml);
    }

    public function testJsonpIsDisabledByDefault(): void
    {
        $out = self::render(
            'http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT . '/feed.xml',
            array('format' => 'json', 'callback' => 'ftrCb')
        );
        $this->assertStringStartsNotWith('ftrCb(', $out, 'JSONP must be disabled by default');
        $this->assertStringContainsString('JSONP', $out);
    }

    public function testJsonpWhenExplicitlyEnabledValidatesCallbackStrictly(): void
    {
        $feedUrl = 'http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT . '/feed.xml';
        $env = array('ftr_allow_jsonp' => 'true');

        $out = self::render($feedUrl, array('format' => 'json', 'callback' => 'ns.ftrCb_1'), array(), $env);
        $this->assertStringStartsWith('ns.ftrCb_1(', $out);
        $this->assertStringEndsWith(');', rtrim($out));
        $inner = substr(trim($out), strlen('ns.ftrCb_1('), -2);
        $doc = json_decode($inner, true);
        $this->assertIsArray($doc, 'JSONP payload must be valid JSON');
        $this->assertSame('Item ü & one', $doc['rss']['channel']['item']['title']);

        // Old regex allowed arbitrary JS expressions inside quoted bracket notation: foo["x"+alert(1)+""]
        $bypass = 'foo["x"+alert(1)+""]';
        $bad = self::render($feedUrl, array('format' => 'json', 'callback' => $bypass), array(), $env);
        $this->assertStringNotContainsString('alert(1)', $bad);
        $this->assertStringContainsString('Invalid JSONP callback', $bad);

        $bad2 = self::render($feedUrl, array('format' => 'json', 'callback' => 'alert(1)//'), array(), $env);
        $this->assertStringContainsString('Invalid JSONP callback', $bad2);
    }

    public function testHostileFeedExtractedTitlesAndJavascriptLinksAreSanitized(): void
    {
        $base = 'http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT;

        // Feed mode with use_extracted_title=1 exercises $extracted_title and javascript: links.
        $xml = self::render($base . '/hostile-feed.xml', array('use_extracted_title' => '1'));
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'hostile feed output must be valid XML');
        $this->assertDoesNotMatchRegularExpression('/(?<!denied:)javascript:/i', $xml);
        $xp = new DOMXPath($dom);
        foreach ($xp->query('//link | //guid | //*[local-name()="identifier"] | //*[local-name()="link"]/@href') as $node) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(javascript|data|vbscript):/i', $node->nodeValue);
        }
        foreach ($xp->query('//title') as $t) {
            $this->assertStringNotContainsString('<img', $t->textContent);
            $this->assertStringNotContainsString('<script', $t->textContent);
            $this->assertStringNotContainsString('onerror', $t->textContent);
        }

        // Single-page dummy feed mode exercises lines 888-889 ($output->setTitle / $newitem->setTitle).
        $singleXml = self::render($base . '/hostile-article.html');
        $singleDom = new DOMDocument();
        $this->assertTrue($singleDom->loadXML($singleXml));
        $this->assertDoesNotMatchRegularExpression('/(?<!denied:)javascript:/i', $singleXml);
        $xp2 = new DOMXPath($singleDom);
        foreach ($xp2->query('//title') as $t) {
            $this->assertSame('Hostile Extracted Title', trim($t->textContent));
        }
    }

    public function testUntitledAndFailedExtractionFeedStillRendersValidRssAndJson(): void
    {
        $base = 'http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT;

        $xml = self::render($base . '/untitled-fail-feed.xml');
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'untitled + failed extraction must still produce valid XML');
        $sp = new SimplePie();
        $sp->set_raw_data($xml);
        $sp->enable_cache(false);
        $this->assertTrue($sp->init(), (string)$sp->error());

        $json = self::render($base . '/untitled-fail-feed.xml', array('format' => 'json'));
        $doc = json_decode($json, true);
        $this->assertIsArray($doc, 'untitled + failed extraction JSON must decode');

        $simple = self::render($base . '/gone.html', array(), array(), array(), 'extract.php');
        $simpleDoc = json_decode($simple, true);
        $this->assertIsArray($simpleDoc, 'extract.php on 404 target must still return valid JSON');
    }

    public function testMultipagePaginationLoopIsBounded(): void
    {
        $base = 'http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT;
        $ruleFile = self::$staged . '/site_config/custom/' . FTR_FIXTURE_HOST . '.txt';
        @mkdir(dirname($ruleFile), 0777, true);
        file_put_contents($ruleFile, "body: //article\nnext_page_link: //a[@rel='next']/@href\n");
        try {
            $xml = self::render($base . '/page-loop?p=1');
        } finally {
            @unlink($ruleFile);
        }
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'bounded multipage output must be valid XML');
        $this->assertStringContainsString('PageMarker-1', $xml);
        $this->assertStringContainsString('PageMarker-2', $xml);
        $this->assertStringNotContainsString('PageMarker-25', $xml, 'multipage traversal must stop at the configured page cap');
    }

    public function testExcessiveFeedEntriesAreCappedByDefaultAndMaxEntries(): void
    {
        $base = 'http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT;

        $defaultXml = self::render($base . '/many-feed.xml');
        $dom1 = new DOMDocument();
        $this->assertTrue($dom1->loadXML($defaultXml));
        $defaultCount = $dom1->getElementsByTagName('item')->length;
        $this->assertGreaterThan(0, $defaultCount);
        $this->assertLessThanOrEqual(10, $defaultCount, 'default_entries should cap unparameterized feed fetches conservatively');

        $maxXml = self::render($base . '/many-feed.xml', array('max' => '999'));
        $dom2 = new DOMDocument();
        $this->assertTrue($dom2->loadXML($maxXml));
        $maxCount = $dom2->getElementsByTagName('item')->length;
        $this->assertGreaterThan($defaultCount, $maxCount);
        $this->assertLessThanOrEqual(25, $maxCount, 'max_entries should cap &max=999 below the 40-item fixture');

        // &max=0 must NOT trigger SimplePie::get_items(0, 0) unbounded slice.
        $zeroXml = self::render($base . '/many-feed.xml', array('max' => '0'));
        $dom3 = new DOMDocument();
        $this->assertTrue($dom3->loadXML($zeroXml));
        $this->assertSame(1, $dom3->getElementsByTagName('item')->length, '&max=0 must clamp to 1, not return all items');
    }

    public function testUntrustedForwardedAndHostHeadersAreNotTrustedByDefault(): void
    {
        $base = 'http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT;
        $xml = self::render(
            $base . '/feed.xml',
            array(),
            array(
                'HTTP_HOST' => "evil.example.org\"><script>alert(1)</script>",
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => '8080',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'REMOTE_ADDR' => '203.0.113.9',
            )
        );
        $this->assertStringNotContainsString('evil.example.org', $xml, 'untrusted Host header must not poison self links');
        $this->assertStringNotContainsString('https://localhost', $xml, 'untrusted X-Forwarded-Proto must not flip scheme to https');
    }

    public function testEndpointRssIsReadableBySimplePie(): void
    {
        $xml = self::render('http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT . '/feed.xml');
        $sp = new SimplePie();
        $sp->set_raw_data($xml);
        $sp->enable_cache(false);
        $this->assertTrue($sp->init(), (string)$sp->error());
        $this->assertSame('Fix & Feed ü', html_entity_decode($sp->get_title(), ENT_QUOTES, 'UTF-8'));
        $items = $sp->get_items();
        $this->assertCount(1, $items);
        $this->assertSame('Item ü & one', html_entity_decode($items[0]->get_title(), ENT_QUOTES, 'UTF-8'));
        $this->assertSame('g1', $items[0]->get_id());
    }
}
