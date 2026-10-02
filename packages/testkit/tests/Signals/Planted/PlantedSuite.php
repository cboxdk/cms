<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Signals\Planted;

use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Throwable;

/**
 * Runs a shared suite outside PHPUnit, so a test can see which of its cases fail against a planted
 * implementation: a case fails when it fails an assertion or throws.
 */
final readonly class PlantedSuite
{
    /**
     * @return list<string> the cases that fail, in the order of the suite
     */
    public static function failing(object $suite): array
    {
        $failing = [];

        foreach (new ReflectionClass($suite)->getMethods() as $method) {
            if ($method->getAttributes(Test::class) === []) {
                continue;
            }

            try {
                $method->invoke($suite);
            } catch (Throwable) {
                $failing[] = $method->getName();
            }
        }

        return $failing;
    }
}
