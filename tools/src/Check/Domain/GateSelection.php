<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use InvalidArgumentException;

/**
 * The gates a run of `composer check` is limited to with `--gate=<n>`, such as
 * `composer check -- --pr --gate=7` for the component kit's Storybook alone, as
 * `composer check:selftest` runs gate 7 of the PR profile next to the local profile. The other
 * gates keep their steps, each reported as not run with NOT_SELECTED, so the report still lists
 * every gate of the profile and says what was left out (GUARDRAILS 10).
 */
final readonly class GateSelection
{
    public const string NOT_SELECTED = 'not selected: this run is limited to the gates named with --gate';

    /**
     * The gates, with every step of a gate whose number is not among $numbers reported as not run.
     * No numbers select every gate.
     *
     * @param  list<Gate>  $gates
     * @param  list<int>  $numbers
     * @return list<Gate>
     */
    public static function only(array $gates, array $numbers): array
    {
        if ($numbers === []) {
            return $gates;
        }

        $known = array_map(static fn (Gate $gate): int => $gate->number, $gates);
        $unknown = array_values(array_diff($numbers, $known));

        if ($unknown !== []) {
            throw new InvalidArgumentException('The profile has no gate '.implode(', ', $unknown).'.');
        }

        return array_map(
            static fn (Gate $gate): Gate => in_array($gate->number, $numbers, true)
                ? $gate
                : new Gate($gate->number, $gate->title, array_map(
                    static fn (Step $step): Step => Step::notRun($step->name, self::NOT_SELECTED),
                    $gate->steps,
                )),
            $gates,
        );
    }

    /**
     * How the header of a limited run names its gates, such as "; limited to gate 7 with --gate";
     * empty for a run of every gate.
     *
     * @param  list<int>  $numbers
     */
    public static function description(array $numbers): string
    {
        if ($numbers === []) {
            return '';
        }

        $sorted = array_values(array_unique($numbers));
        sort($sorted);

        return '; limited to '.(count($sorted) === 1 ? 'gate ' : 'gates ').implode(', ', $sorted).' with --gate';
    }
}
