<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding;

use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedEntriesAggregates;

/*
 * What seed.entries read: each entry to create and its shared variant as absent, each home node at
 * its version, and authorization on the home nodes that were read, or anywhere when none was.
 */

const SEEDED_ENTRY = '0192a0c0-0000-7000-8000-00000000c0e1';
const SEEDED_HOME = '0192a0c0-0000-7000-8000-00000000c0a1';
const SEEDED_OTHER_HOME = '0192a0c0-0000-7000-8000-00000000c0a2';
const SEEDED_MISSING_HOME = '0192a0c0-0000-7000-8000-00000000c0a3';

it('reads each entry and its shared variant as absent and each home node at its version', function (): void {
    $entry = EntryId::fromString(SEEDED_ENTRY);
    $aggregates = new SeedEntriesAggregates([$entry], [SEEDED_HOME => new AggregateVersion(4), SEEDED_MISSING_HOME => null]);
    $versions = $aggregates->versions();

    expect($versions->of($entry)?->existed())->toBeFalse()
        ->and($versions->of(new VariantRef($entry, VariantKey::shared()))?->existed())->toBeFalse()
        ->and($versions->of(NodeId::fromString(SEEDED_HOME))?->version)->toEqual(new AggregateVersion(4))
        ->and($versions->of(NodeId::fromString(SEEDED_MISSING_HOME))?->existed())->toBeFalse()
        ->and($aggregates->isAbsent($entry))->toBeTrue()
        ->and($aggregates->isAbsent(EntryId::fromString('0192a0c0-0000-7000-8000-00000000c0e2')))->toBeFalse();
});

it('authorizes on every home node that was read and on none that was not, or anywhere when none was read', function (): void {
    $read = new SeedEntriesAggregates([], [SEEDED_HOME => new AggregateVersion(1), SEEDED_MISSING_HOME => null, SEEDED_OTHER_HOME => new AggregateVersion(2)]);
    $unread = new SeedEntriesAggregates([], [SEEDED_MISSING_HOME => null]);

    expect($read->authorizationScope())->toEqual(AuthorizationScope::on(new AuthorizationTarget(NodeId::fromString(SEEDED_HOME)), new AuthorizationTarget(NodeId::fromString(SEEDED_OTHER_HOME))))
        ->and($unread->authorizationScope()->isAnywhere())->toBeTrue()
        ->and(new SeedEntriesAggregates([], [])->authorizationScope()->isAnywhere())->toBeTrue();
});
