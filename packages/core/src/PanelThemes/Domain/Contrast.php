<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * The contrast ratio of two colours as WCAG 2.2 defines it, for the colour forms a token takes:
 * `#rrggbb` and `oklch(L% C H)`, which is converted through OKLab to sRGB and clipped to its gamut,
 * as js/ui-kit/scripts/tokens.js measures the catalogue.
 */
#[Experimental]
final readonly class Contrast
{
    private const string HEX = '/\A#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})\z/';

    private const string OKLCH = '/\Aoklch\((\d+(?:\.\d+)?)% (\d+(?:\.\d+)?) (\d+(?:\.\d+)?)\)\z/';

    /**
     * @throws InvalidArgumentException when a colour is of neither form
     */
    public static function ratio(string $first, string $second): float
    {
        $lighter = max(self::luminance($first), self::luminance($second));
        $darker = min(self::luminance($first), self::luminance($second));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * The ratio as the catalogue's documentation writes it, rounded down to two decimals: "4.49:1".
     */
    public static function format(float $ratio): string
    {
        return sprintf('%.2f:1', floor($ratio * 100) / 100);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function luminance(string $colour): float
    {
        $channels = self::linearRgb($colour);

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * @return list<float> red, green and blue, each from 0 to 1
     *
     * @throws InvalidArgumentException
     */
    private static function linearRgb(string $colour): array
    {
        if (preg_match(self::HEX, $colour, $hex) === 1) {
            $channel = static function (string $digits): float {
                $value = hexdec($digits) / 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            };

            return [$channel($hex[1]), $channel($hex[2]), $channel($hex[3])];
        }

        if (preg_match(self::OKLCH, $colour, $oklch) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a colour of the form #rrggbb or oklch(L%% C H).', $colour));
        }

        $lightness = (float) $oklch[1] / 100;
        $chroma = (float) $oklch[2];
        $hue = (float) $oklch[3] * M_PI / 180;
        $a = $chroma * cos($hue);
        $b = $chroma * sin($hue);
        $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($lightness - 0.0894841775 * $a - 1.291485548 * $b) ** 3;
        $clip = static fn (float $value): float => min(1.0, max(0.0, $value));

        return [
            $clip(4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s),
            $clip(-1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s),
            $clip(-0.0041960863 * $l - 0.7034186147 * $m + 1.707614701 * $s),
        ];
    }
}
