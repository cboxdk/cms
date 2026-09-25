<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

/**
 * Shared shapes for the layer rules.
 */
final class Rules
{
    /**
     * The targets may not use any of the dependencies.
     *
     * Each target is checked on its own. Given a list of targets, Pest's not->toUse() passes
     * as soon as one target does not use the dependency, so a list would hide a violation.
     *
     * A layer with no classes yet cannot break the rule. Pest cannot express that: with no
     * targets, not->toUse() fails, and with no dependencies it registers no assertion. So
     * when one side is empty, the test asserts exactly that and stops.
     *
     * @param  list<string>  $targets
     * @param  list<string>  $dependencies
     */
    public static function forbid(array $targets, array $dependencies): void
    {
        if ($targets === [] || $dependencies === []) {
            expect($targets === [] ? $targets : $dependencies)->toBeEmpty();

            return;
        }

        foreach ($targets as $target) {
            expect($target)->not->toUse($dependencies);
        }
    }

    /**
     * Formats violations as one line each, so a failing test names every file.
     *
     * @param  list<string>  $violations
     */
    public static function none(array $violations, string $rule): void
    {
        expect($violations)->toBe([], $rule."\n".implode("\n", $violations));
    }
}
