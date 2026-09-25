<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\InternalUse;
use PhpParser\Node;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Rule 5, GUARDRAILS 2.3: code outside Cbox\Cms may not use what is marked #[Internal]. The
 * test container registers the three extensions as the testkit neon does, and RegisteredRules
 * runs every rule of the container, since PHPStan's own rules call the extensions.
 *
 * @extends RuleTestCase<Rule<Node>>
 */
final class InternalUseTest extends RuleTestCase
{
    use ReportedErrors;

    public function test_an_addon_may_not_use_internal_classes_methods_or_constants_but_the_core_may(): void
    {
        // Nothing in Cbox\Cms\Core\Fixture\Engine (lines 7 to 59) is reported, and line 69
        // uses only stable members of the stable Registry. Each use is reported once. PHPStan's
        // restricted usage rules build the errors, so they are ignorable; an ignore comment
        // outside Boundary and Adapter is still reported by cboxCms.phpstanIgnore.
        self::assertSame([
            '74 cboxCms.internalUse (ignorable)',   // $registry->flush(), an #[Internal] method
            '79 cboxCms.internalUse (ignorable)',   // Registry::reset(), an #[Internal] static method
            '84 cboxCms.internalUse (ignorable)',   // Registry::SECRET, an #[Internal] constant
            '87 cboxCms.internalUse (ignorable)',   // parameter type Engine
            '89 cboxCms.internalUse (ignorable)',   // $engine->run(), a method of an #[Internal] class
            '92 cboxCms.internalUse (ignorable)',   // return type Engine
            '94 cboxCms.internalUse (ignorable)',   // new Engine
            '99 cboxCms.internalUse (ignorable)',   // Engine::boot()
            '104 cboxCms.internalUse (ignorable)',  // Engine::LIMIT
            '109 cboxCms.internalUse (ignorable)',  // Engine::class
            '114 cboxCms.internalUse (ignorable)',  // instanceof Engine
            '124 cboxCms.internalUse (ignorable)',  // the global namespace is outside the core
        ], $this->reported('InternalUse'));
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
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__.'/internal-use.neon'];
    }
}
