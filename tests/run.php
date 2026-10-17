<?php
/**
 * Minimal test runner (PHPUnit is not installed in the --no-dev image).
 *
 *   php tests/run.php            # unit + integration
 *   php tests/run.php unit       # unit only (no MySQL needed)
 *
 * Integration tests use MySQL schema `aipster_mail_test` (MAIL_DB_* env) and
 * never send email (SMTP is stubbed).
 */

require_once __DIR__ . '/../vendor/autoload.php';

final class T
{
    public static int $pass = 0;
    /** @var string[] */
    public static array $fail = [];
}

function test(string $name, callable $fn): void
{
    try {
        $fn();
        T::$pass++;
        echo "  ok    $name\n";
    } catch (\Throwable $e) {
        T::$fail[] = "$name: " . $e->getMessage();
        echo "  FAIL  $name\n        " . get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}

function assertTrue($cond, string $msg = 'expected true'): void
{
    if (!$cond) {
        throw new \RuntimeException($msg);
    }
}

function assertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(($msg !== '' ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertContains(string $needle, string $haystack, string $msg = ''): void
{
    if (strpos($haystack, $needle) === false) {
        throw new \RuntimeException(($msg !== '' ? "$msg: " : '') . 'missing ' . var_export($needle, true) . ' in ' . var_export(substr($haystack, 0, 400), true));
    }
}

function assertNotContains(string $needle, string $haystack, string $msg = ''): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new \RuntimeException(($msg !== '' ? "$msg: " : '') . 'unexpected ' . var_export($needle, true));
    }
}

$suites = isset($argv[1]) ? [$argv[1]] : ['unit', 'integration'];
foreach ($suites as $suite) {
    foreach (glob(__DIR__ . "/$suite/*Test.php") as $file) {
        echo "\n" . $suite . '/' . basename($file) . "\n";
        require $file;
    }
}

echo "\n" . T::$pass . ' passed, ' . count(T::$fail) . " failed\n";
foreach (T::$fail as $f) {
    echo "  - $f\n";
}
exit(T::$fail ? 1 : 0);
