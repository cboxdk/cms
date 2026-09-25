<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Postgres\Fixtures;

use Cbox\Cms\Testkit\Valkey\ValkeyRun;
use Illuminate\Support\Facades\Redis;

/*
 * Fixture for ValkeyHarnessTest, run in a separate Pest process. It is not a suite file (no
 * Test.php suffix); tests/Pest.php gives it the TestCase, RealPostgres and RealValkey because it
 * sits below packages/testkit/tests/Postgres. The first test leaves a key behind and writes the
 * run prefix to the file named by CMS_TESTKIT_VALKEY_PREFIX_FILE; the second must not see it.
 */

it('fixture: leaves a key behind', function (): void {
    $prefixFile = getenv('CMS_TESTKIT_VALKEY_PREFIX_FILE');

    if (is_string($prefixFile) && $prefixFile !== '') {
        file_put_contents($prefixFile, app(ValkeyRun::class)->prefix);
    }

    Redis::set('left-behind', 'by the first test');

    expect(Redis::get('left-behind'))->toBe('by the first test');
});

it('fixture: does not see the key of the test before', function (): void {
    expect(Redis::get('left-behind'))->toBeNull()
        ->and(app(ValkeyRun::class)->keys())->toBe([]);
});
