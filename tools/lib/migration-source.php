<?php

declare(strict_types=1);

/**
 * Shared, dependency-free source parsing for the guard scripts.
 *
 * Deliberately not a Composer-autoloaded class: `tools/schema-guard.php` and
 * `tools/migration-guard.php` must run from a bare `php tools/<name>.php` with
 * no framework boot, so the pipeline and a developer's shell invoke them
 * identically (see the spec's CI decision).
 */

namespace PayStar\Tools;

/**
 * A migration file located on disk, with its owning module resolved from its path.
 *
 * @phpstan-type MigrationFile array{
 *     path: string,
 *     relative: string,
 *     name: string,
 *     module: string,
 *     order: string,
 *     source: string
 * }
 */
final class MigrationSource
{
    /**
     * Pseudo-module for migrations that live in Laravel's own `database/migrations`.
     * Framework infrastructure tables (cache, jobs) belong here; no business module
     * owns them, and a foreign key from a module to one of them is cross-boundary.
     */
    public const FRAMEWORK_MODULE = '@framework';

    /**
     * Every migration in the tree, module migrations first.
     *
     * @return list<array{path: string, relative: string, name: string, module: string, order: string, source: string}>
     */
    public static function collect(string $root): array
    {
        $root = rtrim($root, '/');
        $files = [];

        foreach (self::glob($root.'/app/Modules/*/Database/migrations') as $dir) {
            $module = basename(dirname($dir, 2));
            foreach (self::phpFilesIn($dir) as $path) {
                $files[] = self::describe($root, $path, $module);
            }
        }

        foreach (self::phpFilesIn($root.'/database/migrations') as $path) {
            $files[] = self::describe($root, $path, self::FRAMEWORK_MODULE);
        }

        usort($files, static fn (array $a, array $b): int => [$a['order'], $a['relative']] <=> [$b['order'], $b['relative']]);

        return $files;
    }

    /**
     * @return array{path: string, relative: string, name: string, module: string, order: string, source: string}
     */
    private static function describe(string $root, string $path, string $module): array
    {
        $name = basename($path, '.php');

        return [
            'path' => $path,
            'relative' => self::relative($root, $path),
            'name' => $name,
            'module' => $module,
            'order' => preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', $name, $m) === 1 ? $m[1] : $name,
            'source' => (string) file_get_contents($path),
        ];
    }

    public static function relative(string $root, string $path): string
    {
        $root = rtrim($root, '/').'/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    /**
     * @return list<string>
     */
    private static function glob(string $pattern): array
    {
        $found = glob($pattern, GLOB_ONLYDIR);

        return $found === false ? [] : array_values($found);
    }

    /**
     * Recursive so a module may group its migrations into subdirectories.
     *
     * @return list<string>
     */
    private static function phpFilesIn(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * Body of `up()` / `down()`, brace-matched, plus its byte offset in the file.
     *
     * Migration classes are anonymous (`return new class extends Migration`), so
     * reflection is not available without executing the file — which a guard must
     * never do. Tokenising is the honest alternative to a regex over the whole file.
     *
     * @return array{body: string, line: int}|null null when the method is absent
     */
    public static function method(string $source, string $method): ?array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            $nameIndex = self::nextMeaningful($tokens, $i + 1);
            if ($nameIndex === null || ! is_array($tokens[$nameIndex]) || $tokens[$nameIndex][0] !== T_STRING) {
                continue;
            }
            if (strtolower($tokens[$nameIndex][1]) !== strtolower($method)) {
                continue;
            }

            $open = self::indexOfChar($tokens, $nameIndex + 1, '{');
            if ($open === null) {
                return null;
            }

            return self::bodyFrom($tokens, $open);
        }

        return null;
    }

    /**
     * Every comment and docblock in the file, concatenated.
     */
    public static function comments(string $source): string
    {
        $comments = [];

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                $comments[] = $token[1];
            }
        }

        return implode("\n", $comments);
    }

    /**
     * Source with every comment blanked to spaces (newlines preserved), so that an
     * operation merely *named* in a docblock cannot be mistaken for a real call.
     * String literals are deliberately kept: raw-SQL migrations hide their real
     * operations inside them.
     */
    public static function withoutComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= preg_replace('/[^\n]/', ' ', $token[1]);

                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /**
     * Line number of a byte offset inside $text, given the line $text starts on.
     */
    public static function lineAt(string $text, int $offset, int $startLine = 1): int
    {
        return $startLine + substr_count(substr($text, 0, max(0, $offset)), "\n");
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{body: string, line: int}
     */
    private static function bodyFrom(array $tokens, int $open): array
    {
        $depth = 0;
        $body = '';
        $line = 1;
        $seenOpen = false;
        $count = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];

            if ($text === '{') {
                $depth++;
                if ($depth === 1) {
                    $seenOpen = true;

                    continue;
                }
            }

            if ($text === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }

            if ($seenOpen && $body === '' && is_array($tokens[$i])) {
                $line = $tokens[$i][2];
            }

            $body .= $text;
        }

        return ['body' => $body, 'line' => $line];
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function nextMeaningful(array $tokens, int $from): ?int
    {
        $count = count($tokens);

        for ($i = $from; $i < $count; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function indexOfChar(array $tokens, int $from, string $char): ?int
    {
        $count = count($tokens);

        for ($i = $from; $i < $count; $i++) {
            if (! is_array($tokens[$i]) && $char === $tokens[$i]) {
                return $i;
            }
        }

        return null;
    }
}

/**
 * Minimal reporter shared by both guards: identical output shape, identical exit
 * codes, whether run by CI or by hand.
 */
final class GuardReport
{
    /** @var list<array{file: string, line: int|null, message: string}> */
    private array $failures = [];

    /** @var list<string> */
    private array $notes = [];

    public function __construct(private readonly string $name, private readonly string $root) {}

    public function fail(string $file, ?int $line, string $message): void
    {
        $this->failures[] = ['file' => $file, 'line' => $line, 'message' => $message];
    }

    public function note(string $message): void
    {
        $this->notes[] = $message;
    }

    public function emit(int $scanned): int
    {
        $out = fn (string $line) => fwrite($this->failures === [] ? STDOUT : STDERR, $line."\n");

        if ($this->failures === []) {
            foreach ($this->notes as $note) {
                $out('  '.$note);
            }
            $out(sprintf('%s: OK — %d migration(s) scanned under %s', $this->name, $scanned, $this->root));

            return 0;
        }

        $out(sprintf('%s: FAILED — %d violation(s) in %d migration(s) scanned under %s', $this->name, count($this->failures), $scanned, $this->root));
        $out('');

        foreach ($this->failures as $failure) {
            $location = $failure['file'].($failure['line'] !== null ? ':'.$failure['line'] : '');
            $out('  '.$location);
            foreach (explode("\n", $failure['message']) as $line) {
                $out('      '.$line);
            }
            $out('');
        }

        return 1;
    }
}

/**
 * Parses the `--root=` option both guards accept, so a test fixture tree can be
 * scanned with exactly the production code path.
 */
function guard_root(array $argv): string
{
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--root=')) {
            $root = substr($argument, 7);
            $resolved = realpath($root);

            if ($resolved === false) {
                fwrite(STDERR, "Root directory not found: {$root}\n");
                exit(2);
            }

            return $resolved;
        }

        fwrite(STDERR, "Unknown option: {$argument}\n");
        fwrite(STDERR, "Usage: php tools/<guard>.php [--root=<directory>]\n");
        exit(2);
    }

    return dirname(__DIR__, 2);
}
