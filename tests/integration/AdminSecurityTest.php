<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Security tests for admin CSRF protections, session logout handling,
 * display_errors suppression, and cache-key separation.
 */
final class AdminSecurityTest extends TestCase
{
    private ?string $staged = null;
    private ?string $sessDir = null;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/ftradmsec-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(FTR_APP_DIR, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $e) {
            $t = $dir . substr($e->getPathname(), strlen(FTR_APP_DIR));
            $e->isDir() ? mkdir($t, 0777, true) : copy($e->getPathname(), $t);
        }
        copy(dirname(FTR_APP_DIR) . '/docker/custom_config.php', $dir . '/custom_config.php');
        @mkdir($dir . '/site_config/standard', 0777, true);
        file_put_contents($dir . '/site_config/standard/version.txt', "test-rev\n");
        @mkdir($dir . '/site_config/custom', 0777, true);
        @mkdir($dir . '/cache/rss', 0777, true);
        $this->staged = $dir;

        $this->sessDir = sys_get_temp_dir() . '/ftrsess-' . bin2hex(random_bytes(4));
        mkdir($this->sessDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (array($this->staged, $this->sessDir) as $dir) {
            if ($dir === null || !is_dir($dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $e) {
                $e->isDir() ? @rmdir($e->getPathname()) : @unlink($e->getPathname());
            }
            @rmdir($dir);
        }
    }

    /**
     * Run an admin script in a child PHP process with a controlled session ID.
     *
     * @return array{body: string, session: array<string, mixed>, display_errors: string}
     */
    private function runAdmin(
        string $adminScript,
        string $method,
        array $get = array(),
        array $post = array(),
        string $sessionId = 'testsess1234567890abcdef',
        array $seedSession = array(),
        array $server = array()
    ): array {
        $wrapper = tempnam(sys_get_temp_dir(), 'ftradmw');
        $metaOut = tempnam(sys_get_temp_dir(), 'ftradmm');
        $adminDir = $this->staged . '/admin';

        file_put_contents($wrapper, '<?php ' . "\n"
            . 'putenv("FTR_ADMIN_PASSWORD=correct-horse-battery-staple");' . "\n"
            . 'ini_set("session.save_path", ' . var_export($this->sessDir, true) . ');' . "\n"
            . 'ini_set("session.use_cookies", "1");' . "\n"
            . '$_COOKIE[session_name()] = ' . var_export($sessionId, true) . ';' . "\n"
            . '$seed = ' . var_export($seedSession, true) . ';' . "\n"
            . 'if (!empty($seed)) { session_id(' . var_export($sessionId, true) . '); session_start(); foreach ($seed as $k => $v) { $_SESSION[$k] = $v; } session_write_close(); }' . "\n"
            . '$_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';' . "\n"
            . '$_SERVER["HTTP_HOST"] = "localhost";' . "\n"
            . '$_SERVER["SERVER_NAME"] = "localhost";' . "\n"
            . '$_SERVER["SCRIPT_NAME"] = "/admin/" . ' . var_export($adminScript, true) . ';' . "\n"
            . '$_SERVER["REQUEST_URI"] = "/admin/" . ' . var_export($adminScript, true) . ';' . "\n"
            . 'foreach (' . var_export($server, true) . ' as $_k => $_v) { $_SERVER[$_k] = $_v; }' . "\n"
            . '$_GET = ' . var_export($get, true) . ';' . "\n"
            . '$_POST = ' . var_export($post, true) . ';' . "\n"
            . '$_REQUEST = array_merge($_GET, $_POST);' . "\n"
            . 'register_shutdown_function(function () {' . "\n"
            . '    $disp = (string)ini_get("display_errors");' . "\n"
            . '    $csec = (string)ini_get("session.cookie_secure");' . "\n"
            . '    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }' . "\n"
            . '    file_put_contents(' . var_export($metaOut, true) . ', json_encode(array("session" => $_SESSION ?? array(), "session_id" => session_id(), "display_errors" => $disp, "cookie_secure" => $csec)));' . "\n"
            . '});' . "\n"
            . 'chdir(' . var_export($adminDir, true) . ');' . "\n"
            . 'include ' . var_export($adminDir . '/' . $adminScript, true) . ';' . "\n"
        );

        $devnull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d error_reporting=E_ALL^E_DEPRECATED '
            . escapeshellarg($wrapper) . ' 2>' . $devnull;
        $body = (string)shell_exec($cmd);
        $meta = json_decode((string)@file_get_contents($metaOut), true);
        @unlink($wrapper);
        @unlink($metaOut);

        return array(
            'body' => $body,
            'session' => is_array($meta['session'] ?? null) ? $meta['session'] : array(),
            'session_id' => (string)($meta['session_id'] ?? ''),
            'display_errors' => (string)($meta['display_errors'] ?? ''),
            'cookie_secure' => (string)($meta['cookie_secure'] ?? ''),
        );
    }

    public function testSessionCookieSecureFlagIsSetOnHttpsOnlyWhenTrusted(): void
    {
        $httpRes = $this->runAdmin('login.php', 'GET', array(), array(), 'sec1', array(), array('HTTP_X_FORWARDED_PROTO' => 'https'));
        $this->assertContains($httpRes['cookie_secure'], array('', '0', 'Off', 'off'), 'untrusted X-Forwarded-Proto must not enable cookie_secure');

        $httpsRes = $this->runAdmin('login.php', 'GET', array(), array(), 'sec2', array(), array('HTTPS' => 'on'));
        $this->assertSame('1', $httpsRes['cookie_secure'], 'HTTPS=on must set session.cookie_secure=1');
    }

    public function testEditPatternSaveAndDeleteRequireValidCsrfToken(): void
    {
        $sessId = 'csrfedit1234567890abcdef';
        // Load edit-pattern editor view for example.org so both navbar logout and save form render csrf_token.
        $getRes = $this->runAdmin('edit-pattern.php', 'GET', array('url' => 'example.org'), array(), $sessId, array('auth' => 1));
        $sessId = $getRes['session_id'];
        $token = (string)($getRes['session']['csrf_token'] ?? '');
        $this->assertNotSame('', $token, 'authenticated admin session must generate a non-empty csrf_token');
        $this->assertGreaterThanOrEqual(2, substr_count($getRes['body'], $token), 'edit-pattern editor and logout forms must embed csrf_token');

        $customFile = $this->staged . '/site_config/custom/example.org.txt';

        // Forged POST save without CSRF token must fail and must NOT create the file.
        $this->runAdmin(
            'edit-pattern.php',
            'POST',
            array(),
            array('save' => 'example.org', 'save_dir' => 'custom', 'contents' => "body: //article\n"),
            $sessId
        );
        $this->assertFileDoesNotExist($customFile, 'POST save without CSRF token must not write site_config');

        // Legitimate POST save with valid CSRF token must succeed.
        $this->runAdmin(
            'edit-pattern.php',
            'POST',
            array(),
            array('save' => 'example.org', 'save_dir' => 'custom', 'contents' => "body: //article\n", 'csrf_token' => $token),
            $sessId
        );
        $this->assertFileExists($customFile, 'POST save with valid CSRF token must write custom site_config');

        // Forged POST delete without CSRF token must NOT delete the file.
        $this->runAdmin(
            'edit-pattern.php',
            'POST',
            array(),
            array('delete' => 'example.org.txt', 'delete_dir' => 'custom'),
            $sessId
        );
        $this->assertFileExists($customFile, 'POST delete without CSRF token must not delete custom site_config');

        // Legitimate POST delete with valid CSRF token must delete the file.
        $this->runAdmin(
            'edit-pattern.php',
            'POST',
            array(),
            array('delete' => 'example.org.txt', 'delete_dir' => 'custom', 'csrf_token' => $token),
            $sessId
        );
        $this->assertFileDoesNotExist($customFile, 'POST delete with valid CSRF token must delete custom site_config');
    }

    public function testLoginRequiresCsrfTokenAndLogoutRequiresPostWithCsrfToken(): void
    {
        $formRes = $this->runAdmin('login.php', 'GET', array(), array(), 'uninit1234567890abcdef');
        $issuedSessId = $formRes['session_id'];
        $token = (string)($formRes['session']['csrf_token'] ?? '');
        $this->assertNotSame('', $issuedSessId);
        $this->assertNotSame('', $token, 'login GET must initialize a session csrf_token');
        $this->assertStringContainsString('name="csrf_token"', $formRes['body']);

        // Login POST without CSRF token must not authenticate even with right credentials.
        $noTokenLogin = $this->runAdmin(
            'login.php',
            'POST',
            array(),
            array('username' => 'admin', 'pass' => 'correct-horse-battery-staple'),
            $issuedSessId
        );
        $this->assertNotSame(1, $noTokenLogin['session']['auth'] ?? 0, 'login POST without CSRF token must fail');

        // Login POST with valid CSRF token and right credentials must succeed and regenerate session ID.
        $goodLogin = $this->runAdmin(
            'login.php',
            'POST',
            array(),
            array('username' => 'admin', 'pass' => 'correct-horse-battery-staple', 'csrf_token' => $token),
            $issuedSessId
        );
        $this->assertSame(1, $goodLogin['session']['auth'] ?? 0, 'login POST with valid CSRF token must authenticate');
        $this->assertNotSame($issuedSessId, $goodLogin['session_id'], 'login must regenerate session ID');

        // Seed an authenticated session with token and verify GET ?logout does NOT log out.
        $authedId = 'csrflogout1234567890abcdef';
        $indexGet = $this->runAdmin('index.php', 'GET', array(), array(), $authedId, array('auth' => 1));
        $authedId = $indexGet['session_id'];
        $logoutToken = (string)($indexGet['session']['csrf_token'] ?? '');
        $this->assertNotSame('', $logoutToken);

        $getLogout = $this->runAdmin('index.php', 'GET', array('logout' => '1'), array(), $authedId);
        $this->assertSame(1, $getLogout['session']['auth'] ?? 0, 'GET ?logout must not log out the session (CSRF)');

        // POST logout without CSRF token must not log out.
        $forgedPostLogout = $this->runAdmin('index.php', 'POST', array(), array('logout' => '1'), $authedId);
        $this->assertSame(1, $forgedPostLogout['session']['auth'] ?? 0, 'POST logout without CSRF token must not log out');

        // POST logout with valid CSRF token must destroy the session.
        $validLogout = $this->runAdmin(
            'index.php',
            'POST',
            array(),
            array('logout' => '1', 'csrf_token' => $logoutToken),
            $authedId
        );
        $this->assertNotSame(1, $validLogout['session']['auth'] ?? 0, 'POST logout with valid CSRF token must clear auth');
    }

    public function testAdminAndCleanCacheDoNotEnableDisplayErrorsOrReflectUntrustedHost(): void
    {
        $sessId = 'disp1234567890abcdef';
        foreach (array('index.php', 'edit-pattern.php', 'update.php') as $script) {
            $res = $this->runAdmin(
                $script,
                'GET',
                array(),
                array(),
                $sessId,
                array('auth' => 1),
                array('HTTP_HOST' => 'attacker-host.invalid"><script>alert(1)</script>')
            );
            $this->assertContains($res['display_errors'], array('', '0', 'Off', 'off'), "$script must not force display_errors=1");
            $this->assertStringNotContainsString('attacker-host.invalid', $res['body'], "$script must not reflect untrusted HTTP_HOST");
        }

        // Verify cleancache.php source does not set display_errors=1 or @set_time_limit.
        $cleanSrc = (string)file_get_contents(FTR_APP_DIR . '/cleancache.php');
        $this->assertDoesNotMatchRegularExpression('/ini_set\s*\(\s*[\'"]display_errors[\'"]\s*,\s*1/', $cleanSrc);
        $this->assertDoesNotMatchRegularExpression('/set_time_limit\s*\(/', $cleanSrc);
    }

    public function testCacheKeyDoesNotCollideAcrossAmbiguousConcatenationsOrCallbacks(): void
    {
        if (!@fsockopen(FTR_FIXTURE_HOST, FTR_FIXTURE_PORT, $errno, $errstr, 1)) {
            $this->markTestSkipped('Fixture server not running.');
        }

        $feedUrl = 'http://' . FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT . '/feed.xml';
        $runCached = function (array $params) use ($feedUrl): string {
            $script = tempnam(sys_get_temp_dir(), 'ftrck');
            $allowed = var_export(array(FTR_FIXTURE_HOST . ':' . FTR_FIXTURE_PORT), true);
            $app = var_export($this->staged, true);
            $query = var_export(array_merge(array('url' => $feedUrl), $params), true);
            file_put_contents($script, "<?php\n"
                . "putenv('ftr_caching=true');\n"
                . "putenv('ftr_allow_jsonp=true');\n"
                . "require $app . '/libraries/SafeHttpUrl.php';\n"
                . "SafeHttpUrl::\$testAllowedHosts = $allowed;\n"
                . "\$_GET = \$_REQUEST = $query;\n"
                . "\$_SERVER['HTTP_HOST'] = 'localhost';\n"
                . "\$_SERVER['SERVER_NAME'] = 'localhost';\n"
                . "\$_SERVER['SCRIPT_NAME'] = '/makefulltextfeed.php';\n"
                . "\$_SERVER['REQUEST_URI'] = '/makefulltextfeed.php';\n"
                . "chdir($app);\n"
                . "include $app . '/makefulltextfeed.php';\n"
            );
            $devnull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
            $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg($script) . ' 2>' . $devnull);
            @unlink($script);
            return $out;
        };

        $out1 = $runCached(array('format' => 'json', 'callback' => 'cbOne'));
        $out2 = $runCached(array('format' => 'json', 'callback' => 'cbTwo'));
        $this->assertStringStartsWith('cbOne(', $out1);
        $this->assertStringStartsWith('cbTwo(', $out2);

        // Also check makefulltextfeed.php uses structured/delimited hashing rather than raw string concatenation.
        $src = (string)file_get_contents(FTR_APP_DIR . '/makefulltextfeed.php');
        $this->assertDoesNotMatchRegularExpression('/md5\s*\(\s*\$max\s*\.\s*\$url/', $src, 'cache key must not concatenate $max.$url without delimiters');
    }
}
