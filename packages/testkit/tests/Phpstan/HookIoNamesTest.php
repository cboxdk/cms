<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use PhpParser\Node;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Rule 9 name by name: every class, namespace, function, function family and container id that
 * HookIoRule lists, and every way the rule reads one, is reported in a hook, so a name taken off
 * a list, or a way of reading one left out, shows here. HookIoRuleTest covers what the rule
 * leaves alone.
 *
 * @extends RuleTestCase<Rule<Node>>
 */
final class HookIoNamesTest extends RuleTestCase
{
    use ReportedErrors;

    public function test_it_reports_every_listed_name_in_a_hook(): void
    {
        self::assertSame($this->expected(), $this->messages());
    }

    /**
     * Each error of the testkit's rules as "line: what the hook uses".
     *
     * @return list<string>
     */
    private function messages(): array
    {
        $reported = [];

        foreach ($this->gatherAnalyserErrors([self::fixture('HookIoNames')]) as $error) {
            if (($error->getIdentifier() ?? '') === 'cboxCms.hookIo' && preg_match('/ uses (.+)\. A hook is deterministic/', $error->getMessage(), $use) === 1) {
                $reported[] = sprintf('%d: %s', $error->getLine() ?? 0, $use[1]);
            }
        }

        sort($reported, SORT_NATURAL);

        return $reported;
    }

    /**
     * @return list<string>
     */
    private function expected(): array
    {
        return [
            '18: the class PDO',
            '20: the class Redis',
            '26: the class Doctrine\\DBAL\\Connection',
            '27: the class Illuminate\\Cache\\Repository',
            '28: the class Illuminate\\Contracts\\Cache\\Repository',
            '29: the class Illuminate\\Contracts\\Database\\Query\\Builder',
            '30: the class Illuminate\\Contracts\\Redis\\Factory',
            '31: the class Illuminate\\Database\\Connection',
            '32: the class Illuminate\\Redis\\RedisManager',
            '33: the class Illuminate\\Support\\Facades\\Cache',
            '34: the class Illuminate\\Support\\Facades\\DB',
            '35: the class Illuminate\\Support\\Facades\\Redis',
            '36: the class Illuminate\\Support\\Facades\\Schema',
            '37: the class Memcached',
            '38: the class mysqli',
            '39: the class PDO',
            '40: the class PDOStatement',
            '41: the class Predis\\Client',
            '42: the class Redis',
            '43: the class RedisCluster',
            '44: the class SQLite3',
            '45: the class PDO',
            '46: the class Illuminate\\Support\\Facades\\DB',
            "47: the container id 'cache'",
            "48: the container id 'cache.store'",
            "49: the container id 'db'",
            "50: the container id 'db.connection'",
            "51: the container id 'db.factory'",
            "52: the container id 'db.schema'",
            "53: the container id 'redis'",
            "54: the container id 'redis.connection'",
            "55: the container id 'db'",
            "56: the container id 'db'",
            "57: the container id 'redis'",
            "58: the container id 'cache'",
            "59: the container id 'db.schema'",
            "60: the container id 'redis'",
            "61: the container id 'db'",
            '62: the function apcu_fetch()',
            '63: the function mysqli_connect()',
            '64: the function pg_query()',
            '65: the function sqlite_open()',
            '66: the function pg_query()',
            '67: the function pg_escape_string()',
            '69: the function pg_query()',
            '70: the class Redis',
            '70: the class Redis',
            '73: the function \\PG_QUERY()',
            '74: the class mysqli',
            "74: the container id 'db'",
            "75: the container id 'files'",
            '76: the function file_get_contents()',
            '81: the class PDO',
            '84: the class PDO',
            '84: the class Redis',
            '87: the class PDO',
            '87: the class SQLite3',
        ];
    }

    /**
     * @return Rule<Node>
     */
    protected function getRule(): Rule
    {
        $rules = [];

        foreach (self::getContainer()->getServicesByTag('phpstan.rules.rule') as $rule) {
            if ($rule instanceof Rule && str_starts_with($rule::class, 'Cbox\\Cms\\Testkit\\Phpstan\\')) {
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
