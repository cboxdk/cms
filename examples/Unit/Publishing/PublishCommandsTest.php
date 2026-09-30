<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Core\Publishing\Domain\Commands\PublishEntry;
use Cbox\Cms\Core\Publishing\Domain\Commands\UnpublishEntry;

// The panel's primary button publishes with entry.publish: the entry, the version of its shared
// variant, the revision to release, its home placement at the version read, the locale and, to
// schedule it, a window. entry.unpublish takes it back with the entry and its variant's version.

it('publishes a revision and the home placement now, or in a window', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000301');
    $home = PlacementId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000351');

    $now = new PublishEntry($entry, new AggregateVersion(6), new RevisionNumber(4), $home, new AggregateVersion(2), new Locale('da'));
    $later = new PublishEntry($entry, new AggregateVersion(6), new RevisionNumber(4), $home, new AggregateVersion(2), new Locale('da'), new TimeWindow(new DateTimeImmutable('2026-03-11T06:00:00Z')));

    expect($now->window)->toBeNull()
        ->and($later->window?->from?->format('Y-m-d H:i'))->toBe('2026-03-11 06:00')
        ->and($now->expectedVersions()->of($home))->toEqual(ReadVersion::at($home, new AggregateVersion(2)))
        ->and($now->expectedVersions()->of($now->variant()))->toEqual(ReadVersion::at($now->variant(), new AggregateVersion(6)));
});

it('unpublishes the entry at the version of its shared variant the caller read', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000301');

    $unpublish = new UnpublishEntry($entry, new AggregateVersion(7));

    expect($unpublish->variant())->toEqual(new VariantRef($entry, VariantKey::shared()))
        ->and($unpublish->expectedVersions()->of($unpublish->variant()))->toEqual(ReadVersion::at($unpublish->variant(), new AggregateVersion(7)));
});
