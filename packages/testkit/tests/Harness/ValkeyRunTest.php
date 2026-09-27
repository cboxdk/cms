<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Harness;

use Cbox\Cms\Testkit\Valkey\Boundary\ValkeySettings;
use Cbox\Cms\Testkit\Valkey\ValkeyConnector;
use Cbox\Cms\Testkit\Valkey\ValkeyRun;
use Cbox\Cms\Testkit\Valkey\ValkeyServiceCheck;
use InvalidArgumentException;
use LogicException;

/*
 * The parts of the Valkey harness that need no service: the run prefix and its SCAN pattern,
 * the settings read from the configuration, and the fail-fast check against a port where nothing
 * listens.
 */

function valkeySettings(int $port = 63797, int $database = ValkeyRun::DATABASE): ValkeySettings
{
    return new ValkeySettings(name: 'default', host: '127.0.0.1', port: $port, database: $database, password: 'secret-password');
}

it('makes a fresh prefix of the form cms_test_<run id>_ for each run', function (): void {
    $first = ValkeyRun::newPrefix();
    $second = ValkeyRun::newPrefix();

    expect($first)->toMatch('/\Acms_test_[0-9a-f]{12}_\z/')
        ->and($second)->toMatch('/\Acms_test_[0-9a-f]{12}_\z/')
        ->and($first)->not->toBe($second)
        ->and(new ValkeyRun(valkeySettings(), $first)->prefix)->toBe($first);
});

it('derives the key outside the prefix from the run id, so two runs never share it', function (string $first, string $second): void {
    $runs = [new ValkeyRun(valkeySettings(), $first), new ValkeyRun(valkeySettings(), $second)];

    expect($runs[0]->outsideKey())->toBe(rtrim($first, '_'))
        ->and($runs[1]->outsideKey())->toBe(rtrim($second, '_'))
        ->and($runs[0]->outsideKey())->not->toBe($runs[1]->outsideKey());

    foreach ($runs as $owner) {
        foreach ($runs as $run) {
            expect(str_starts_with($owner->outsideKey(), $run->prefix))->toBeFalse();
        }
    }
})->with([
    'fixed run ids' => ['cms_test_0123456789ab_', 'cms_test_ba9876543210_'],
    'one run id a prefix of the other' => ['cms_test_ab_', 'cms_test_abc_'],
]);

it('scans only below the prefix, with glob characters escaped', function (): void {
    expect(ValkeyRun::pattern('cms_test_abc_'))->toBe('cms_test_abc_*')
        ->and(ValkeyRun::pattern('a*b?c[d]^e\\f'))->toBe('a\\*b\\?c\\[d\\]\\^e\\\\f*');
});

it('refuses a prefix that could match the keys of other runs or other data', function (string $prefix): void {
    expect(static fn (): ValkeyRun => new ValkeyRun(valkeySettings(), $prefix))
        ->toThrow(InvalidArgumentException::class, 'is not of the form cms_test_<run id>_');
})->with([
    'empty' => [''],
    'the stem alone' => ['cms_test_'],
    'a glob' => ['cms_test_*'],
    'no trailing separator' => ['cms_test_abc'],
    'upper case' => ['cms_test_ABC_'],
    'another stem' => ['laravel_database_'],
]);

it('refuses a connection outside the test database index', function (): void {
    expect(static fn (): ValkeyRun => new ValkeyRun(valkeySettings(database: 0), ValkeyRun::newPrefix()))
        ->toThrow(InvalidArgumentException::class, 'The Redis connection [default] uses database index 0; test runs use only index 15.');
});

it('reads a Redis connection from the configuration, a url included', function (): void {
    config()->set('database.redis.harness_probe', ['host' => 'valkey', 'port' => '6380', 'database' => '15', 'username' => '', 'password' => null]);
    config()->set('database.redis.harness_url', ['url' => 'redis://user:pass@cache.test:6390/15', 'host' => 'ignored', 'port' => 1]);

    $plain = ValkeySettings::of('harness_probe', config());
    $url = ValkeySettings::of('harness_url', config());

    expect([$plain->host, $plain->port, $plain->database, $plain->username, $plain->password])->toBe(['valkey', 6380, 15, null, null])
        ->and([$url->host, $url->port, $url->database, $url->username, $url->password])->toBe(['cache.test', 6390, 15, 'user', 'pass']);
});

it('refuses a Redis connection that is missing or has no host', function (): void {
    config()->set('database.redis.harness_hostless', ['port' => 6379]);

    expect(static fn (): ValkeySettings => ValkeySettings::of('no_such_connection', config()))
        ->toThrow(LogicException::class, 'The Redis connection [no_such_connection] is not configured.')
        ->and(static fn (): ValkeySettings => ValkeySettings::of('harness_hostless', config()))
        ->toThrow(LogicException::class, 'The Redis connection [harness_hostless] has no host.');
});

it('fails within seconds when nothing listens, and points to composer services:up', function (): void {
    $started = hrtime(true);
    $failure = ValkeyServiceCheck::probe(valkeySettings(port: 1));
    $seconds = (hrtime(true) - $started) / 1e9;

    expect($failure)->toBeString()
        ->toContain('The Valkey test service is not reachable at 127.0.0.1:1 (database 15, connection [default]).')
        ->toContain('Start the services with `composer services:up` and run the suite again.')
        ->toContain('Reason: ')
        ->and($failure)->not->toContain('secret-password')
        ->and($seconds)->toBeLessThan(ValkeyConnector::TIMEOUT_SECONDS + 1.0);
});
