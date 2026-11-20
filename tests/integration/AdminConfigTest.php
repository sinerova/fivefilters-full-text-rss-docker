<?php

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Admin credential handling, checked by loading the repo's custom_config.php in a child
 * process with a controlled environment. Nothing here touches the network.
 */
#[Group('integration')]
final class AdminConfigTest extends TestCase
{
    /** Copies app/ plus the root custom_config.php into a temp dir, as the image does. */
    private static function stagedAppDir(): string
    {
        $dir = sys_get_temp_dir() . '/ftrcfg-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(FTR_APP_DIR, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($entries as $file) {
            $target = $dir . substr($file->getPathname(), strlen(FTR_APP_DIR));
            if ($file->isDir()) {
                mkdir($target, 0777, true);
            } else {
                copy($file->getPathname(), $target);
            }
        }
        copy(dirname(FTR_APP_DIR) . '/custom_config.php', $dir . '/custom_config.php');
        return $dir;
    }

    private static function loadOptions(?string $password): array
    {
        $dir = self::stagedAppDir();
        $script = tempnam(sys_get_temp_dir(), 'ftrcfg');
        file_put_contents($script, '<?php '
            . 'require ' . var_export($dir . '/config.php', true) . '; '
            . 'echo json_encode(array("admin" => $options->admin_credentials, "debug" => $options->debug, '
            . '"user_submitted_config" => $options->user_submitted_config));');
        $env = array('PATH' => getenv('PATH'), 'SystemRoot' => getenv('SystemRoot'));
        if ($password !== null) {
            $env['FTR_ADMIN_PASSWORD'] = $password;
        }
        $proc = proc_open(
            escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg($script),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            null,
            $env
        );
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        unlink($script);
        self::removeDir($dir);
        return json_decode($out, true) ?: array();
    }

    private static function removeDir(string $dir): void
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    public function testAdminIsDisabledWhenPasswordIsUnset(): void
    {
        $opts = self::loadOptions(null);
        $this->assertSame('', $opts['admin']['password'] ?? null, 'empty password disables admin');
    }

    public function testAdminPasswordIsReadFromEnvironment(): void
    {
        $opts = self::loadOptions('s3cret-test-value');
        $this->assertSame('s3cret-test-value', $opts['admin']['password']);
    }

    public function testDebugIsRestrictedToAdminByDefault(): void
    {
        $opts = self::loadOptions(null);
        $this->assertSame('admin', $opts['debug']);
    }

    public function testUserSubmittedConfigIsDisabled(): void
    {
        $opts = self::loadOptions(null);
        $this->assertFalse($opts['user_submitted_config']);
    }
}
