<?php

// (c) Sergei Shilko <contact@sshilko.com>
//
// MIT License
//
// For the full copyright and license information, please view the LICENSE
// file that was distributed with this source code.
// @license https://opensource.org/licenses/mit-license.php MIT

/**
 * Verifies that every class file under src/ and tests/ can be loaded.
 *
 * `php -l` does not catch load-time fatals. A file that only fails when its
 * class is declared, for example a parameter type that narrows the signature of
 * an inherited method, passes `php -l` but kills the process the moment anything
 * autoloads it. Inside the PHPUnit suite that shows up as a silent exit code 255
 * with no summary, because the fatal happens while the class is linked, outside
 * of any test. This script links every class in a child process and reports the
 * files that cannot be loaded.
 *
 * Usage: php build/check-classes.php
 */

declare(strict_types=1);

namespace BackQ\Build;

use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

const EXIT_OK = 0;
const EXIT_BROKEN = 1;
const EXIT_USAGE = 2;

/**
 * A class declaration is required: procedural helper scripts, such as
 * tests/Support/FakeNsqdServer.php, have no class to load.
 */
const DECLARATION_PATTERN = '/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s/mi';

/**
 * Marker the child writes to stdout before it links each class, so the parent
 * can tell which class was the last one attempted when the child dies.
 */
const MARKER = '#LINKING# ';

$root = dirname(__DIR__);

if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, 'Dependencies are not installed, run "composer install" first.' . PHP_EOL);
    exit(EXIT_USAGE);
}

try {
    $files = phpFiles($root);
    $classes = collectClassNames($root, $files);
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot build the class list: ' . $e->getMessage() . PHP_EOL);
    exit(EXIT_USAGE);
}

$failures = [];
$checked = 0;
$pending = $classes;

while ([] !== $pending) {
    [$broken, $error] = linkClasses($root, $pending);

    if (null === $broken) {
        $checked += count($pending);

        break;
    }

    $failures[$broken] = ['file' => $pending[$broken], 'error' => $error];
    unset($pending[$broken]);
}

$skipped = filesWithoutDeclaration($root, $files);

echo 'Checked ' . $checked . ' class file(s) under src/ and tests/.' . PHP_EOL;

if ([] !== $skipped) {
    echo 'Skipped ' . count($skipped) . ' file(s) without a class declaration:' . PHP_EOL;
    foreach ($skipped as $file) {
        echo '  - ' . $file . PHP_EOL;
    }
}

if ([] === $failures) {
    echo 'OK, every class file loads.' . PHP_EOL;
    exit(EXIT_OK);
}

echo PHP_EOL . count($failures) . ' class file(s) cannot be loaded:' . PHP_EOL;

foreach ($failures as $class => $failure) {
    echo PHP_EOL . '  ' . $failure['file'] . PHP_EOL;
    echo '    class: ' . $class . PHP_EOL;
    echo '    ' . $failure['error'] . PHP_EOL;
}

echo PHP_EOL . 'A class file that does not load takes down every test suite that'
    . ' autoloads it, without a PHPUnit summary. Fix the declaration(s) above.' . PHP_EOL;

exit(EXIT_BROKEN);

/**
 * Maps every class-like file to its fully qualified class name, using the PSR-4
 * prefixes declared in composer.json.
 *
 * @param list<string> $files paths relative to the repository root
 *
 * @return array<string, string> class name => file path, relative to the repository root
 */
function collectClassNames(string $root, array $files): array
{
    $prefixes = psr4Prefixes($root);
    $classes = [];

    foreach ($files as $file) {
        if (!hasDeclaration($root . '/' . $file)) {
            continue;
        }

        $class = classNameFor($file, $prefixes);

        if (null !== $class) {
            $classes[$class] = $file;
        }
    }

    ksort($classes);

    return $classes;
}

/**
 * @return array<string, string> namespace prefix => absolute directory
 */
function psr4Prefixes(string $root): array
{
    $composer = $root . '/composer.json';
    if (!is_file($composer)) {
        throw new RuntimeException('composer.json not found in ' . $root);
    }

    $json = file_get_contents($composer);
    if (false === $json) {
        throw new RuntimeException('Cannot read ' . $composer);
    }

    try {
        $config = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('composer.json is not valid JSON: ' . $e->getMessage());
    }

    $prefixes = [];
    foreach (['autoload', 'autoload-dev'] as $section) {
        foreach ($config[$section]['psr-4'] ?? [] as $prefix => $directory) {
            if (is_string($prefix) && is_string($directory)) {
                $prefixes[rtrim($prefix, '\\')] = rtrim($directory, '/');
            }
        }
    }

    if ([] === $prefixes) {
        throw new RuntimeException('No PSR-4 prefixes found in composer.json');
    }

    return $prefixes;
}

/**
 * @return list<string> paths relative to the repository root
 */
