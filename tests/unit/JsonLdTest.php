<?php

use PHPUnit\Framework\TestCase;

require_once FTR_APP_DIR . '/libraries/JsonLd.php';

/** JSON-LD title/author/date extraction (3.9.7, with the 3.9.9 and 3.9.10 fixes). */
final class JsonLdTest extends TestCase
{
    private static function article(array $extra = array(), string $type = 'NewsArticle'): string
    {
        return json_encode(array_merge(array('@context' => 'https://schema.org', '@type' => $type), $extra));
    }

    public function testReadsHeadlineAuthorAndDate(): void
    {
        $r = JsonLd::extract(array(self::article(array(
            'headline' => 'A Headline',
            'author' => array('@type' => 'Person', 'name' => 'Ada Lovelace'),
            'datePublished' => '2021-02-11T10:30:00+00:00',
        ))));
        $this->assertSame('A Headline', $r['title']);
        $this->assertSame(array('Ada Lovelace'), $r['authors']);
        $this->assertSame(gmmktime(10, 30, 0, 2, 11, 2021), $r['date']);
    }

    public function testAuthorAsStringListAndOrganization(): void
    {
        $this->assertSame(array('Solo'), JsonLd::extract(array(self::article(array('author' => 'Solo'))))['authors']);
        $r = JsonLd::extract(array(self::article(array('author' => array(
            array('@type' => 'Person', 'name' => 'One'),
            'Two',
            array('@type' => 'Organization', 'name' => 'Three Inc'),
            array('@type' => 'Person', 'name' => 'One'),
        )))));
        $this->assertSame(array('One', 'Two', 'Three Inc'), $r['authors'], 'order kept, duplicates removed');
    }

    public function testFindsArticleInsideGraphAndResolvesAuthorReference(): void
    {
        $graph = json_encode(array('@context' => 'https://schema.org', '@graph' => array(
            array('@type' => 'WebPage', '@id' => '#page', 'name' => 'Site page name'),
            array('@type' => array('Article', 'NewsArticle'), 'headline' => 'Graph headline', 'author' => array('@id' => '#/schema/person/7'), 'datePublished' => '2020-04-24'),
            array('@type' => 'Person', '@id' => '#/schema/person/7', 'name' => 'Grace Hopper'),
        )));
        $r = JsonLd::extract(array($graph));
        $this->assertSame('Graph headline', $r['title']);
        $this->assertSame(array('Grace Hopper'), $r['authors']);
        $this->assertNotNull($r['date']);
    }

    public function testTopLevelListAndSchemaOrgTypeIris(): void
    {
        $list = json_encode(array(
            array('@type' => 'BreadcrumbList'),
            array('@type' => 'https://schema.org/BlogPosting', 'name' => 'Falls back to name'),
        ));
        $this->assertSame('Falls back to name', JsonLd::extract(array($list))['title']);
        $this->assertSame('Prefixed', JsonLd::extract(array(self::article(array('headline' => 'Prefixed'), 'schema:TechArticle')))['title']);
    }

    public function testHeadlineWinsOverNameAndDateCreatedIsAFallback(): void
    {
        $r = JsonLd::extract(array(self::article(array('headline' => 'H', 'name' => 'N', 'dateCreated' => '2019-03-28'))));
        $this->assertSame('H', $r['title']);
        $this->assertSame(strtotime('2019-03-28'), $r['date']);
    }

    public function testTextIsReducedToPlainText(): void
    {
        $r = JsonLd::extract(array(self::article(array(
            'headline' => "  Tom &amp; Jerry&#8217;s <b>big</b>\n\tday <script>alert(1)</script>",
            'author' => 'A &lt;b&gt;',
        ))));
        $this->assertSame("Tom & Jerry\u{2019}s big day alert(1)", $r['title']);
        $this->assertSame(array('A'), $r['authors'], 'entity-encoded markup is removed, not decoded into live markup');
    }

    public function testEntityEncodedMarkupNeverSurvivesDecoding(): void
    {
        $r = JsonLd::extract(array(self::article(array(
            'headline' => 'T &lt;img src=x onerror=alert(1)&gt; end &amp;lt;script&amp;gt;',
            'author' => 'Ann &lt;img src=x onerror=alert(2)&gt;',
        ))));
        foreach (array($r['title'], $r['authors'][0]) as $text) {
            $this->assertDoesNotMatchRegularExpression('/<\/?[a-z]/i', $text, $text);
        }
        $this->assertSame('Ann', $r['authors'][0]);
    }

