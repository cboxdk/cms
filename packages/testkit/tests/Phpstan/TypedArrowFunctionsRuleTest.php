<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\TypedArrowFunctionsRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Rule 1 on arrow functions: only native declarations, since PHPStan infers the rest.
 *
 * @extends RuleTestCase<TypedArrowFunctionsRule>
 */
final class TypedArrowFunctionsRuleTest extends RuleTestCase
{
    use ReportedErrors;

    public function test_it_reports_loose_types_outside_boundary_and_adapter(): void
    {
        self::assertSame([
            '31 cboxCms.untypedArray',  // fn (array $items): int
            '32 cboxCms.untypedArray',  // fn (ClosureEntry $entry): array
        ], $this->reported('Closures'));
    }

    protected function getRule(): Rule
    {
        return new TypedArrowFunctionsRule;
    }
}
