<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\TypedPropertiesRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Rule 1 on properties. Promoted properties are checked as constructor parameters.
 *
 * @extends RuleTestCase<TypedPropertiesRule>
 */
final class TypedPropertiesRuleTest extends RuleTestCase
{
    use ReportedErrors;

    public function test_it_reports_loose_types_outside_boundary_and_adapter(): void
    {
        self::assertSame([
            '11 cboxCms.untypedArray',  // public array $untyped
            '16 cboxCms.mixed',         // public mixed $anything
            '19 cboxCms.untypedArray',  // array<string, mixed>
        ], $this->reported('Properties'));
    }

    protected function getRule(): Rule
    {
        return new TypedPropertiesRule;
    }
}
