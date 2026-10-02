<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\MethodCallablesRule;
use PhpParser\Node;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * MethodCallablesRule alone: a first-class callable of a method, such as $carbon->diffForHumans(...),
 * is handed to each of the three rules that check method calls, the system clock's, the UUIDs'
 * and the hooks' IO, so each reports its own.
 *
 * @extends RuleTestCase<Rule<Node>>
 */
final class MethodCallablesRuleTest extends RuleTestCase
{
    use ReportedErrors;

    public function test_it_hands_a_method_callable_to_the_clock_the_uuid_and_the_hook_io_rule(): void
    {
        self::assertSame([
            '20 cboxCms.systemClock', // $carbon->diffForHumans(...)
            '21 cboxCms.uuid',        // $factory->uuid4(...)
            '31 cboxCms.hookIo',      // ConnectionInterface::select(...) on the connection
        ], $this->reported('MethodCallables'));
    }

    /**
     * MethodCallablesRule as hook-io.neon registers it, alone.
     *
     * @return Rule<Node>
     */
    protected function getRule(): Rule
    {
        $rules = [];

        foreach (self::getContainer()->getServicesByTag('phpstan.rules.rule') as $rule) {
            if ($rule instanceof Rule && str_ends_with($rule::class, '\\MethodCallablesRule')) {
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
