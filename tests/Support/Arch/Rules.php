<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

use Pest\Arch\Factories\LayerFactory;
use Pest\Arch\Layer as PestLayer;
use Pest\Arch\Options\LayerOptions;
use Pest\Arch\Repositories\ObjectsRepository;
use Pest\Arch\SingleArchExpectation;
use Pest\Expectation;

/**
 * Shared shapes for the layer rules.
 */
final class Rules
{
    /**
     * The targets may use only the allowed classes and namespaces, as Pest's toOnlyUse() decides
     * it: every name an object of a target's layer uses must be the name of an object in the layer
     * of that target or of an allowed entry, each layer made by Pest's
     * LayerFactory with the options of the arch expectation, the test's ignores included.
     *
     * Pest's toOnlyUse() makes the layer of every allowed entry again for every target, which for
     * the Domain rule, a thousand classes that may use a thousand classes, took more than two
     * minutes of gate 5 and of the mutation run's coverage. This makes each layer once and gives
     * the same verdict, and it names every violation instead of the first.
     *
     * @param  list<string>  $targets
     * @param  list<string>  $allowed
     */
    public static function onlyUse(array $targets, array $allowed): void
    {
        /** @var Expectation<array<int, string>|string> $expectation */
        $expectation = expect($targets);

        SingleArchExpectation::fromExpectation($expectation, static function (LayerOptions $options) use ($targets, $allowed): void {
            $factory = new LayerFactory(ObjectsRepository::getInstance());
            $layers = [];
            $layer = static function (string $name) use ($factory, $options, &$layers): PestLayer {
                return $layers[$name] ??= $factory->make($options, $name);
            };
            $names = static function (PestLayer $layer): array {
                $names = [];

                foreach ($layer as $object) {
                    $names[$object->name] = true;
                }

                return $names;
            };

            $permitted = [];

            foreach ($allowed as $entry) {
                $permitted += $names($layer($entry));
            }

            $violations = [];

            foreach ($targets as $target) {
                $own = $names($layer($target));

                foreach ($layer($target) as $object) {
                    foreach ($object->uses as $use) {
                        if (! isset($permitted[$use]) && ! isset($own[$use])) {
                            $violations[] = "{$object->name} ({$target}) uses {$use}.";
                        }
                    }
                }
            }

            expect(array_values(array_unique($violations)))->toBe([], "Expecting the targets to only use the allowed classes and namespaces. However:\n".implode("\n", array_unique($violations)));
        })->ensureLazyExpectationIsVerified();
    }

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
