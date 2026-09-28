<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * Reads the frontmatter, markers, fenced code blocks, links and heading anchors of a Markdown page.
 *
 * Frontmatter is the block between a first line `---` and the next line `---`, one `key: value`
 * per line; a value may be quoted in single or double quotes. Line numbers count from the first
 * line of the file, frontmatter included.
 *
 * A marker is an HTML comment on a line of its own, `<!-- <kind>: <target> -->` (MarkerKind). A
 * fenced block opens with three or more backticks or tildes, indented by at most three spaces, and
 * closes with a line of at least as many of the same character and nothing else (CommonMark). A
 * marker inside a fenced block is content, not a marker. An example or example-file marker embeds
 * the fenced block that opens on the very next line.
 *
 * A link is an inline link or image, `[text](target)` or `![text](target)`, on one line, or a
 * reference definition, `[label]: target`, outside fenced blocks and inline code. A heading is an
 * ATX heading outside fenced blocks, and its anchor is the one GitHub gives it: the text without
 * inline code marks and link targets, in lowercase, without punctuation but hyphens and
 * underscores, with every space a hyphen, and `-1`, `-2` and so on after a repeat.
 */
final readonly class PageParser
{
    private const string MARKER = '/^<!--\s*(?<kind>extension-point|example-file|example):\s*(?<target>.*?)\s*-->\s*$/';

    private const string OPENING_FENCE = '/^ {0,3}(?<fence>`{3,}|~{3,})(?<info>.*)$/';

    private const string FRONTMATTER_FENCE = '---';

    private const string FRONTMATTER_LINE = '/^(?<key>[A-Za-z_][A-Za-z0-9_-]*):(?:[ \t]+(?<value>.*?))?[ \t]*$/';

    private const string HEADING = '/^ {0,3}#{1,6}(?:[ \t]+(?<text>.*?))?(?:[ \t]+#+)?[ \t]*$/';

    private const string INLINE_LINK = '/(?<image>!?)\[(?<text>(?:[^\[\]]|\[[^\[\]]*\])*)\]\(\s*(?<target><[^>]*>|[^\s)]+)(?:\s+(?:"[^"]*"|\'[^\']*\'|\([^)]*\)))?\s*\)/';

    private const string REFERENCE_DEFINITION = '/^ {0,3}\[(?<text>[^\]]+)\]:[ \t]*(?<target><[^>]*>|\S+)/';

    /**
     * @param  string  $path  repo-relative
     */
    public static function parse(string $path, string $markdown): Page
    {
        $lines = explode("\n", $markdown);

        if (end($lines) === '') {
            array_pop($lines);
        }

        $frontmatter = self::frontmatter($lines);
        $markers = [];
        $embeds = [];
        $strayFences = [];
        $unclosedFences = [];
        $links = [];
        $headings = [];
        $previous = null;
        $count = count($lines);

        for ($index = $frontmatter instanceof Frontmatter ? $frontmatter->lastLine : 0; $index < $count; $index++) {
            $line = $lines[$index];
            $fence = self::openingFence($line);

            if ($fence !== null) {
                $close = self::closingLine($lines, $index + 1, $fence);
                $end = $close ?? $count;
                $body = implode('', array_map(static fn (string $content): string => $content."\n", array_slice($lines, $index + 1, $end - $index - 1)));

                if ($close === null) {
                    $unclosedFences[] = $index + 1;
                }

                if ($previous instanceof Marker && $previous->kind->embeds() && $previous->line === $index) {
                    $embeds[] = new Embed($previous, $body);
                } else {
                    $strayFences[] = $index + 1;
                }

                $previous = null;
                $index = $end;

                continue;
            }

            $previous = null;

            if (preg_match(self::MARKER, $line, $match) === 1) {
                $previous = new Marker(MarkerKind::from($match['kind']), $match['target'], $index + 1);
                $markers[] = $previous;

                continue;
            }

            if (preg_match(self::HEADING, $line, $match) === 1) {
                $headings[] = $match['text'] ?? '';
            }

            array_push($links, ...self::links($line, $index + 1));
        }

        return new Page($path, $markers, $embeds, $strayFences, $unclosedFences, $frontmatter, $links, self::anchors($headings));
    }

    /**
     * The anchor GitHub gives a heading with this text, before a repeat adds its number.
     */
    public static function anchor(string $heading): string
    {
        $text = (string) preg_replace('/!?\[((?:[^\[\]]|\[[^\[\]]*\])*)\]\([^)]*\)/', '$1', $heading);
        $text = mb_strtolower(str_replace('`', '', $text));
        $text = (string) preg_replace('/[^\p{L}\p{N}\p{M} _-]/u', '', $text);

        return str_replace(' ', '-', $text);
    }

    /**
     * @param  list<string>  $headings
     * @return list<string>
     */
    private static function anchors(array $headings): array
    {
        $anchors = [];
        $seen = [];

        foreach ($headings as $heading) {
            $anchor = self::anchor($heading);
            $repeat = $seen[$anchor] ?? 0;
            $seen[$anchor] = $repeat + 1;
            $anchors[] = $repeat === 0 ? $anchor : "{$anchor}-{$repeat}";
        }

        return $anchors;
    }

    /**
     * @param  list<string>  $lines
     */
    private static function frontmatter(array $lines): ?Frontmatter
    {
        if (($lines[0] ?? null) !== self::FRONTMATTER_FENCE) {
            return null;
        }

        $values = [];
        $invalid = [];
        $count = count($lines);

        for ($index = 1; $index < $count; $index++) {
            $line = $lines[$index];

            if ($line === self::FRONTMATTER_FENCE) {
                return new Frontmatter($values, $invalid, $index + 1);
            }

            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            if (preg_match(self::FRONTMATTER_LINE, $line, $match) === 1) {
                $values[$match['key']] = self::scalar($match['value'] ?? '');
            } else {
                $invalid[] = $index + 1;
            }
        }

        return null;
    }

    /**
     * A YAML scalar on one line: a double-quoted string with `\"` and `\\` escapes, a single-quoted
     * string with `''` for a quote, or a plain value up to a comment.
     */
    private static function scalar(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return strtr(substr($value, 1, -1), ['\\"' => '"', '\\\\' => '\\']);
        }

        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            return str_replace("''", "'", substr($value, 1, -1));
        }

        return (string) preg_replace('/[ \t]+#.*$/', '', $value);
    }

    /**
     * The links on one line, with inline code masked so that a link inside it is none.
     *
     * @return list<Link>
     */
    private static function links(string $line, int $number): array
    {
        $masked = self::maskInlineCode($line);
        $links = [];

        if (preg_match(self::REFERENCE_DEFINITION, $masked, $match, PREG_OFFSET_CAPTURE) === 1) {
            return [new Link(self::unbracket($match['target'][0]), substr($line, $match['text'][1], strlen($match['text'][0])), $number, false)];
        }

        preg_match_all(self::INLINE_LINK, $masked, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $links[] = new Link(
                self::unbracket($match['target'][0]),
                substr($line, $match['text'][1], strlen($match['text'][0])),
                $number,
                $match['image'][0] === '!',
            );
        }

        return $links;
    }

    private static function unbracket(string $target): string
    {
        return str_starts_with($target, '<') && str_ends_with($target, '>') ? substr($target, 1, -1) : $target;
    }

    /**
     * The line with every code span, a run of backticks up to the next run of the same length,
     * replaced byte for byte by `x`, so offsets stay where they were.
     */
    private static function maskInlineCode(string $line): string
    {
        $length = strlen($line);
        $offset = 0;

        while ($offset < $length) {
            $start = strpos($line, '`', $offset);

            if ($start === false) {
                break;
            }

            $run = strspn($line, '`', $start);
            $close = self::closingRun($line, $start + $run, $run);

            if ($close === null) {
                $offset = $start + $run;

                continue;
            }

            $end = $close + $run;
            $line = substr_replace($line, str_repeat('x', $end - $start), $start, $end - $start);
            $offset = $end;
        }

        return $line;
    }

    /**
     * The offset of the next run of exactly $run backticks from $from, or null.
     */
    private static function closingRun(string $line, int $from, int $run): ?int
    {
        $length = strlen($line);

        while ($from < $length) {
            $start = strpos($line, '`', $from);

            if ($start === false) {
                return null;
            }

            $found = strspn($line, '`', $start);

            if ($found === $run) {
                return $start;
            }

            $from = $start + $found;
        }

        return null;
    }

    private static function openingFence(string $line): ?string
    {
        if (preg_match(self::OPENING_FENCE, $line, $match) !== 1) {
            return null;
        }

        // A backtick fence's info string may not contain a backtick (CommonMark 4.5).
        if ($match['fence'][0] === '`' && str_contains($match['info'], '`')) {
            return null;
        }

        return $match['fence'];
    }

    /**
     * The index of the line that closes the fence, or null when the block runs to the end.
     *
     * @param  list<string>  $lines
     */
    private static function closingLine(array $lines, int $from, string $fence): ?int
    {
        $pattern = '/^ {0,3}'.preg_quote($fence[0], '/').'{'.strlen($fence).',}[ \t]*$/';
        $count = count($lines);

        for ($index = $from; $index < $count; $index++) {
            if (preg_match($pattern, $lines[$index]) === 1) {
                return $index;
            }
        }

        return null;
    }
}
