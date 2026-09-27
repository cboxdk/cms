<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * Reads the markers and fenced code blocks of a Markdown page.
 *
 * A marker is an HTML comment on a line of its own, `<!-- <kind>: <target> -->` (MarkerKind). A
 * fenced block opens with three or more backticks or tildes, indented by at most three spaces, and
 * closes with a line of at least as many of the same character and nothing else (CommonMark). A
 * marker inside a fenced block is content, not a marker. An example or example-file marker embeds
 * the fenced block that opens on the very next line.
 */
final readonly class PageParser
{
    private const string MARKER = '/^<!--\s*(?<kind>extension-point|example-file|example):\s*(?<target>.*?)\s*-->\s*$/';

    private const string OPENING_FENCE = '/^ {0,3}(?<fence>`{3,}|~{3,})(?<info>.*)$/';

    /**
     * @param  string  $path  repo-relative
     */
    public static function parse(string $path, string $markdown): Page
    {
        $lines = explode("\n", $markdown);

        if (end($lines) === '') {
            array_pop($lines);
        }

        $markers = [];
        $embeds = [];
        $strayFences = [];
        $unclosedFences = [];
        $previous = null;
        $count = count($lines);

        for ($index = 0; $index < $count; $index++) {
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
            }
        }

        return new Page($path, $markers, $embeds, $strayFences, $unclosedFences);
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
