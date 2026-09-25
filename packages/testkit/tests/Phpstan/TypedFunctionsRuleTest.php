<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\TypedFunctionsRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Rule 1 on function signatures.
 *
 * @extends RuleTestCase<TypedFunctionsRule>
 */
final class TypedFunctionsRuleTest extends RuleTestCase
{
    use ReportedErrors;

    public function test_it_reports_loose_types_outside_boundary_and_adapter(): void
    {
        self::assertSame([
            '9 cboxCms.mixed',          // untyped(): mixed
            '9 cboxCms.untypedArray',   // untyped(array $entries)
        ], $this->reported('Closures'));
    }

    protected function getRule(): Rule
    {
        return new TypedFunctionsRule;
    }
}
