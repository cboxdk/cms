<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\TypedMethodsRule;
use Fixture\Boundary\Domain\Parser as InnermostDomainParser;
use Fixture\Entries\Domain\Parser;
use PHPStan\Analyser\Error;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Rule 1 on method signatures: which layers are checked, what counts as a typed array, and
 * that no ignore comment hides an error.
 *
 * @extends RuleTestCase<Rule<InClassMethodNode>>
 */
final class TypedMethodsRuleTest extends RuleTestCase
{
    use ReportedErrors;

    private bool $ignorable = false;

    public function test_it_reports_mixed_in_domain_but_not_in_boundary_adapter_or_tests(): void
    {
        $message = 'Parameter $value of method %s::parse() uses mixed in its type mixed. Use a precise type; mixed is only allowed in Boundary and Adapter namespaces (GUARDRAILS 2.2).';

        // Not reported: Fixture\Http\Boundary\Parsers (below Boundary), Fixture\Http\Boundary
        // (directly in a namespace ending in Boundary), Fixture\Receipts\Adapter and
        // Fixture\Entries\Tests. Fixture\Boundary\Domain is Domain: the innermost segment decides.
        $this->analyse([self::fixture('Layers')], [
            [sprintf($message, Parser::class), 10],
            [sprintf($message, InnermostDomainParser::class), 51],
        ]);
    }

    public function test_it_reports_untyped_arrays_array_shapes_and_nested_mixed(): void
    {
        self::assertSame([
            '19 cboxCms.untypedArray',   // array
            '28 cboxCms.untypedArray',   // array<string, mixed>
            '31 cboxCms.untypedArray',   // Entry[] has no key type
            '34 cboxCms.untypedArray',   // array<Entry> has no key type
            '37 cboxCms.untypedArray',   // list<mixed>
            '40 cboxCms.arrayShape',     // array{id: int}
            '46 cboxCms.untypedArray',   // list<array>
            '52 cboxCms.mixed',          // Box<mixed>
            '64 cboxCms.mixed',          // Generator<int, Entry> leaves TSend and TReturn mixed
            '67 cboxCms.mixed',          // callable(mixed): void
            '83 cboxCms.untypedArray',   // template bound "of array"
        ], $this->reported('Arrays'));
    }

    public function test_the_message_names_the_declaration_and_the_advice(): void
    {
        $errors = array_values(array_filter(
            $this->gatherAnalyserErrors([self::fixture('Arrays')]),
            static fn (Error $error): bool => $error->getLine() === 28,
        ));

        self::assertCount(1, $errors);
        self::assertSame(
            'Return type of method Fixture\Entries\Domain\Arrays::mixedMap() uses an untyped array in its type array<string, mixed>. Use list<T> or array<K, V> with key and value types other than mixed; untyped arrays are only allowed in Boundary and Adapter namespaces (GUARDRAILS 2.2).',
            $errors[0]->getMessage(),
        );
    }

    public function test_an_ignore_comment_on_the_reported_line_does_not_hide_the_error(): void
    {
        // Line 10 is the target of an ignore-next-line comment, line 12 has an ignore-line
        // comment, and line 14 ignores cboxCms.mixed by identifier. The rule still reports all
        // three, because its errors are non-ignorable.
        self::assertSame([
            '10 cboxCms.mixed',
            '12 cboxCms.mixed',
            '14 cboxCms.mixed',
        ], $this->reported('Ignores'));
    }

    public function test_the_same_errors_built_as_ignorable_are_hidden_by_those_comments(): void
    {
        // The control for the test above: the comments do target the reported lines, and an
        // ordinary rule would be silenced by them.
        $this->ignorable = true;

        self::assertSame([], $this->reported('Ignores'));
        self::assertSame(['10 cboxCms.mixed (ignorable)', '51 cboxCms.mixed (ignorable)'], $this->reported('Layers'));
    }

    /**
     * @return Rule<InClassMethodNode>
     */
    protected function getRule(): Rule
    {
        return $this->ignorable ? new IgnorableTypedMethodsRule : new TypedMethodsRule;
    }
}
