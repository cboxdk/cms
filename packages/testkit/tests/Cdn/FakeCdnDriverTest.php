<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Cdn;

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Cdn\CdnPurge;
use Cbox\Cms\Contracts\Cdn\PurgeMode;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Testkit\Cdn\FakeCdnDriver;
use InvalidArgumentException;

/*
 * What the fake CDN driver adds to the shared suite: its settings and the latest mode per key.
 */

it('takes Fastly\'s 256 keys per request and soft purges by default, and refuses fewer than one key per request', function (): void {
    $driver = new FakeCdnDriver;

    expect($driver->maxKeysPerRequest())->toBe(256)
        ->and($driver->supportsSoftPurge())->toBeTrue()
        ->and($driver->driver())->toBe($driver)
        ->and(fn (): FakeCdnDriver => new FakeCdnDriver(maxKeysPerRequest: 0))->toThrow(InvalidArgumentException::class, 'at least one key per request, got 0');
});

it('gives the mode of the latest purge of each key, and null for a key never purged', function (): void {
    $driver = new FakeCdnDriver(softPurge: false);
    $purged = DependencyKey::entry(EntryId::fromString('01960000-0000-7000-8000-00000000000a'));
    $other = DependencyKey::entry(EntryId::fromString('01960000-0000-7000-8000-00000000000b'));

    $driver->purge(new CdnPurge([$purged], PurgeMode::Soft));

    expect($driver->purgedWith($purged))->toBe(PurgeMode::Hard)
        ->and($driver->purgedWith($other))->toBeNull();
});
