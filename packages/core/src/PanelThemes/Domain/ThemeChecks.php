<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\ComposedTheme;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;

/**
 * What a composed theme must keep for the panel to stay WCAG 2.2 AA (GUARDRAILS 8, PRD 13.4), on
 * the whole panel and on each part hook a theme names, in the light and the dark mode:
 *
 * - every contrast pair of the catalogue reaches its minimum, 4.5:1 for text and 3:1 for a user
 *   interface part or the focus ring (1.4.3, 1.4.11), else registry_panel_theme_contrast;
 * - --cms-target-size is a length of at least 24 pixels (2.5.8), else registry_panel_theme_invalid.
 *
 * The values are resolved after composition, so a theme that changes one side of a pair is checked
 * against the other side as every selected theme left it. Pure.
 */
#[Experimental]
final readonly class ThemeChecks
{
    /** The token every pointer target is at least as high as. */
    public const string TARGET_SIZE = 'target-size';

    /** The smallest pointer target in pixels (WCAG 2.2, 2.5.8). */
    public const int MINIMUM_TARGET_PIXELS = 24;

    /** Pixels per rem and em, as the kit's catalogue counts them. */
    private const int PIXELS_PER_REM = 16;

    /**
     * @param  string  $subject  what the problems are about, such as "The composed theme app, fixtureaddon:brand"
     * @return list<BuildProblem>
     */
    public static function problems(ComposedTheme $theme, string $subject): array
    {
        $problems = [];

        foreach ([null, ...array_keys($theme->parts)] as $part) {
            $place = $part === null ? 'on the whole panel' : sprintf('on the part hook %s', $part);
            $values = $theme->valuesAt($part);

            foreach (Mode::cases() as $mode) {
                $resolved = ResolvedTokens::of($theme->catalogue, $values, $mode);

                foreach ($theme->catalogue->contrast as $pair) {
                    $ratio = Contrast::ratio($resolved[$pair->foreground] ?? '', $resolved[$pair->background] ?? '');

                    if ($ratio < $pair->kind->minimum()) {
                        $problems[] = new BuildProblem(BuildErrorCode::PanelThemeContrast, sprintf(
                            '%s draws --cms-%s on --cms-%s (%s) at %s in the %s mode %s, below the %s WCAG 2.2 AA needs. Change the theme\'s value of one of them, in the mode the problem names.',
                            $subject,
                            $pair->foreground,
                            $pair->background,
                            $pair->kind->value,
                            Contrast::format($ratio),
                            $mode->value,
                            $place,
                            Contrast::format($pair->kind->minimum()),
                        ));
                    }
                }

                $size = $resolved[self::TARGET_SIZE] ?? null;

                if ($size !== null && (self::pixels($size) ?? 0.0) < self::MINIMUM_TARGET_PIXELS) {
                    $problems[] = new BuildProblem(BuildErrorCode::PanelThemeInvalid, sprintf(
                        '%s sets --cms-%s to %s in the %s mode %s, and a pointer target must be at least %d pixels high (WCAG 2.2, 2.5.8). Give it %dpx, 1.5rem or more.',
                        $subject,
                        self::TARGET_SIZE,
                        $size,
                        $mode->value,
                        $place,
                        self::MINIMUM_TARGET_PIXELS,
                        self::MINIMUM_TARGET_PIXELS,
                    ));
                }
            }
        }

        return $problems;
    }

    /**
     * The length in pixels, or null for a percentage, which has no size of its own.
     */
    private static function pixels(string $length): ?float
    {
        if (preg_match('/\A(\d+(?:\.\d+)?)(px|rem|em)?\z/', $length, $match) !== 1) {
            return null;
        }

        $unit = $match[2] ?? '';

        return (float) $match[1] * ($unit === 'rem' || $unit === 'em' ? self::PIXELS_PER_REM : 1);
    }
}
