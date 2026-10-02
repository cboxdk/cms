<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\FunctionCallablesRule;
use PhpParser\Node;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * FunctionCallablesRule alone: a first-class callable of a function, such as time(...),
 * is handed to each of the three rules that check method calls, the system clock's, the UUIDs'
 * and the hooks' IO, so each reports its own.
 *
 * @extends RuleTestCase<Rule<Node>>
 */
final class FunctionCallablesRuleTest extends RuleTestCase
{
    use ReportedErrors;

    public function test_it_hands_a_function_callable_to_the_clock_the_uuid_and_the_hook_io_rule(): void
    {
        self::assertSame([
            '17 cboxCms.systemClock', // time(...)
            '18 cboxCms.uuid',        // uuid_create(...)
            '26 cboxCms.hookIo',      // file_get_contents(...)
        ], $this->reported('FunctionCallables'));
    }

    /**
     * FunctionCallablesRule as hook-io.neon registers it, alone.
     *
     * @return Rule<Node>
     */
    protected function getRule(): Rule
    {
        $rules = [];

        foreach (self::getContainer()->getServicesByTag('phpstan.rules.rule') as $rule) {
            if ($rule instanceof Rule && str_ends_with($rule::class, '\\FunctionCallablesRule')) {
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
