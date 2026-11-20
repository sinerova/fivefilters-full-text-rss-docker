<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CVE-2026-111234: SimplePie <= 1.9.0 reads local files via file:// in redirects or
 * autodiscovery links. Feeds are fetched through SimplePie_HumbleHttpAgent, which
 * overrides SimplePie's File class and must never touch the local filesystem.
 */
final class SimplePieFileSchemeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once FTR_APP_DIR . '/libraries/simplepie/autoloader.php';
        require_once FTR_APP_DIR . '/libraries/humble-http-agent/HumbleHttpAgent.php';
        require_once FTR_APP_DIR . '/libraries/humble-http-agent/SimplePie_HumbleHttpAgent.php';
    }

    #[DataProvider('urls')]
    public function testNonHttpSchemesAreRejectedWithoutReadingFiles(string $url): void
    {
        $file = new SimplePie_HumbleHttpAgent($url);
        $this->assertFalse($file->success);
        $this->assertNull($file->body);
    }

    public static function urls(): array
    {
        return [
            'file'      => ['file:///etc/passwd'],
            'plain path' => ['/etc/passwd'],
            'php'       => ['php://filter/resource=/etc/passwd'],
            'data'      => ['data://text/plain;base64,AAAA'],
        ];
    }
}