    public function testPlainLessThanSurvives(): void
    {
        $this->assertSame('5 < 6 and 7 > 4', JsonLd::extract(array(self::article(array('headline' => '5 &lt; 6 and 7 &gt; 4'))))['title']);
    }

    public function testNonScalarAndMalformedValuesAreIgnored(): void
    {
        $r = JsonLd::extract(array(self::article(array(
            'headline' => array('nested' => 'x'),
            'name' => 12345,
            'author' => array('name' => array('x')),
            'datePublished' => array('2020-01-01'),
            'dateCreated' => 'not a date at all',
        ))));
        $this->assertSame(array('title' => null, 'authors' => array(), 'date' => null), $r);
    }

    public function testInvalidJsonAndNonArticlesYieldNothing(): void
    {
        $empty = array('title' => null, 'authors' => array(), 'date' => null);
        $this->assertSame($empty, JsonLd::extract(array('{not json', '', '"just a string"', '123', 'null')));
        $this->assertSame($empty, JsonLd::extract(array(self::article(array('headline' => 'x'), 'Product'))));
        $this->assertSame($empty, JsonLd::extract(array(self::article(array('title' => 'x'), 'JobPosting'))));
        $this->assertSame($empty, JsonLd::extract(array()));
    }

    public function testPageWithSeveralDistinctArticlesIsIgnored(): void
    {
        $r = JsonLd::extract(array(
            self::article(array('headline' => 'First', 'author' => 'A')),
            self::article(array('headline' => 'Second', 'author' => 'B')),
        ));
        $this->assertNull($r['title']);
        $this->assertSame(array(), $r['authors']);
    }

    public function testTheSameArticleRepeatedIsStillOneArticle(): void
    {
        $one = self::article(array('headline' => 'Same'));
        $this->assertSame('Same', JsonLd::extract(array($one, $one))['title']);
    }

    public function testCdataWrapperAndBomAreTolerated(): void
    {
        $json = self::article(array('headline' => 'Wrapped'));
        $this->assertSame('Wrapped', JsonLd::extract(array("//<![CDATA[\n$json\n//]]>"))['title']);
        $this->assertSame('Wrapped', JsonLd::extract(array("\xEF\xBB\xBF$json"))['title']);
    }

    public function testInvalidUtf8IsSubstitutedNotFatal(): void
    {
        $r = JsonLd::extract(array('{"@type":"Article","headline":"Bad ' . "\xC3\x28" . ' bytes"}'));
        $this->assertIsString($r['title']);
        $this->assertStringStartsWith('Bad ', $r['title']);
    }

    public function testLimits(): void
    {
        $long = JsonLd::extract(array(self::article(array('headline' => str_repeat('é', 5000)))));
        $this->assertSame(JsonLd::MAX_TITLE, mb_strlen($long['title'], 'UTF-8'));

        $many = array_map(fn($i) => "Author $i", range(1, 100));
        $r = JsonLd::extract(array(self::article(array('author' => $many))));
        $this->assertCount(JsonLd::MAX_AUTHORS, $r['authors']);

        $this->assertNull(JsonLd::extract(array(str_repeat(' ', JsonLd::MAX_BYTES) . self::article(array('headline' => 'Too big'))))['title'], 'oversized script ignored');

        $deep = str_repeat('[', 200) . str_repeat(']', 200);
        $this->assertNull(JsonLd::extract(array($deep))['title'], 'JSON deeper than the cap is ignored');

        $scripts = array_fill(0, JsonLd::MAX_SCRIPTS, '{}');
        $scripts[] = self::article(array('headline' => 'Eleventh script'));
        $this->assertNull(JsonLd::extract($scripts)['title'], 'only the first scripts are read');
    }

    public function testNoStateCarriesOverBetweenCalls(): void
    {
        $first = JsonLd::extract(array(self::article(array('headline' => 'First', 'author' => 'A', 'datePublished' => '2020-01-01'))));
        $this->assertSame('First', $first['title']);
        $second = JsonLd::extract(array(self::article(array('headline' => 'Second'))));
        $this->assertSame('Second', $second['title']);
        $this->assertSame(array(), $second['authors']);
        $this->assertNull($second['date']);
    }

    public function testSelfReferencingAuthorDoesNotLoop(): void
    {
        $json = json_encode(array('@graph' => array(
            array('@type' => 'Article', 'headline' => 'Loop', 'author' => array('@id' => '#a')),
            array('@id' => '#a', 'author' => array('@id' => '#a')),
        )));
        $this->assertSame(array(), JsonLd::extract(array($json))['authors']);
    }
}
