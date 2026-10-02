<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\StaticMethodCallablesRule;
use PhpParser\Node;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * StaticMethodCallablesRule alone: a first-class callable of a static method, such as Carbon::now(...),
 * is handed to each of the three rules that check method calls, the system clock's, the UUIDs'
 * and the hooks' IO, so each reports its own.
 *
 * @extends RuleTestCase<Rule<Node>>
 */
final class StaticMethodCallablesRuleTest extends RuleTestCase
{
    use ReportedErrors;

    public function test_it_hands_a_static_method_callable_to_the_clock_the_uuid_and_the_hook_io_rule(): void
    {
        self::assertSame([
            '20 cboxCms.systemClock', // Carbon::now(...)
            '21 cboxCms.uuid',        // Str::uuid(...)
            '29 cboxCms.hookIo',      // DB::select(...)
        ], $this->reported('StaticMethodCallables'));
    }

    /**
     * StaticMethodCallablesRule as hook-io.neon registers it, alone.
     *
     * @return Rule<Node>
     */
    protected function getRule(): Rule
    {
        $rules = [];

        foreach (self::getContainer()->getServicesByTag('phpstan.rules.rule') as $rule) {
            if ($rule instanceof Rule && str_ends_with($rule::class, '\\StaticMethodCallablesRule')) {
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
        return [__DIR__.'/hook-io.neon'];
    }
}
