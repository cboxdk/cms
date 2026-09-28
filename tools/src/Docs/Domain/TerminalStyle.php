<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * The graphic rendition of a character on the terminal of a screenshot (TerminalSvg): colours as
 * `#rrggbb` or null for the terminal's own, and the attributes an SGR sequence, `ESC [ ... m`,
 * sets (ECMA-48 8.3.117).
 */
final readonly class TerminalStyle
{
    /**
     * The 16 colours of the palette: 0 to 7 normal, 8 to 15 bright.
     *
     * @var list<string>
     */
    public const array PALETTE = [
        '#000000', '#cd3131', '#0dbc79', '#e5e510', '#2472c8', '#bc3fbc', '#11a8cd', '#e5e5e5',
        '#666666', '#f14c4c', '#23d18b', '#f5f543', '#3b8eea', '#d670d6', '#29b8db', '#ffffff',
    ];

    public const string FOREGROUND = '#e6edf3';

    public const string BACKGROUND = '#0d1117';

    /**
     * The levels of each channel in the colour cube of the 256-colour palette, 16 to 231.
     *
     * @var list<int>
     */
    private const array CUBE = [0, 95, 135, 175, 215, 255];

    public function __construct(
        public ?string $foreground = null,
        public ?string $background = null,
        public bool $bold = false,
        public bool $dim = false,
        public bool $italic = false,
        public bool $underline = false,
        public bool $inverse = false,
    ) {}

    /**
     * The style after the parameters of one SGR sequence, such as `1;31` or `38;5;208`. Codes it
     * does not know leave the style as it was.
     */
    public function apply(string $parameters): self
    {
        $codes = array_map(static fn (string $code): int => $code === '' ? 0 : (int) $code, explode(';', $parameters));
        $style = $this;
        $count = count($codes);

        for ($index = 0; $index < $count; $index++) {
            $code = $codes[$index];

            if ($code === 38 || $code === 48) {
                $rest = array_slice($codes, $index + 1);
                $colour = $this->extendedColour($rest);
                $index += $this->extendedLength($rest);

                if ($colour !== null) {
                    $style = $code === 38 ? $style->with(foreground: $colour) : $style->with(background: $colour);
                }

                continue;
            }

            $style = $style->code($code);
        }

        return $style;
    }

    public function equals(self $other): bool
    {
        return $this->foreground === $other->foreground
            && $this->background === $other->background
            && $this->bold === $other->bold
            && $this->dim === $other->dim
            && $this->italic === $other->italic
            && $this->underline === $other->underline
            && $this->inverse === $other->inverse;
    }

    /**
     * The colour this style draws its text in, after inverse.
     */
    public function textColour(): string
    {
        return $this->inverse ? ($this->background ?? self::BACKGROUND) : ($this->foreground ?? self::FOREGROUND);
    }

    /**
     * The colour of the cell behind the text, after inverse, or null for the terminal's own.
     */
    public function cellColour(): ?string
    {
        return $this->inverse ? ($this->foreground ?? self::FOREGROUND) : $this->background;
    }

    /**
     * The colour of an index of the 256-colour palette.
     */
    public static function indexed(int $index): ?string
    {
        if ($index < 0 || $index > 255) {
            return null;
        }

        if ($index < 16) {
            return self::PALETTE[$index];
        }

        if ($index < 232) {
            $cube = $index - 16;

            return self::hex(self::CUBE[intdiv($cube, 36)], self::CUBE[intdiv($cube, 6) % 6], self::CUBE[$cube % 6]);
        }

        $grey = 8 + 10 * ($index - 232);

        return self::hex($grey, $grey, $grey);
    }

    private function code(int $code): self
    {
        return match (true) {
            $code === 0 => new self,
            $code === 1 => $this->with(bold: true),
            $code === 2 => $this->with(dim: true),
            $code === 3 => $this->with(italic: true),
            $code === 4 => $this->with(underline: true),
            $code === 7 => $this->with(inverse: true),
            $code === 22 => $this->with(bold: false, dim: false),
            $code === 23 => $this->with(italic: false),
            $code === 24 => $this->with(underline: false),
            $code === 27 => $this->with(inverse: false),
            $code >= 30 && $code <= 37 => $this->with(foreground: self::PALETTE[$code - 30]),
            $code === 39 => $this->withDefault(foreground: true),
            $code >= 40 && $code <= 47 => $this->with(background: self::PALETTE[$code - 40]),
            $code === 49 => $this->withDefault(foreground: false),
            $code >= 90 && $code <= 97 => $this->with(foreground: self::PALETTE[$code - 90 + 8]),
            $code >= 100 && $code <= 107 => $this->with(background: self::PALETTE[$code - 100 + 8]),
            default => $this,
        };
    }

    /**
     * The colour of `5;<index>` or `2;<r>;<g>;<b>` after 38 or 48, or null when the codes are
     * neither.
     *
     * @param  list<int>  $codes  the codes after 38 or 48
     */
    private function extendedColour(array $codes): ?string
    {
        if (($codes[0] ?? null) === 5 && isset($codes[1])) {
            return self::indexed($codes[1]);
        }

        if (($codes[0] ?? null) === 2 && isset($codes[3])) {
            $channel = static fn (int $value): int => max(0, min(255, $value));

            return self::hex($channel($codes[1]), $channel($codes[2]), $channel($codes[3]));
        }

        return null;
    }

    /**
     * How many of the codes after 38 or 48 the colour takes; all of them when they are neither
     * form, since the rest of the sequence cannot be read then.
     *
     * @param  list<int>  $codes  the codes after 38 or 48
     */
    private function extendedLength(array $codes): int
    {
        return match (true) {
            ($codes[0] ?? null) === 5 && isset($codes[1]) => 2,
            ($codes[0] ?? null) === 2 && isset($codes[3]) => 4,
            default => count($codes),
        };
    }

    private static function hex(int $red, int $green, int $blue): string
    {
        return sprintf('#%02x%02x%02x', $red, $green, $blue);
    }

    private function with(
        ?string $foreground = null,
        ?string $background = null,
        ?bool $bold = null,
        ?bool $dim = null,
        ?bool $italic = null,
        ?bool $underline = null,
        ?bool $inverse = null,
    ): self {
        return new self(
            $foreground ?? $this->foreground,
            $background ?? $this->background,
            $bold ?? $this->bold,
            $dim ?? $this->dim,
            $italic ?? $this->italic,
            $underline ?? $this->underline,
            $inverse ?? $this->inverse,
        );
    }

    private function withDefault(bool $foreground): self
    {
        return new self(
            $foreground ? null : $this->foreground,
            $foreground ? $this->background : null,
            $this->bold,
            $this->dim,
            $this->italic,
            $this->underline,
            $this->inverse,
        );
    }
}
