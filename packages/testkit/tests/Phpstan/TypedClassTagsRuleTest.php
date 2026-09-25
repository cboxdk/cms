<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\TypedClassTagsRule;
use Fixture\Entries\Domain\Entries;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Rule 1 on the PHPDoc of a class: template bounds, the generic arguments of supertypes, and
 * the property and method tags, such as the generated properties of an Eloquent model.
 *
 * @extends RuleTestCase<TypedClassTagsRule>
 */
final class TypedClassTagsRuleTest extends RuleTestCase
{
    use ReportedErrors;

    public function test_it_reports_loose_types_in_class_tags_outside_boundary_and_adapter(): void
    {
        $restriction = ' only allowed in Boundary and Adapter namespaces (GUARDRAILS 2.2).';
        $entries = Entries::class;

        // Not reported: the implements tag with typed generics, the property-read tag with a
        // list, the method tag all(), the unbounded template of Collection, TKey of array-key,
        // TypedEntries, and the same tags on a class in an Adapter namespace.
        $this->analyse([self::fixture('ClassTags')], [
            ["The extends tag for Fixture\\Entries\\Domain\\Collection of class {$entries} uses mixed in its type Fixture\\Entries\\Domain\\Collection<mixed>. Use a precise type; mixed is".$restriction, 27],
            ["The property tag \$attributes of class {$entries} uses an untyped array in its type array<string, mixed>. Use list<T> or array<K, V> with key and value types other than mixed; untyped arrays are".$restriction, 27],
            ["Parameter \$filter of the method tag rows() of class {$entries} uses mixed in its type mixed. Use a precise type; mixed is".$restriction, 27],
            ["Return type of the method tag rows() of class {$entries} uses an untyped array in its type array. Use list<T> or array<K, V> with key and value types other than mixed; untyped arrays are".$restriction, 27],
            ['Template TRow of class Fixture\Entries\Domain\Rows uses an untyped array in its bound array. Use list<T> or array<K, V> with key and value types other than mixed; untyped arrays are'.$restriction, 40],
        ]);
    }

    protected function getRule(): Rule
    {
        return new TypedClassTagsRule;
    }
}
