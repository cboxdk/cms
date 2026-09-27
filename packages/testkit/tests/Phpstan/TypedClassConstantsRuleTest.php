<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\TypedClassConstantsRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Rule 1 on class constants: a `const array` without a typed var tag is an untyped array, and the
 * type in the var tag is checked like any other declaration.
 *
 * @extends RuleTestCase<TypedClassConstantsRule>
 */
final class TypedClassConstantsRuleTest extends RuleTestCase
{
    use ReportedErrors;

    public function test_it_reports_loose_types_outside_boundary_and_adapter(): void
    {
        self::assertSame([
            '11 cboxCms.untypedArray',  // const array UNTYPED
            '17 cboxCms.untypedArray',  // array<string, mixed>
            '20 cboxCms.arrayShape',    // array{a: int}
            '26 cboxCms.untypedArray',  // const array FIRST
            '26 cboxCms.untypedArray',  // const array SECOND
            '34 cboxCms.untypedArray',  // the interface's const array
            '41 cboxCms.untypedArray',  // the enum's const array
        ], $this->reported('Constants'));
    }

    public function test_it_names_the_constant_and_its_type(): void
    {
        $this->analyse([self::fixture('Constants')], [
            ['Constant Fixture\Entries\Domain\Constants::UNTYPED uses an untyped array in its type array. Use list<T> or array<K, V> with key and value types other than mixed; untyped arrays are only allowed in Boundary and Adapter namespaces (GUARDRAILS 2.2).', 11],
            ['Constant Fixture\Entries\Domain\Constants::MIXED_MAP uses an untyped array in its type array<string, mixed>. Use list<T> or array<K, V> with key and value types other than mixed; untyped arrays are only allowed in Boundary and Adapter namespaces (GUARDRAILS 2.2).', 17],
            ['Constant Fixture\Entries\Domain\Constants::SHAPE uses an array shape in its type array{a: int}. Use a DTO for structured data; array shapes are only allowed in Boundary and Adapter namespaces (GUARDRAILS 2.2).', 20],
            ['Constant Fixture\Entries\Domain\Constants::FIRST uses an untyped array in its type array. Use list<T> or array<K, V> with key and value types other than mixed; untyped arrays are only allowed in Boundary and Adapter namespaces (GUARDRAILS 2.2).', 26],
            ['Constant Fixture\Entries\Domain\Constants::SECOND uses an untyped array in its type array. Use list<T> or array<K, V> with key and value types other than mixed; untyped arrays are only allowed in Boundary and Adapter namespaces (GUARDRAILS 2.2).', 26],
            ['Constant Fixture\Entries\Domain\ConstantsInterface::UNTYPED uses an untyped array in its type array. Use list<T> or array<K, V> with key and value types other than mixed; untyped arrays are only allowed in Boundary and Adapter namespaces (GUARDRAILS 2.2).', 34],
            ['Constant Fixture\Entries\Domain\ConstantsEnum::UNTYPED uses an untyped array in its type array. Use list<T> or array<K, V> with key and value types other than mixed; untyped arrays are only allowed in Boundary and Adapter namespaces (GUARDRAILS 2.2).', 41],
        ]);
    }

    protected function getRule(): Rule
    {
        return new TypedClassConstantsRule;
    }
}
