<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once FTR_APP_DIR . '/libraries/content-extractor/SiteConfig.php';
require_once FTR_APP_DIR . '/libraries/content-extractor/ContentExtractor.php';

/** Fingerprints shipped in config.php and in the image's custom_config.php (3.9.12: Substack on custom domains). */
final class FingerprintTest extends TestCase
{
    public static function configFiles(): array
    {
        return [
            'app/config.php defaults' => [FTR_APP_DIR . '/config.php'],
            'docker/custom_config.php' => [dirname(FTR_APP_DIR) . '/docker/custom_config.php'],
        ];
    }

    /** Evaluate only the fingerprints array, so the test does not depend on the rest of the config. */
    private static function fingerprintsIn(string $file): array
    {
        $src = (string)file_get_contents($file);
        if (!preg_match('/\$options->fingerprints\s*=\s*(array\(.*?\n\));/s', $src, $m)) {
            throw new RuntimeException("no fingerprints array in $file");
        }
        return eval('return ' . $m[1] . ';');
    }

    private static function hostFor(string $file, string $html)
    {
        $x = new ContentExtractor(sys_get_temp_dir(), null);
        $x->fingerprints = self::fingerprintsIn($file);
        return $x->findHostUsingFingerprints($html);
    }

    #[DataProvider('configFiles')]
    public function testSubstackPagesOnCustomDomainsGetTheSubstackConfig(string $file): void
    {
        $head = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"/>'
            . '<link rel="preconnect" href="https://substackcdn.com" />'
            . '<title>Some Newsletter</title></head><body></body></html>';
        $this->assertSame('fingerprint.substack.com', self::hostFor($file, $head));
    }

    #[DataProvider('configFiles')]
    public function testOrdinaryPagesAndNearMissesDoNotMatchSubstack(string $file): void
    {
        $this->assertFalse(self::hostFor($file, '<html><head><title>x</title></head><body>a plain page</body></html>'));
        // mentioning substackcdn.com in the body, or preconnecting to another host, is not a Substack page
        $this->assertFalse(self::hostFor($file, '<html><head></head><body>' . str_repeat('x', 9000) . '<link rel="preconnect" href="https://substackcdn.com" /></body></html>'));
        $this->assertFalse(self::hostFor($file, '<html><head><link rel="preconnect" href="https://cdn.example.com" /></head></html>'));
    }

    #[DataProvider('configFiles')]
    public function testExistingFingerprintsStillMatch(string $file): void
    {
        $this->assertSame('fingerprint.wordpress.com', self::hostFor($file, '<html><head><meta name="generator" content="WordPress 6.5" /></head></html>'));
        $this->assertSame('fingerprint.blogspot.com', self::hostFor($file, '<html><head><meta name="generator" content="Blogger"></head></html>'));
    }
}
