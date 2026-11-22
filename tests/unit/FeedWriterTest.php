<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests the feed output adapter (app/libraries/feedwriter) without the HTTP endpoint.
 * RSS is produced by the pinned upstream mibe/FeedWriter v1.1.2 (see upstream/).
 */
final class FeedWriterTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once FTR_APP_DIR . '/libraries/feedwriter/FeedWriter.php';
    }

    private static function sample(int $format = RSS2): FeedWriter
    {
        $f = new FeedWriter($format);
        $f->setTitle('Fix & Feed ü');
        $f->setDescription('desc <b>x</b>');
        $f->setLink('https://example.com/');
        $f->setXsl('css/feed.xsl');
        $f->setSelf('https://ftr.example/makefulltextfeed.php?url=x&max=2');
        $f->setAlternate('https://example.com/feed', 'Source URL');

        $i = $f->createNewItem();
        $i->setTitle('Item ü & one');
        $i->setLink('https://example.com/a?x=1&y=2');
        $i->setDescription('<p>First <script>alert(1)</script></p>');
        $i->setElement('content:encoded', '<p>Full</p>');
        $i->addElement('dc:creator', 'Alice');
        $i->addElement('dc:creator', 'Bob');
        $i->addElement('category', 'news');
        $i->setElement('guid', 'https://example.com/a', array('isPermaLink' => 'true'));
        $i->setDate(1700000000);
        $i->addElement('dc:identifier', 'https://example.com/final');
        $i->addElement('og:title', 'OG only');
        $i->addElement('twitter:site', '@only');
        $f->addItem($i);
        return $f;
    }

    private static function capture(callable $fn): string
    {
        ob_start();
        $fn();
        return (string)ob_get_clean();
    }

    public function testRssIsWellFormedWithEscapedTitles(): void
    {
        $xml = self::sample()->rssDocument();
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'RSS must parse as XML');
        $this->assertStringContainsString('<title>Fix &amp; Feed ü</title>', $xml);
        $this->assertStringContainsString('<title>Item ü &amp; one</title>', $xml);
        $this->assertStringNotContainsString('&amp;amp;', $xml);
    }

    public function testRssKeepsEveryRepeatedElement(): void
    {
        $dom = new DOMDocument();
        $dom->loadXML(self::sample()->rssDocument());
        $creators = array();
        foreach ($dom->getElementsByTagName('creator') as $c) {
            $creators[] = $c->textContent;
        }
        $this->assertSame(array('Alice', 'Bob'), $creators);
    }

    /**
     * Element names the previous (2008) FeedWriter produced for the same item, in document
     * order. Pins the output shape so a library change cannot silently drop an element.
     */
    public function testItemElementsMatchPreviousOutput(): void
    {
        $dom = new DOMDocument();
        $dom->loadXML(self::sample()->rssDocument());
        $names = array();
        foreach ($dom->getElementsByTagName('item')->item(0)->childNodes as $n) {
            if ($n->nodeType === XML_ELEMENT_NODE) {
                $names[] = $n->nodeName;
            }
        }
        $this->assertSame(
            array('title', 'link', 'description', 'content:encoded', 'dc:creator', 'dc:creator', 'category', 'guid', 'pubDate', 'dc:identifier'),
            $names
        );
    }

    public function testRssOmitsOpenGraphAndTwitterElements(): void
    {
        $xml = self::sample()->rssDocument();
        $this->assertStringNotContainsString('og:title', $xml);
        $this->assertStringNotContainsString('twitter:site', $xml);
    }

    public function testRssGuidIsPermalinkWithAttribute(): void
    {
        $xml = self::sample()->rssDocument();
        $this->assertStringContainsString('<guid isPermaLink="true">https://example.com/a</guid>', $xml);
    }

    public function testRssHasItemDateAndLinkAndSelfAndXsl(): void
    {
        $xml = self::sample()->rssDocument();
        $this->assertStringContainsString('<pubDate>Tue, 14 Nov 2023 22:13:20 +0000</pubDate>', $xml);
        $this->assertStringContainsString('<link>https://example.com/a?x=1&amp;y=2</link>', $xml);
        $this->assertSame(1, substr_count($xml, 'rel="self"'));
        $this->assertStringContainsString('<?xml-stylesheet type="text/xsl" href="css/feed.xsl"?>', $xml);
    }

    public function testRssDescriptionIsEscapedNotCdata(): void
    {
        $xml = self::sample()->rssDocument();
        $this->assertStringNotContainsString('CDATA', $xml);
        $this->assertStringContainsString('&lt;p&gt;First', $xml);
    }

    public function testJsonHasRssChannelShapeAndMultiValueArrays(): void
    {
        $f = self::sample(JSON);
        $out = self::capture(function () use ($f) {
            $f->generateFeed();
        });
        $doc = json_decode($out, true);
        $this->assertSame('2.0', $doc['rss']['@attributes']['version']);
        $item = $doc['rss']['channel']['item'];
        $this->assertSame(array('Alice', 'Bob'), $item['dc_creator']);
        $this->assertSame('https://example.com/final', $item['dc_identifier']);
        $this->assertSame('OG only', $item['og_title']);
        $this->assertSame('@only', $item['twitter_site']);
    }

    public function testJsonSimpleModeReturnsFirstItemFields(): void
    {
        $f = self::sample(JSON);
        $f->enableSimpleJson();
        $out = self::capture(function () use ($f) {
            $f->generateFeed();
        });
        $doc = json_decode($out, true);
        $this->assertSame('Item ü & one', $doc['title']);
        $this->assertSame('https://example.com/final', $doc['effective_url']);
        $this->assertSame('example.com', $doc['domain']);
        $this->assertSame('OG only', $doc['og_title']);
    }

    public function testRssDeclaresNamespacesUsedByItems(): void
    {
        $dom = new DOMDocument();
        $dom->loadXML(self::sample()->rssDocument());
        $root = $dom->documentElement;
        $this->assertSame('2.0', $root->getAttribute('version'));
        foreach (array('atom' => 'http://www.w3.org/2005/Atom', 'content' => 'http://purl.org/rss/1.0/modules/content/', 'dc' => 'http://purl.org/dc/elements/1.1/') as $prefix => $uri) {
            $this->assertSame($uri, $root->lookupNamespaceURI($prefix), "xmlns:$prefix");
        }
        $this->assertSame('<p>Full</p>', $dom->getElementsByTagNameNS('http://purl.org/rss/1.0/modules/content/', 'encoded')->item(0)->textContent);
        $this->assertSame('https://example.com/final', $dom->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'identifier')->item(0)->textContent);
    }

    public function testRssMediaElementsAreNamespacedAndWellFormed(): void
    {
        $f = self::sample();
        $i = $f->createNewItem();
        $i->setTitle('Media');
        $i->setLink('https://example.com/m');
        $i->addElement('media:content', '', array('url' => 'https://example.com/m.jpg', 'type' => 'image/jpeg'));
        $i->addElement('media:thumbnail', '', array('url' => 'https://example.com/t.jpg'));
        $f->addItem($i);
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($f->rssDocument()), 'prefixes must be declared');
        $ns = 'http://search.yahoo.com/mrss/';
        $this->assertSame('https://example.com/m.jpg', $dom->getElementsByTagNameNS($ns, 'content')->item(0)->getAttribute('url'));
        $this->assertSame(1, $dom->getElementsByTagNameNS($ns, 'thumbnail')->length);
    }

    public function testRssAtomLinksSelfAlternateRelated(): void
    {
        $f = self::sample();
        $f->setRelated('https://example.org/subscribe?x=1&y=2', 'Subscribe');
        $dom = new DOMDocument();
        $dom->loadXML($f->rssDocument());
        $rels = array();
        foreach ($dom->getElementsByTagNameNS('http://www.w3.org/2005/Atom', 'link') as $l) {
            $rels[$l->getAttribute('rel')] = $l->getAttribute('href');
        }
        $this->assertSame('https://ftr.example/makefulltextfeed.php?url=x&max=2', $rels['self']);
        $this->assertSame('https://example.com/feed', $rels['alternate']);
        $this->assertSame('https://example.org/subscribe?x=1&y=2', $rels['related']);
    }

    public function testRssIsReadableBySimplePieLikeFreshRss(): void
    {
        require_once FTR_APP_DIR . '/libraries/simplepie/autoloader.php';
        $sp = new SimplePie();
        $sp->set_raw_data(self::sample()->rssDocument());
        $sp->enable_cache(false);
        $this->assertTrue($sp->init(), (string)$sp->error());
        $this->assertSame('Fix & Feed ü', html_entity_decode($sp->get_title(), ENT_QUOTES, 'UTF-8'));
        $items = $sp->get_items();
        $this->assertCount(1, $items);
        $this->assertSame('Item ü & one', html_entity_decode($items[0]->get_title(), ENT_QUOTES, 'UTF-8'));
        $this->assertSame('https://example.com/a?x=1&y=2', html_entity_decode($items[0]->get_link(), ENT_QUOTES, 'UTF-8'));
        $this->assertSame('https://example.com/a', $items[0]->get_id());
        $this->assertSame(1700000000, $items[0]->get_date('U'));
        $this->assertStringContainsString('<p>Full</p>', $items[0]->get_content());
        $this->assertSame('Alice', $items[0]->get_authors()[0]->get_name());
    }

    public function testJsonSingleItemIsObjectAndManyItemsAreArray(): void
    {
        $f = self::sample(JSON);
        $doc = $f->jsonDocument();
        $this->assertIsObject($doc['rss']['channel']->item);
        $second = $f->createNewItem();
        $second->setTitle('Two');
        $f->addItem($second);
        $doc = $f->jsonDocument();
        $this->assertIsArray($doc['rss']['channel']->item);
        $this->assertCount(2, $doc['rss']['channel']->item);
    }

    public function testEmptyFeedStillProducesValidRss(): void
    {
        $f = new FeedWriter(RSS2);
        $f->setTitle('Empty');
        $f->setLink('https://example.com/');
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($f->rssDocument()));
        $this->assertSame(0, $dom->getElementsByTagName('item')->length);
    }

    public function testEmptyChannelTitleDescriptionAndLinkProduceValidRss(): void
    {
        $f = new FeedWriter(RSS2);
        $f->setTitle('');
        $f->setDescription('');
        $f->setLink('');
        $f->setAlternate('', '');
        $item = $f->createNewItem();
        $item->setTitle('');
        $item->setDescription('');
        $item->setLink('');
        $f->addItem($item);

        $xml = $f->rssDocument();
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'empty channel/item fields must not crash or break XML');

        require_once FTR_APP_DIR . '/libraries/simplepie/autoloader.php';
        $sp = new SimplePie();
        $sp->set_raw_data($xml);
        $sp->enable_cache(false);
        $this->assertTrue($sp->init(), (string)$sp->error());
    }

    public function testZeroStringChannelAndImageFieldsDoNotCrash(): void
    {
        $f = new FeedWriter(RSS2);
        $f->setTitle('0');
        $f->setDescription('0');
        $f->setLink('0');
        $f->setAlternate('https://example.com/feed.xml', '0');
        $f->setImage('0', '0', 'https://example.com/logo.png');

        $item = $f->createNewItem();
        $item->setTitle('0');
        $item->setDescription('0');
        $item->setLink('0');
        $f->addItem($item);

        $xml = $f->rssDocument();
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml), '"0" fields must not trigger empty() exceptions');
        $this->assertStringContainsString('<title>0</title>', $xml);
        $this->assertStringContainsString('<description>0</description>', $xml);
        $this->assertStringContainsString('<link>https://example.com/feed.xml</link>', $xml);

        $json = $f->jsonDocument();
        $this->assertSame('0', $json['rss']['channel']->title);
        $this->assertSame('0', $json['rss']['channel']->description);
        $this->assertSame('https://example.com/feed.xml', $json['rss']['channel']->link);
    }

    public function testUnsafeLinkSchemesAreRejectedInRssAndJson(): void
    {
        $f = new FeedWriter(RSS2);
        $f->setTitle('Safe');
        $f->setDescription('Safe desc');
        $f->setLink('javascript:alert(1)');
        $f->setAlternate('data:text/html,<script>alert(1)</script>', 'Source');
        $f->setRelated('vbscript:msgbox(1)', 'Sub');

        $item = $f->createNewItem();
        $item->setTitle('Entry');
        $item->setDescription('Body');
        $item->setLink(" \tjavascript:alert(2)");
        $f->addItem($item);

        $xml = $f->rssDocument();
        $this->assertStringNotContainsString('javascript:', strtolower($xml));
        $this->assertStringNotContainsString('data:text/html', strtolower($xml));
        $this->assertStringNotContainsString('vbscript:', strtolower($xml));

        $json = json_encode($f->jsonDocument());
        $this->assertStringNotContainsString('javascript:', strtolower((string)$json));
    }

    public function testFeedXslNeverDisablesOutputEscapingAndGuardsLinks(): void
    {
        $xslPath = FTR_APP_DIR . '/css/feed.xsl';
        $xslDom = new DOMDocument();
        $this->assertTrue($xslDom->load($xslPath), 'feed.xsl must be valid XML');
        $xp = new DOMXPath($xslDom);
        $unescaped = $xp->query('//*[@disable-output-escaping="yes"]');
        $this->assertSame(0, $unescaped->length, 'feed.xsl must not use disable-output-escaping="yes"');
        $xslRaw = (string)file_get_contents($xslPath);
        $this->assertStringContainsString("starts-with(", $xslRaw, 'feed.xsl must guard href schemes');
    }
}
