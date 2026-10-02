<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Publishing;

use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredEntry;
use Cbox\Cms\Core\Publishing\Domain\Dto\UnpublishEntryAggregates;
use Cbox\Cms\Core\Tests\Entries\EntryActionWorld;
use DateTimeImmutable;

/*
 * What entry.unpublish read: the entry and its shared variant, the variant as absent when the
 * entry has no head, and where it is authorized, on the entry's home or, for an entry that read
 * as absent, anywhere.
 */

it('reads the shared variant as absent when the entry has no head, and authorizes on the home or, without the entry, anywhere', function (): void {
    $at = new DateTimeImmutable('2026-10-01T12:00:00Z');
    $headless = new UnpublishEntryAggregates(EntryActionWorld::entry(), new StoredEntry(EntryActionWorld::entry(), EntryActionWorld::type(), EntryActionWorld::home(), new AggregateVersion(2), null), [], $at);
    $absent = new UnpublishEntryAggregates(EntryActionWorld::entry(), null, [], $at);
    $variant = new VariantRef(EntryActionWorld::entry(), VariantKey::shared());

    expect($headless->versions()->of(EntryActionWorld::entry())?->version)->toEqual(new AggregateVersion(2))
        ->and($headless->versions()->of($variant)?->existed())->toBeFalse()
        ->and($absent->versions()->of(EntryActionWorld::entry())?->existed())->toBeFalse()
        ->and($headless->authorizationScope())->toEqual(AuthorizationScope::on(new AuthorizationTarget(EntryActionWorld::home())))
        ->and($absent->authorizationScope()->isAnywhere())->toBeTrue();
});
