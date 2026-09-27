<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\InternalUse;
use Cbox\Cms\Testkit\Phpstan\InternalUseCollector;
use PhpParser\Node;
use PHPStan\Collectors\Collector;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Rule 5, GUARDRAILS 2.3: code outside Cbox\Cms may not use what is marked #[Internal]. The
 * test container registers the three extensions, InternalUseIgnoreErrorExtension and
 * InternalUseRule as the testkit neon does, and RegisteredRules runs every rule of the
 * container, since PHPStan's own rules call the extensions. getCollectors() adds
 * InternalUseCollector, which RuleTestCase does not take from the container.
 *
 * @extends RuleTestCase<Rule<Node>>
 */
final class InternalUseTest extends RuleTestCase
{
    use ReportedErrors;

    public function test_an_addon_may_not_use_internal_classes_methods_or_constants_but_the_core_may(): void
    {
        // Nothing in Cbox\Cms\Core\Fixture\Engine (lines 7 to 59) is reported, and line 69
        // uses only stable members of the stable Registry. Each use is reported once, and
        // non-ignorable: only an ignore comment that names cboxCms.internalUse hides one.
        self::assertSame([
            '74 cboxCms.internalUse',   // $registry->flush(), an #[Internal] method
            '79 cboxCms.internalUse',   // Registry::reset(), an #[Internal] static method
            '84 cboxCms.internalUse',   // Registry::SECRET, an #[Internal] constant
            '87 cboxCms.internalUse',   // parameter type Engine
            '89 cboxCms.internalUse',   // $engine->run(), a method of an #[Internal] class
            '92 cboxCms.internalUse',   // return type Engine
            '94 cboxCms.internalUse',   // new Engine
            '99 cboxCms.internalUse',   // Engine::boot()
            '104 cboxCms.internalUse',  // Engine::LIMIT
            '109 cboxCms.internalUse',  // Engine::class
            '114 cboxCms.internalUse',  // instanceof Engine
            '124 cboxCms.internalUse',  // the global namespace is outside the core
        ], $this->reported('InternalUse'));
    }

    public function test_in_an_addon_only_an_ignore_comment_that_names_the_identifier_hides_a_use(): void
    {
        // Every error on the fixture, PHPStan's own about ignore comments included. A waived use
        // is reported as ignorable and hidden by its comment, which then counts as used; every
        // other use is non-ignorable, and a comment that hides nothing is reported as unmatched.
        $reported = [];

        foreach ($this->gatherAnalyserErrors([self::fixture('InternalUseIgnores')]) as $error) {
            $reported[] = sprintf('%d %s%s', $error->getLine() ?? 0, $error->getIdentifier() ?? '', $error->canBeIgnored() ? ' (ignorable)' : '');
        }

        sort($reported, SORT_NATURAL);

        // Hidden: line 30 (a comment at the end of the line, with a reason), 36 (a comment on the
        // line above), 42 (a doc comment above), both uses on 52 (the identifier named twice)
        // and the use in the trait on 86, reported in the context of TraitUser.
        self::assertSame([
            '47 cboxCms.internalUse',       // two uses, the identifier named once: one is reported
            '57 cboxCms.internalUse',       // the ignore-line form
            '57 ignore.unmatchedLine',
            '63 cboxCms.internalUse',       // the ignore-next-line form on line 62
            '63 ignore.unmatchedLine',
            '68 cboxCms.internalUse',       // the ignore form without an identifier
            '68 ignore.parseError',
            '73 cboxCms.internalUse',       // the ignore form for another identifier
            '73 ignore.unmatchedIdentifier',
            '78 ignore.unmatchedIdentifier', // a waiver with no use on its line
            '91 cboxCms.internalUse',       // a use in the trait with no comment
        ], $reported);
    }

    public function test_the_message_names_the_use_and_the_rule(): void
    {
        $messages = [];

        foreach ($this->gatherAnalyserErrors([self::fixture('InternalUse')]) as $error) {
            if ($error->getIdentifier() === InternalUse::IDENTIFIER) {
                $messages[$error->getLine() ?? 0] = $error->getMessage();
            }
        }

        $rule = ' It is marked #[Internal], and only code in the Cbox\Cms namespace may use it (GUARDRAILS 2.3). Use a #[Stable] or #[Experimental] contract instead.';

        self::assertSame('Call to internal method Cbox\Cms\Core\Fixture\Engine\Registry::flush().'.$rule, $messages[74] ?? null);
        self::assertSame('Call to method run() of internal class Cbox\Cms\Core\Fixture\Engine\Engine.'.$rule, $messages[89] ?? null);
        self::assertSame('Access to internal constant Cbox\Cms\Core\Fixture\Engine\Registry::SECRET.'.$rule, $messages[84] ?? null);
        self::assertSame('Reference to internal class Cbox\Cms\Core\Fixture\Engine\Engine::class.'.$rule, $messages[109] ?? null);
    }

    /**
     * @return Rule<Node>
     */
    protected function getRule(): Rule
    {
        $rules = [];

        foreach (self::getContainer()->getServicesByTag('phpstan.rules.rule') as $rule) {
            if ($rule instanceof Rule) {
                $rules[] = $rule;
            }
        }

        return new RegisteredRules($rules);
    }

    /**
     * @return list<Collector<Node, mixed>>
     */
    protected function getCollectors(): array
    {
        return [new InternalUseCollector];
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__.'/internal-use.neon'];
    }
}
