<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

use Cbox\Cms\Tests\Support\JsToolchainLock;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The marker gate of GUARDRAILS 11: no code or configuration file carries one of the five marker
 * words, matched as whole words and in any case, the fourth also in the plural.
 *
 * The files are the ones git knows in the checkout: tracked files and untracked files that are
 * not ignored, so a new file is checked before it is committed. It reads every one of them that
 * is text, whatever its name or extension, so a configuration file such as an ini, a conf, an
 * env example or a dotfile is read as code is, and leaves out what EXCLUDED lists. A file is
 * binary, and not read, when its first BINARY_PROBE_BYTES bytes hold a NUL byte, the rule git
 * uses to tell text from binary. Symlinks and submodules are not files and are left out.
 *
 * The fourth word is also the name of the HTML attribute for the input hint of a form control.
 * In `.html` and `.tsx` files that attribute is not a marker (InputHintAttributes); the word
 * anywhere else in those files, a key of the same name included, still is.
 *
 * The words are written in parts here and in the tests, so the gate checks its own files too.
 */
final readonly class MarkerScan
{
    /**
     * The five marker words of GUARDRAILS 11, in lower case.
     *
     * @var list<string>
     */
    public const array WORDS = ['to'.'do', 'fix'.'me', 'x'.'xx', 'place'.'holder', 'provi'.'sional'];

    /**
     * The word that also matches in the plural.
     */
    public const string PLURAL = 'place'.'holder';

    /**
     * How many bytes from the start of a file decide whether it is binary, as git decides it.
     */
    public const int BINARY_PROBE_BYTES = 8000;

    /**
     * What the gate never reads, with the reason: `*.<extension>` is every file with that
     * extension, a path ending in a slash is everything below that directory at the root, and
     * any other entry is one file at the root.
     *
     * @var array<string, string>
     */
    public const array EXCLUDED = [
        '*.md' => 'Markdown is prose: the guides, the PRD extracts and PROGRESS.md name the words when they describe the rule.',
        'composer.lock' => 'third-party metadata written by Composer.',
        'package-lock.json' => 'third-party metadata written by npm.',
        '.claude/' => 'agent orchestration, not product code or configuration: its workflow uses the words in prompt text and as a PROGRESS.md status.',
        '.harness/' => 'agent orchestration, not product code or configuration.',
    ];

    /**
     * @param  list<string>  $files  the selected files, relative to the root and sorted
     * @param  list<string>  $hits  one line per line with a marker: `<file>:<line>: <words>`
     */
    private function __construct(
        public string $root,
        public array $files,
        public array $hits,
    ) {}

    /**
     * Scans this checkout, as the marker gate does, while holding the JsToolchainLock shared.
     *
     * The JS toolchain tests write probe files below Node::PROBE_DIRECTORY, untracked and not
     * ignored, and delete them again, under the exclusive lock. A parallel Pest run, the mutation
     * run of gate 5 among them, runs those tests next to the gate, which then listed another
     * process's probe and failed with "Cannot read" when the probe was deleted before it was read.
     * Holding the lock shared, the scan sees no probe: a probe exists only while its writer holds
     * the lock exclusively.
     */
    public static function ofCheckout(string $root): self
    {
        return JsToolchainLock::shared(static fn (): self => self::of($root));
    }

    /**
     * Selects the files of the checkout at the root and reads each one.
     */
    public static function of(string $root): self
    {
        $files = [];

        foreach (self::listed($root) as $path) {
            $file = $root.'/'.$path;

            if (! is_file($file) || is_link($file)) {
                continue;
            }

            if (self::selects($path, self::head($file))) {
                $files[] = $path;
            }
        }

        sort($files, SORT_STRING);
        $hits = [];

        foreach ($files as $path) {
            $contents = file_get_contents($root.'/'.$path);

            if ($contents === false) {
                throw new RuntimeException("Cannot read {$path}.");
            }

            array_push($hits, ...self::hitsIn($path, $contents));
        }

        return new self($root, $files, $hits);
    }

    /**
     * Whether the gate reads a file, given its path relative to the root and its first
     * BINARY_PROBE_BYTES bytes: every file that EXCLUDED does not list and that is text.
     */
    public static function selects(string $path, string $head): bool
    {
        return ! self::excluded($path) && ! str_contains($head, "\0");
    }

    public static function excluded(string $path): bool
    {
        foreach (array_keys(self::EXCLUDED) as $entry) {
            $matches = match (true) {
                str_starts_with($entry, '*.') => strtolower(pathinfo($path, PATHINFO_EXTENSION)) === substr($entry, 2),
                str_ends_with($entry, '/') => str_starts_with($path, $entry),
                default => $path === $entry,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * The lines of a file that carry a marker, as `<file>:<line>: <words>`. The input hint
     * attribute of a form control in a `.html` or `.tsx` file is not a marker; InputHintAttributes
     * says where it is.
     *
     * @return list<string>
     */
    public static function hitsIn(string $path, string $contents): array
    {
        $allowed = array_flip(InputHintAttributes::offsets($path, $contents));
        $hits = [];
        $lineStart = 0;

        foreach (explode("\n", $contents) as $index => $line) {
            preg_match_all(self::pattern(), $line, $matches, PREG_OFFSET_CAPTURE);
            $words = [];

            foreach ($matches[0] as [$word, $offset]) {
                if (! array_key_exists($lineStart + $offset, $allowed)) {
                    $words[] = $word;
                }
            }

            if ($words !== []) {
                $hits[] = sprintf('%s:%d: %s', $path, $index + 1, implode(', ', $words));
            }

            $lineStart += strlen($line) + 1;
        }

        return $hits;
    }

    /**
     * Whole words in any case, as `git grep -w -i` matches them: a word character is a letter,
     * a digit or an underscore.
     */
    public static function pattern(): string
    {
        $words = array_map(
            static fn (string $word): string => $word === self::PLURAL ? $word.'s?' : $word,
            self::WORDS,
        );

        return '/(?<![A-Za-z0-9_])(?:'.implode('|', $words).')(?![A-Za-z0-9_])/i';
    }

    /**
     * The files git lists in the checkout: tracked regular files, executable or not, and
     * untracked files that are not ignored. Symlinks and submodules in the index are left out.
     *
     * @return list<string>
     */
    private static function listed(string $root): array
    {
        $listed = [];

        foreach (self::entries($root, ['ls-files', '-z', '--stage']) as $entry) {
            if (preg_match('/^(\d{6}) [0-9a-f]+ \d\t(.+)$/s', $entry, $match) !== 1) {
                throw new RuntimeException("Cannot read the git ls-files entry '{$entry}'.");
            }

            if (in_array($match[1], ['100644', '100755'], true)) {
                $listed[] = $match[2];
            }
        }

        return array_values(array_unique([
            ...$listed,
            ...self::entries($root, ['ls-files', '-z', '--others', '--exclude-standard']),
        ]));
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private static function entries(string $root, array $arguments): array
    {
        $process = new Process(['git', '-c', 'core.quotePath=false', ...$arguments], $root);
        $process->mustRun();

        return array_values(array_filter(
            explode("\0", $process->getOutput()),
            static fn (string $entry): bool => $entry !== '',
        ));
    }

    private static function head(string $file): string
    {
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$file}.");
        }

        $head = fread($handle, self::BINARY_PROBE_BYTES);
        fclose($handle);

        return $head === false ? '' : $head;
    }
}
