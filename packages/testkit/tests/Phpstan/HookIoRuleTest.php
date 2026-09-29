<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Tests\Phpstan\HookIo\Article;
use GuzzleHttp\Client;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PhpParser\Node;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Symfony\Component\Process\Process;

/**
 * Rule 9, PRD 6.3 and 11.12: a hook does no IO. The hooks of the fixture reach the network, the
 * filesystem, a program, the database, the cache and Redis; a class that is no hook and a hook that
 * reads only its PlanView are not reported. tests/Feature/Tooling/LayerTypeRulesTest.php runs the
 * rule through the real configuration, a trait of a hook included.
 *
 * @extends RuleTestCase<Rule<Node>>
 */
final class HookIoRuleTest extends RuleTestCase
{
    use ReportedErrors;

    public function test_it_reports_io_in_a_hook_as_non_ignorable_and_leaves_other_classes_alone(): void
    {
        // Not reported: NotAHook (lines 73 to 83) does the same IO, and PureHook (lines 86 to 94)
        // names 'file', 'cache' and 'db' as words.
        self::assertSame([
            '31 cboxCms.hookIo',   // file_get_contents()
            '32 cboxCms.hookIo',   // file_get_contents(...), a first-class callable
            '33 cboxCms.hookIo',   // new Client()
            '34 cboxCms.hookIo',   // new Process()
            '35 cboxCms.hookIo',   // SplFileInfo::openFile()
            '43 cboxCms.hookIo',   // a ConnectionInterface in the constructor
            '47 cboxCms.hookIo',   // the connection
            '47 cboxCms.hookIo',   // its query builder
            '48 cboxCms.hookIo',   // DB::table()
            '48 cboxCms.hookIo',   // its query builder
            '49 cboxCms.hookIo',   // an Eloquent model
            '49 cboxCms.hookIo',   // its Eloquent builder
            '61 cboxCms.hookIo',   // Cache::get()
            '62 cboxCms.hookIo',   // cache()
            '63 cboxCms.hookIo',   // Redis::get()
            '64 cboxCms.hookIo',   // $container->make('db')
            '65 cboxCms.hookIo',   // app('redis')
            '66 cboxCms.hookIo',   // array_map('file_get_contents', ...)
            '107 cboxCms.hookIo',  // unlink() in test code
        ], $this->reported('HookIo'));
    }

    public function test_the_message_names_the_hook_what_it_uses_and_the_rule(): void
    {
        $this->analyse([self::fixture('HookIo')], [
            [$this->message('FetchesAndReads', 'the function file_get_contents()'), 31],
            [$this->message('FetchesAndReads', 'the function file_get_contents()'), 32],
            [$this->message('FetchesAndReads', 'the class '.Client::class), 33],
            [$this->message('FetchesAndReads', 'the class '.Process::class), 34],
            [$this->message('FetchesAndReads', 'the method openFile()'), 35],
            [$this->message('QueriesTheDatabase', 'the class '.ConnectionInterface::class), 43],
            [$this->message('QueriesTheDatabase', 'the class '.ConnectionInterface::class), 47],
            [$this->message('QueriesTheDatabase', 'the class '.Builder::class), 47],
            [$this->message('QueriesTheDatabase', 'the class '.DB::class), 48],
            [$this->message('QueriesTheDatabase', 'the class '.Builder::class), 48],
            [$this->message('QueriesTheDatabase', 'the class '.Article::class), 49],
            [$this->message('QueriesTheDatabase', 'the class '.EloquentBuilder::class), 49],
            [$this->message('UsesCacheAndRedis', 'the class '.Cache::class), 61],
            [$this->message('UsesCacheAndRedis', 'the function cache()'), 62],
            [$this->message('UsesCacheAndRedis', 'the class '.Redis::class), 63],
            [$this->message('UsesCacheAndRedis', "the container id 'db'"), 64],
            [$this->message('UsesCacheAndRedis', "the container id 'redis'"), 65],
            [$this->message('UsesCacheAndRedis', 'the function file_get_contents()'), 66],
            [$this->message('Tests\TestHook', 'the function unlink()'), 107],
        ]);
    }

    private function message(string $hook, string $use): string
    {
        return sprintf(
            'Hook Fixture\HookIo\%s uses %s. A hook is deterministic and does no IO: no network, filesystem, process, database, cache or Redis (PRD 6.3, 11.12). It reads what it needs from the PlanView it is given.',
            $hook,
            $use,
        );
    }

    /**
     * The rule and the three rules that hand it first-class callables, from the test container.
     *
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
