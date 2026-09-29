<?php

declare(strict_types=1);

// Hooks of each phase that reach the network, the filesystem, a program, the database, the cache
// or Redis, where it is reported, and the same code in a class that is no hook and a hook that
// reads only its PlanView, where it is not.

namespace Fixture\HookIo {
    use Cbox\Cms\Contracts\Hooks\AuthorizeHook;
    use Cbox\Cms\Contracts\Hooks\FieldChanges;
    use Cbox\Cms\Contracts\Hooks\HookDecision;
    use Cbox\Cms\Contracts\Hooks\HookErrors;
    use Cbox\Cms\Contracts\Hooks\PlanView;
    use Cbox\Cms\Contracts\Hooks\TransformHook;
    use Cbox\Cms\Contracts\Hooks\ValidateHook;
    use Cbox\Cms\Testkit\Tests\Phpstan\HookIo\Article;
    use GuzzleHttp\Client;
    use Illuminate\Contracts\Container\Container;
    use Illuminate\Database\ConnectionInterface;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Redis;
    use SplFileInfo;
    use Symfony\Component\Process\Process;

    final readonly class FetchesAndReads implements AuthorizeHook
    {
        public function authorize(PlanView $plan): HookDecision
        {
            $body = file_get_contents('https://example.test/allow');
            $reader = file_get_contents(...);
            new Client();
            new Process(['curl', 'https://example.test']);
            (new SplFileInfo('/tmp/list'))->openFile();

            return $body === '' || $reader === null ? HookDecision::deny('empty') : HookDecision::noObjection();
        }
    }

    final readonly class QueriesTheDatabase implements TransformHook
    {
        public function __construct(private ConnectionInterface $connection) {}

        public function transform(PlanView $plan): FieldChanges
        {
            $this->connection->table('sites')->count();
            DB::table('sites')->count();
            Article::query()->count();

            return new FieldChanges();
        }
    }

    final readonly class UsesCacheAndRedis implements ValidateHook
    {
        public function __construct(private Container $container) {}

        public function validate(PlanView $plan): HookErrors
        {
            Cache::get('seen');
            cache('seen');
            Redis::get('seen');
            $this->container->make('db');
            app('redis');
            array_map('file_get_contents', ['/tmp/a']);

            return new HookErrors();
        }
    }

    // Not reported: no hook.
    final readonly class NotAHook
    {
        public function __construct(private ConnectionInterface $connection) {}

        public function read(): string|false
        {
            $this->connection->table('sites')->count();

            return file_get_contents('/tmp/value');
        }
    }

    // Not reported: reads only its PlanView, and names 'file' and 'cache' as words.
    final readonly class PureHook implements AuthorizeHook
    {
        public function authorize(PlanView $plan): HookDecision
        {
            $words = ['file', 'cache', 'db'];

            return $plan->command->toString() === 'x' ? HookDecision::deny(implode(', ', $words)) : HookDecision::noObjection();
        }
    }
}

namespace Fixture\HookIo\Tests {
    use Cbox\Cms\Contracts\Hooks\AuthorizeHook;
    use Cbox\Cms\Contracts\Hooks\HookDecision;
    use Cbox\Cms\Contracts\Hooks\PlanView;

    // Reported: test code is checked too.
    final readonly class TestHook implements AuthorizeHook
    {
        public function authorize(PlanView $plan): HookDecision
        {
            return file_exists('/tmp/x') && unlink('/tmp/x') ? HookDecision::noObjection() : HookDecision::deny('x');
        }
    }
}
