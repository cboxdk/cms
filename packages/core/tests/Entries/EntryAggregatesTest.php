<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Core\Entries\Domain\Dto\CreateEntryAggregates;
use Cbox\Cms\Core\Entries\Domain\Dto\ReleaseVariantAggregates;
use Cbox\Cms\Core\Entries\Domain\Dto\ReviseEntryAggregates;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredEntry;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;

/*
 * What entry.create and entry.revise read: the entry and its shared variant at their versions, and where the
 * command is authorized, on the entry's home or, for an entry that read as absent, anywhere.
 */

it('reads the entry and its shared variant at their versions, the variant as absent when the entry has no head', function (): void {
    $head = new StoredHead(new AggregateVersion(5), new RevisionNumber(2), new RevisionNumber(2), null);
    $withHead = new ReviseEntryAggregates(EntryActionWorld::entry(), new StoredEntry(EntryActionWorld::entry(), EntryActionWorld::type(), EntryActionWorld::home(), new AggregateVersion(3), $head));
    $withoutHead = new ReviseEntryAggregates(EntryActionWorld::entry(), new StoredEntry(EntryActionWorld::entry(), EntryActionWorld::type(), EntryActionWorld::home(), new AggregateVersion(3), null));
    $variant = new VariantRef(EntryActionWorld::entry(), VariantKey::shared());

    expect($withHead->versions()->of(EntryActionWorld::entry())?->version)->toEqual(new AggregateVersion(3))
        ->and($withHead->versions()->of($variant)?->version)->toEqual(new AggregateVersion(5))
        ->and($withoutHead->versions()->of($variant)?->version)->toBeNull()
        ->and($withoutHead->versions()->of($variant)?->existed())->toBeFalse()
        ->and(new ReviseEntryAggregates(EntryActionWorld::entry(), null)->versions()->of(EntryActionWorld::entry())?->existed())->toBeFalse();
});

it('authorizes on the entry\'s home in every locale, and anywhere for an entry that read as absent', function (): void {
    $stored = new ReviseEntryAggregates(EntryActionWorld::entry(), new StoredEntry(EntryActionWorld::entry(), EntryActionWorld::type(), EntryActionWorld::home(), new AggregateVersion(1), null));

    expect($stored->authorizationScope())->toEqual(AuthorizationScope::on(new AuthorizationTarget(EntryActionWorld::home())))
        ->and($stored->authorizationScope()->isAnywhere())->toBeFalse()
        ->and(new ReviseEntryAggregates(EntryActionWorld::entry(), null)->authorizationScope()->isAnywhere())->toBeTrue();
});

it('authorizes entry.create on its home node when the node was read, and anywhere when it read as absent', function (): void {
    $read = new CreateEntryAggregates(EntryActionWorld::entry(), null, EntryActionWorld::home(), new AggregateVersion(2));
    $absent = new CreateEntryAggregates(EntryActionWorld::entry(), null, EntryActionWorld::home(), null);

    expect($read->authorizationScope())->toEqual(AuthorizationScope::on(new AuthorizationTarget(EntryActionWorld::home())))
        ->and($read->authorizationScope()->isAnywhere())->toBeFalse()
        ->and($absent->authorizationScope()->isAnywhere())->toBeTrue();
});

it('reads what variant.release read the same way: the variant as absent without a head, and anywhere without the entry', function (): void {
    $headless = new ReleaseVariantAggregates(EntryActionWorld::entry(), new StoredEntry(EntryActionWorld::entry(), EntryActionWorld::type(), EntryActionWorld::home(), new AggregateVersion(4), null));
    $variant = new VariantRef(EntryActionWorld::entry(), VariantKey::shared());

    expect($headless->versions()->of(EntryActionWorld::entry())?->version)->toEqual(new AggregateVersion(4))
        ->and($headless->versions()->of($variant)?->existed())->toBeFalse()
        ->and($headless->authorizationScope())->toEqual(AuthorizationScope::on(new AuthorizationTarget(EntryActionWorld::home())))
        ->and(new ReleaseVariantAggregates(EntryActionWorld::entry(), null)->authorizationScope()->isAnywhere())->toBeTrue();
});
