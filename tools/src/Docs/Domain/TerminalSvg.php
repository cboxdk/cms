<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * Draws the output of a command as a terminal window in SVG, for the screenshots of the
 * documentation (Screenshots). The first line is the prompt with the command, then the output as a
 * terminal of the given width shows it: a line longer than the width wraps, `\r` returns to the
 * first column, a tab moves to the next multiple of 8, SGR sequences (`ESC [ ... m`) set colours
 * and attributes (TerminalStyle), `ESC [ K` clears to the end of the line, and every other escape
 * sequence and control character is dropped. Empty lines at the end are left out.
 *
 * The same command line, output and width always give the same bytes. Every run of characters
 * starts at its column and is stretched to its width, so the columns line up whatever monospace
 * font the viewer has.
 */
final readonly class TerminalSvg
{
    public const float CELL_WIDTH = 7.8;

    public const int FONT_SIZE = 13;

    public const int LINE_HEIGHT = 20;

    public const int PADDING = 16;

    public const int TITLE_BAR = 34;

    private const string FONTS = "ui-monospace, SFMono-Regular, Menlo, Consolas, 'DejaVu Sans Mono', 'Liberation Mono', monospace";

    private const string TITLE_BAR_COLOUR = '#161b22';

    private const string BORDER_COLOUR = '#30363d';

    /** @var list<string> */
    private const array BUTTONS = ['#ff5f57', '#febc2e', '#28c840'];

    public static function render(string $commandLine, string $output, int $columns): string
    {
        $lines = self::screen("\e[1;32m\$\e[0m \e[1m{$commandLine}\e[0m\n".$output, $columns);
        $width = self::number(2 * self::PADDING + $columns * self::CELL_WIDTH);
        $height = self::TITLE_BAR + 2 * self::PADDING + count($lines) * self::LINE_HEIGHT;
        $svg = [
            "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$width}\" height=\"{$height}\" viewBox=\"0 0 {$width} {$height}\" role=\"img\" aria-label=\"".self::escape($commandLine).'">',
            '<title>'.self::escape($commandLine).'</title>',
            '<style>text{font-family:'.self::escape(self::FONTS).';font-size:'.self::FONT_SIZE.'px;white-space:pre}.b{font-weight:700}.d{opacity:.66}.i{font-style:italic}.u{text-decoration:underline}</style>',
            '<rect x="0.5" y="0.5" width="'.self::number((float) $width - 1).'" height="'.($height - 1).'" rx="8" fill="'.TerminalStyle::BACKGROUND.'" stroke="'.self::BORDER_COLOUR.'"/>',
            '<path d="M0.5 '.(self::TITLE_BAR).'V8.5a8 8 0 0 1 8-8H'.self::number((float) $width - 8.5).'a8 8 0 0 1 8 8V'.self::TITLE_BAR.'Z" fill="'.self::TITLE_BAR_COLOUR.'" stroke="'.self::BORDER_COLOUR.'"/>',
        ];

        foreach (self::BUTTONS as $index => $colour) {
            $svg[] = '<circle cx="'.(20 + 20 * $index).'" cy="17" r="6" fill="'.$colour.'"/>';
        }

        foreach ($lines as $row => $cells) {
            array_push($svg, ...self::line($cells, self::TITLE_BAR + self::PADDING + $row * self::LINE_HEIGHT));
        }

        $svg[] = '</svg>';

        return implode("\n", $svg)."\n";
    }

    /**
     * The lines of cells the text leaves on a terminal of the width.
     *
     * @return list<list<TerminalCell>>
     */
    public static function screen(string $text, int $columns): array
    {
        $columns = max(1, $columns);
        /** @var list<list<TerminalCell>> $lines */
        $lines = [[]];
        $row = 0;
        $column = 0;
        $style = new TerminalStyle;
        $length = strlen($text);
        $offset = 0;

        while ($offset < $length) {
            $byte = $text[$offset];

            if ($byte === "\e") {
                $sequence = self::escapeSequence($text, $offset);

                if (str_ends_with($sequence, 'm') && str_starts_with($sequence, "\e[")) {
                    $style = $style->apply(substr($sequence, 2, -1));
                } elseif ($sequence === "\e[K" || $sequence === "\e[0K") {
                    $lines[$row] = array_slice($lines[$row], 0, $column);
                }

                $offset += strlen($sequence);

                continue;
            }

            $offset++;

            if ($byte === "\n") {
                $row++;
                $column = 0;
                $lines[$row] = [];
            } elseif ($byte === "\r") {
                $column = 0;
            } elseif ($byte === "\t") {
                $column = min($columns, (intdiv($column, 8) + 1) * 8);
            } elseif ($byte === "\x08") {
                $column = max(0, $column - 1);
            } elseif (ord($byte[0]) >= 0x20 && ord($byte[0]) !== 0x7F) {
                $width = self::codePointLength($byte);
                $character = substr($text, $offset - 1, $width);
                $offset += $width - 1;

                if ($column >= $columns) {
                    $row++;
                    $column = 0;
                    $lines[$row] = [];
                }

                for ($gap = count($lines[$row]); $gap < $column; $gap++) {
                    $lines[$row][$gap] = new TerminalCell(' ', new TerminalStyle);
                }

                $lines[$row][$column] = new TerminalCell(mb_check_encoding($character, 'UTF-8') ? $character : "\u{FFFD}", $style);
                $column++;
            }
        }

        $lines = array_map(array_values(...), $lines);

        while ($lines !== [] && self::isBlank(array_last($lines))) {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * The escape sequence at the offset: a CSI (`ESC [` up to a final byte from `@` to `~`), an OSC
     * (`ESC ]` up to BEL or `ESC \`), or ESC and the one character after it.
     */
    private static function escapeSequence(string $text, int $offset): string
    {
        $next = $text[$offset + 1] ?? '';

        if ($next === '[' && preg_match('/\G\e\[[\x30-\x3F]*[\x20-\x2F]*[\x40-\x7E]/', $text, $match, 0, $offset) === 1) {
            return $match[0];
        }

        if ($next === ']' && preg_match('/\G\e\][^\x07\e]*(?:\x07|\e\\\\)?/', $text, $match, 0, $offset) === 1) {
            return $match[0];
        }

        return substr($text, $offset, 2);
    }

    private static function codePointLength(string $lead): int
    {
        $byte = ord($lead[0]);

        return match (true) {
            $byte >= 0xF0 => 4,
            $byte >= 0xE0 => 3,
            $byte >= 0xC0 => 2,
            default => 1,
        };
    }

    /**
     * @param  list<TerminalCell>  $cells
     */
    private static function isBlank(array $cells): bool
    {
        return array_all($cells, static fn (TerminalCell $cell): bool => $cell->character === ' ' && $cell->style->cellColour() === null);
    }

    /**
     * The SVG elements of one line: a rectangle behind every run with a background, and a text
     * element for every run of the same style that is not only spaces.
     *
     * @param  list<TerminalCell>  $cells
     * @return list<string>
     */
    private static function line(array $cells, int $top): array
    {
        $elements = [];
        $baseline = $top + 14;
        $start = 0;
        $count = count($cells);

        while ($start < $count) {
            $style = $cells[$start]->style;
            $end = $start + 1;

            while ($end < $count && $cells[$end]->style->equals($style)) {
                $end++;
            }

            $text = implode('', array_map(static fn (TerminalCell $cell): string => $cell->character, array_slice($cells, $start, $end - $start)));
            $x = self::number(self::PADDING + $start * self::CELL_WIDTH);
            $runWidth = self::number(($end - $start) * self::CELL_WIDTH);
            $background = $style->cellColour();

            if ($background !== null) {
                $elements[] = "<rect x=\"{$x}\" y=\"{$top}\" width=\"{$runWidth}\" height=\"".self::LINE_HEIGHT."\" fill=\"{$background}\"/>";
            }

            $trimmed = rtrim($text, ' ');

            if ($trimmed !== '') {
                $classes = array_keys(array_filter(['b' => $style->bold, 'd' => $style->dim, 'i' => $style->italic, 'u' => $style->underline]));
                $class = $classes === [] ? '' : ' class="'.implode(' ', $classes).'"';
                $textWidth = self::number(mb_strlen($trimmed) * self::CELL_WIDTH);
                $elements[] = "<text x=\"{$x}\" y=\"{$baseline}\" fill=\"{$style->textColour()}\"{$class} textLength=\"{$textWidth}\">".self::escape($trimmed).'</text>';
            }

            $start = $end;
        }

        return $elements;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.2F', $value), '0'), '.');
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
