<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\TypedClosuresRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Rule 1 on closures: only native declarations, since PHPStan reads no PHPDoc on closures.
 *
 * @extends RuleTestCase<TypedClosuresRule>
 */
final class TypedClosuresRuleTest extends RuleTestCase
{
    use ReportedErrors;

    public function test_it_reports_loose_types_outside_boundary_and_adapter(): void
    {
        self::assertSame([
            '33 cboxCms.mixed',         // function (mixed $value): bool
        ], $this->reported('Closures'));
    }

    protected function getRule(): Rule
    {
        return new TypedClosuresRule;
    }
}