function phpFiles(string $root): array
{
    $files = [];

    foreach ([$root . '/src', $root . '/tests'] as $directory) {
        if (!is_dir($directory)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            \assert($file instanceof SplFileInfo);
            if ($file->isFile() && 'php' === strtolower($file->getExtension())) {
                $files[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * @param list<string> $files paths relative to the repository root
 *
 * @return list<string>
 */
function filesWithoutDeclaration(string $root, array $files): array
{
    $skipped = [];

    foreach ($files as $file) {
        if (!hasDeclaration($root . '/' . $file)) {
            $skipped[] = $file;
        }
    }

    return $skipped;
}

function hasDeclaration(string $file): bool
{
    $contents = file_get_contents($file);

    return false !== $contents && 1 === preg_match(DECLARATION_PATTERN, $contents);
}

/**
 * @param string $file path relative to the repository root
 * @param array<string, string> $prefixes
 */
function classNameFor(string $file, array $prefixes): ?string
{
    foreach ($prefixes as $prefix => $directory) {
        $directory = rtrim(str_replace('\\', '/', $directory), '/');

        if (!str_starts_with($file, $directory . '/')) {
            continue;
        }

        $relative = substr($file, strlen($directory) + 1);

        return $prefix . '\\' . str_replace('/', '\\', substr($relative, 0, -4));
    }

    return null;
}

/**
 * Links a batch of classes in one child process. A compile-time fatal cannot be
 * caught in-process, so the isolation is the point; batching keeps a healthy
 * run down to a single child process. When a class breaks, the child stops, and
 * the caller retries without the class the child announced last.
 *
 * @param array<string, string> $classes class name => file path, relative to the repository root
 *
 * @return array{0: ?string, 1: string} broken class (null when all linked) and the reason
 */
function linkClasses(string $root, array $classes): array
{
    $command = sprintf(
        '%s -d display_errors=stderr -d error_reporting=-1 -d log_errors=0 -r %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(linkerSnippet($root . '/vendor/autoload.php'))
    );

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $process = proc_open($command, $descriptors, $pipes, $root);

    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start the linker child process');
    }

    $request = [];
    foreach ($classes as $class => $file) {
        $request[] = json_encode(['class' => $class, 'file' => $file], JSON_THROW_ON_ERROR);
    }

    fwrite($pipes[0], implode(PHP_EOL, $request) . PHP_EOL);
    fclose($pipes[0]);

    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($process);

    if (0 === $code) {
        return [null, ''];
    }

    $last = (string) array_key_first($classes);
    foreach (array_reverse(explode(PHP_EOL, $stdout)) as $line) {
        $line = trim($line);

        if (str_starts_with($line, MARKER)) {
            $last = trim(substr($line, strlen(MARKER)));

            break;
        }
    }

    $error = trim($stderr);
    if ('' === $error) {
        $error = 'the child process died with exit code ' . $code . ' and no message';
    }

    return [$last, $error];
}

/**
 * The code the child runs: it links one class at a time, announces each one on
 * stdout, and exits non-zero on the first failure. Errors go to stderr so they
 * never mix with the markers.
 */
function linkerSnippet(string $autoload): string
{
    $autoloadCode = var_export($autoload, true);
    $markerCode = var_export(MARKER, true);

    return <<<PHP
        require {$autoloadCode};

        function backqExists(string \$class, bool \$autoload): bool
        {
            return class_exists(\$class, \$autoload)
                || interface_exists(\$class, \$autoload)
                || trait_exists(\$class, \$autoload)
                || enum_exists(\$class, \$autoload);
        }

        function backqLink(string \$class, string \$file): int
        {
            try {
                if (backqExists(\$class, true)) {
                    return 0;
                }

                /**
                 * The optimized classmap can be stale, so link the file itself
                 * instead of trusting the autoloader to find the class.
                 */
                require_once \$file;

                if (backqExists(\$class, false)) {
                    return 0;
                }
            } catch (Throwable \$e) {
                fwrite(STDERR, get_class(\$e) . ': ' . \$e->getMessage() . PHP_EOL);
                return 4;
            }

            fwrite(STDERR, 'the file does not declare the class, run "composer dump-autoload" if the classmap is stale' . PHP_EOL);
            return 3;
        }

        \$requests = file('php://stdin', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (false === \$requests) {
            fwrite(STDERR, 'cannot read the class list from stdin' . PHP_EOL);
            exit(5);
        }

        foreach (\$requests as \$request) {
            \$payload = json_decode(\$request, true);
            if (!is_array(\$payload) || !isset(\$payload['class'], \$payload['file'])) {
                fwrite(STDERR, 'malformed request: ' . \$request . PHP_EOL);
                exit(5);
            }

            echo {$markerCode} . \$payload['class'] . PHP_EOL;

            \$status = backqLink((string) \$payload['class'], (string) \$payload['file']);
            if (0 !== \$status) {
                exit(\$status);
            }
        }
        PHP;
}
