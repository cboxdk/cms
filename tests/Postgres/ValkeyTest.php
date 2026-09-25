<?php

declare(strict_types=1);

use Cbox\Cms\Testkit\Valkey\ValkeyRun;
use Illuminate\Support\Facades\Redis;

/*
 * The Valkey service in compose.yaml, reached through Laravel's redis connection. The RealValkey
 * harness points it at the test database index and the run's key prefix.
 */

it('reaches Valkey through the default redis connection', function (): void {
    $connection = Redis::connection();

    expect($connection->command('ping'))->toBeTrue()
        ->and($connection->command('info', ['server']))->toHaveKey('valkey_version');
});

it('sets and reads a key under the run prefix in the test database index', function (): void {
    $run = app(ValkeyRun::class);

    Redis::set('valkey-test', 'written by the Postgres suite');

    expect(Redis::get('valkey-test'))->toBe('written by the Postgres suite')
        ->and($run->keys())->toBe([$run->prefix.'valkey-test'])
        ->and($run->prefix)->toStartWith('cms_test_')
        ->and(config('database.redis.default.database'))->toBe(ValkeyRun::DATABASE);
});
