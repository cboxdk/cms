<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

/**
 * Where the fourth marker word of the marker gate is the input hint of an HTML form control
 * rather than a marker: the one exception to the gate, decided by Sylvester on 27 September.
 *
 * In a `.html` file it is the attribute written `<word>="` or `<word>='`, preceded by whitespace
 * and inside a start or end tag, outside its quoted values. In a `.tsx` file it is the JSX
 * attribute written `<word>="`, `<word>='` or `<word>={`, preceded by whitespace, outside
 * comments, strings and template literals. The decision covers the attribute only, so a key
 * named after the word in an object literal, a props type or an interface still fails, even
 * when the object is spread onto an input. The word must be in lower case and singular, and
 * each exception is one word at one offset: a value, a variable, a key, a comment, text and
 * every other file type still fail the gate.
 *
 * The scan is lexical, not a parser. Where it cannot tell code from a comment or a string, such
 * as an apostrophe in JSX text, it treats the rest as a string, so the word there still fails.
 */
final readonly class InputHintAttributes
{
    /**
     * The file extensions, in lower case, whose files may carry the attribute.
     *
     * @var list<string>
     */
    public const array EXTENSIONS = ['html', 'tsx'];

    /**
     * Elements whose content HTML reads as text, never as tags.
     *
     * @var list<string>
     */
    private const array RAW_TEXT_ELEMENTS = ['script', 'style', 'textarea', 'title'];

    /**
     * The byte offsets in the contents where the word is the attribute.
     *
     * @return list<int>
     */
    public static function offsets(string $path, string $contents): array
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'html' => self::matches('/(?<=\s)'.MarkerScan::PLURAL.'(?==["\'])/', self::htmlTags($contents)),
            'tsx' => self::matches('/(?<=\s)'.MarkerScan::PLURAL.'(?==["\'{])/', self::tsxCode($contents)),
            default => [],
        };
    }

    /**
     * @return list<int>
     */
    private static function matches(string $pattern, string $subject): array
    {
        preg_match_all($pattern, $subject, $matches, PREG_OFFSET_CAPTURE);

        return array_map(static fn (array $match): int => $match[1], $matches[0]);
    }

    /**
     * The contents with every byte of a comment, and every byte inside a string or a template
     * literal outside its `${}` expressions, replaced by a space. Newlines and the quotes and
     * backticks stay, so offsets and lines are those of the contents.
     */
    private static function tsxCode(string $contents): string
    {
        $code = $contents;
        $length = strlen($contents);
        // One entry per open template literal: the brace depth of the code around it.
        $templates = [];
        $inTemplate = false;
        $depth = 0;
        $index = 0;

        while ($index < $length) {
            $character = $contents[$index];
            $next = $contents[$index + 1] ?? '';

            if ($inTemplate) {
                if ($character === '`') {
                    $inTemplate = false;
                    $depth = (int) array_pop($templates);
                    $index++;
                } elseif ($character === '$' && $next === '{') {
                    $inTemplate = false;
                    $depth = 0;
                    $index += 2;
                } else {
                    $end = $character === '\\' ? min($index + 2, $length) : $index + 1;
                    self::blank($code, $index, $end);
                    $index = $end;
                }

                continue;
            }

            if ($character === '/' && $next === '/') {
                $end = strpos($contents, "\n", $index);
                $end = $end === false ? $length : $end;
                self::blank($code, $index, $end);
                $index = $end;
            } elseif ($character === '/' && $next === '*') {
                $end = strpos($contents, '*/', $index + 2);
                $end = $end === false ? $length : $end + 2;
                self::blank($code, $index, $end);
                $index = $end;
            } elseif ($character === '"' || $character === "'") {
                $index = self::string($contents, $code, $index);
            } elseif ($character === '`') {
                $templates[] = $depth;
                $inTemplate = true;
                $index++;
            } elseif ($character === '{') {
                $depth++;
                $index++;
            } elseif ($character === '}') {
                if ($depth === 0 && $templates !== []) {
                    $inTemplate = true;
                } else {
                    $depth = max(0, $depth - 1);
                }

                $index++;
            } else {
                $index++;
            }
        }

        return $code;
    }

    /**
     * Blanks a quoted string that starts at the offset, up to its closing quote or the end of
     * the line, and returns the offset after it.
     */
    private static function string(string $contents, string &$code, int $start): int
    {
        $quote = $contents[$start];
        $length = strlen($contents);
        $index = $start + 1;

        while ($index < $length && $contents[$index] !== $quote && $contents[$index] !== "\n") {
            $index += $contents[$index] === '\\' && ($contents[$index + 1] ?? "\n") !== "\n" ? 2 : 1;
        }

        self::blank($code, $start + 1, min($index, $length));

        return $index < $length && $contents[$index] === $quote ? $index + 1 : $index;
    }

    /**
     * The contents with every byte outside a tag, and every byte inside a quoted attribute value,
     * replaced by a space: text, comments, doctypes and the content of raw text elements such as
     * `script`. Newlines and the quotes stay.
     */
    private static function htmlTags(string $contents): string
    {
        $tags = $contents;
        $length = strlen($contents);
        $index = 0;

        while ($index < $length) {
            if (preg_match('/\G<(\/?)([A-Za-z][A-Za-z0-9-]*)/', $contents, $match, 0, $index) === 1) {
                $index = self::tag($contents, $tags, $index + strlen($match[0]));
                $name = strtolower($match[2]);

                if ($match[1] === '' && in_array($name, self::RAW_TEXT_ELEMENTS, true)) {
                    $end = preg_match('/<\/'.$name.'(?![A-Za-z0-9-])/i', $contents, $close, PREG_OFFSET_CAPTURE, $index) === 1
                        ? $close[0][1]
                        : $length;
                    self::blank($tags, $index, $end);
                    $index = $end;
                }

                continue;
            }

            if (str_starts_with(substr($contents, $index, 4), '<!--')) {
                $end = strpos($contents, '-->', $index + 4);
                $end = $end === false ? $length : $end + 3;
            } elseif (($contents[$index] === '<') && in_array($contents[$index + 1] ?? '', ['!', '?'], true)) {
                $end = strpos($contents, '>', $index);
                $end = $end === false ? $length : $end + 1;
            } else {
                $end = $index + 1;
            }

            self::blank($tags, $index, $end);
            $index = $end;
        }

        return $tags;
    }

    /**
     * Reads a tag from after its name to its closing `>`, blanking its quoted values, and
     * returns the offset after it.
     */
    private static function tag(string $contents, string &$tags, int $index): int
    {
        $length = strlen($contents);

        while ($index < $length && $contents[$index] !== '>') {
            if ($contents[$index] === '"' || $contents[$index] === "'") {
                $end = strpos($contents, $contents[$index], $index + 1);
                $end = $end === false ? $length : $end;
                self::blank($tags, $index + 1, $end);
                $index = min($end + 1, $length);
            } else {
                $index++;
            }
        }

        return min($index + 1, $length);
    }

    /**
     * Replaces the bytes from the start up to the end with spaces, keeping newlines.
     */
    private static function blank(string &$subject, int $start, int $end): void
    {
        for ($index = $start; $index < $end; $index++) {
            if ($subject[$index] !== "\n") {
                $subject[$index] = ' ';
            }
        }
    }
}
