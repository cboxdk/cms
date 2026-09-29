<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Postgres;

use Cbox\Cms\Testkit\Postgres\ChildProcesses;
use Cbox\Cms\Testkit\Postgres\ProcessContext;
use Cbox\Cms\Testkit\Valkey\RealValkey;
use Cbox\Cms\Testkit\Valkey\ValkeyConnector;
use Cbox\Cms\Testkit\Valkey\ValkeyHarness;
use Cbox\Cms\Testkit\Valkey\ValkeyRun;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Redis as Client;
use RuntimeException;
use Symfony\Component\Process\Process;

/*
 * The real-Valkey harness (GUARDRAILS 9): every Redis connection uses the test database index and
 * the run's key prefix, and the keys under the prefix are removed with SCAN and UNLINK after each
 * test and when the run ends.
 */

function phpredis(string $connection): Client
{
    $client = Redis::connection($connection);

    if (! $client instanceof PhpRedisConnection) {
        throw new RuntimeException('Expected a phpredis connection.');
    }

    $phpredis = $client->client();

    if (! $phpredis instanceof Client) {
        throw new RuntimeException('Expected a phpredis client.');
    }

    return $phpredis;
}

/**
 * Runs the Valkey fixture in a separate Pest process and returns it after it has exited.
 *
 * @param  array<string, string>  $env
 */
function runValkeyFixture(array $env): Process
{
    $root = dirname(ChildProcesses::autoloader(), 2);
    $run = new Process([PHP_BINARY, $root.'/vendor/bin/pest', __DIR__.'/fixtures/valkey-run.php', '--colors=never'], $root, $env);
    $run->setTimeout(120);
    $run->run();

    return $run;
}

it('gives the run a cms_test_ prefix that every test in the process shares', function (): void {
    $run = app(ValkeyRun::class);

    expect($run->prefix)->toMatch('/\Acms_test_[0-9a-f]{12}_\z/')
        ->and(ValkeyRun::forProcess($run->settings)->prefix)->toBe($run->prefix)
        ->and($run->settings->database)->toBe(ValkeyRun::DATABASE);
});

it('points every Redis connection at the test database index and the run prefix', function (): void {
    $prefix = app(ValkeyRun::class)->prefix;

    foreach (['default', 'cache'] as $connection) {
        $client = phpredis($connection);

        expect($client->getDbNum())->toBe(ValkeyRun::DATABASE)
            ->and($client->getOption(Client::OPT_PREFIX))->toBe($prefix);
    }
});

it('stores what Laravel writes under the run prefix, the cache included', function (): void {
    $run = app(ValkeyRun::class);

    Redis::set('greeting', 'hello');
    Cache::store('redis')->put('answer', 'forty-two', 60);

    $keys = $run->keys();

    expect(Redis::get('greeting'))->toBe('hello')
        ->and(Cache::store('redis')->get('answer'))->toBe('forty-two')
        ->and($keys)->toContain($run->prefix.'greeting')
        ->and($keys)->toHaveCount(2)
        ->and(array_filter($keys, static fn (string $key): bool => ! str_starts_with($key, $run->prefix)))->toBe([]);
});

it('removes every key under the prefix with SCAN and UNLINK, over many SCAN batches, and nothing else', function (): void {
    $run = app(ValkeyRun::class);
    $other = new ValkeyRun($run->settings, ValkeyRun::newPrefix());
    $count = ValkeyRun::SCAN_COUNT * 2 + 500;

    $mine = $run->client();
    $theirs = $other->client();
    $raw = ValkeyConnector::connect($run->settings);
    $outside = $run->outsideKey();

    try {
        $pipe = $mine->multi(Client::PIPELINE);

        for ($i = 0; $i < $count; $i++) {
            $pipe->set('bulk:'.$i, '1');
        }

        $pipe->exec();
        $theirs->set('bulk:0', 'another run');
        $raw->set($outside, 'no prefix');

        expect($run->keys())->toHaveCount($count)
            ->and($run->clean())->toBe($count)
            ->and($run->keys())->toBe([])
            ->and($theirs->get('bulk:0'))->toBe('another run')
            ->and($raw->get($outside))->toBe('no prefix');
    } finally {
        $raw->unlink($outside);
        $other->clean();
        $mine->close();
        $theirs->close();
        $raw->close();
    }

    expect($other->keys())->toBe([]);
});

it('leaves the outside key of another run in Valkey when one run cleans up after itself', function (): void {
    $run = app(ValkeyRun::class);
    $other = new ValkeyRun($run->settings, ValkeyRun::newPrefix());
    $raw = ValkeyConnector::connect($run->settings);

    try {
        $raw->set($other->outsideKey(), 'another run');
        $raw->set($run->outsideKey(), 'this run');

        // What a test of this run does when it ends: remove its outside key and clean its prefix.
        $raw->unlink($run->outsideKey());
        $run->clean();

        expect($raw->get($other->outsideKey()))->toBe('another run')
            ->and($raw->get($run->outsideKey()))->toBeFalse();
    } finally {
        $raw->unlink([$run->outsideKey(), $other->outsideKey()]);
        $raw->close();
    }
});

it('keeps two runs started at the same time apart, and each removes its keys when it ends', function (): void {
    $parent = app(ValkeyRun::class);
    $settings = $parent->settings;
    $release = $parent->prefix.'release';

    Redis::set('shared', 'parent');

    $script = static function (ProcessContext $context) use ($settings, $release): void {
        $run = ValkeyRun::forProcess($settings);
        $client = $run->client();
        $raw = ValkeyConnector::connect($settings);

        if ($client->get('shared') !== false) {
            throw new RuntimeException('The child saw a key it did not write.');
        }

        $client->set('shared', $run->prefix);
        $context->signal('prefix '.$run->prefix);
        $context->signal('written');

        $deadline = hrtime(true) + 10_000_000_000;

        while ($raw->exists($release) !== 1) {
            if (hrtime(true) > $deadline) {
                throw new RuntimeException('The parent did not release the child within 10 s.');
            }

            usleep(2_000);
        }

        if ($client->get('shared') !== $run->prefix || $run->keys() !== [$run->prefix.'shared']) {
            throw new RuntimeException('The child saw keys of another run: '.implode(', ', $run->keys()));
        }

        $client->close();
        $raw->close();
    };

    $processes = app(ChildProcesses::class);
    $children = [$processes->start($script), $processes->start($script)];

    $prefixes = [];

    try {
        foreach ($children as $child) {
            $child->waitForSignal('written');
            $prefix = substr($child->signals()[0], strlen('prefix '));
            $prefixes[] = $prefix;

            expect(new ValkeyRun($settings, $prefix)->keys())->toBe([$prefix.'shared']);
        }

        expect(array_unique([$parent->prefix, ...$prefixes]))->toHaveCount(3)
            ->and(Redis::get('shared'))->toBe('parent')
            ->and($parent->keys())->toBe([$parent->prefix.'shared']);

        Redis::set('release', '1');

        foreach ($children as $child) {
            $child->wait();
        }

        foreach ($prefixes as $prefix) {
            expect(new ValkeyRun($settings, $prefix)->keys())->toBe([]);
        }
    } finally {
        // A child stopped by a failure never reaches its own clean-up at exit.
        foreach ($children as $child) {
            $child->stop();
        }

        foreach ($prefixes as $prefix) {
            new ValkeyRun($settings, $prefix)->clean();
        }
    }
});

it('leaves no key behind after a Pest run, and a test does not see the keys of the test before it', function (): void {
    $prefixFile = (string) tempnam(sys_get_temp_dir(), 'cms-valkey-prefix-');

    try {
        $run = runValkeyFixture(['CMS_TESTKIT_VALKEY_PREFIX_FILE' => $prefixFile]);
        $prefix = (string) file_get_contents($prefixFile);
    } finally {
        unlink($prefixFile);
    }

    expect($run->getExitCode())->toBe(0, $run->getOutput().$run->getErrorOutput())
        ->and($run->getOutput())->toContain('2 passed')
        ->and($prefix)->toStartWith(ValkeyRun::PREFIX_STEM)
        ->and($prefix)->not->toBe(app(ValkeyRun::class)->prefix)
        ->and(new ValkeyRun(app(ValkeyRun::class)->settings, $prefix)->keys())->toBe([]);
});

it('fails every test within seconds and points to composer services:up when Valkey is down', function (): void {
    $started = hrtime(true);
    $run = runValkeyFixture(['REDIS_PORT' => '1', 'CMS_TESTKIT_VALKEY_PREFIX_FILE' => '']);
    $seconds = (hrtime(true) - $started) / 1e9;
    $output = $run->getOutput().$run->getErrorOutput();

    expect($run->getExitCode())->not->toBe(0)
        ->and($output)
        ->toContain('2 failed')
        ->toContain('The Valkey test service is not reachable at')
        ->toContain(':1 (database 15, connection [default])')
        ->toContain('`composer services:up`')
        ->and($seconds)->toBeLessThan(10.0);
});

it('lets a test case name the Redis connection the harness checks and cleans with', function (): void {
    $case = new class('valkey connection probe') extends TestCase
    {
        use RealValkey;

        public function connection(): string
        {
            return $this->valkeyConnection();
        }
    };

    expect($case->connection())->toBe('default');
});

it('finishes a test that built the Redis manager but never opened a connection', function (): void {
    $harness = ValkeyHarness::start(app());
    app(RedisManager::class);

    $harness->finish();

    expect(app()->resolved('redis'))->toBeTrue();
});
