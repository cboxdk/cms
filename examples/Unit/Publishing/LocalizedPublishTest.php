<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Core\Publishing\Domain\Commands\PublishLocalizedEntry;
use Cbox\Cms\Core\Publishing\Domain\Dto\VariantRelease;

// Version 2 of entry.publish publishes an entry in one locale: it names the shared variant and the
// variant of the locale, each at the version the caller read with the revision to release, and the
// home placement, so the Danish text, the shared fields and the placement go live in one changeset.

it('publishes the Danish variant and the shared variant with the home placement', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000111');
    $placement = PlacementId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000121');

    $publish = new PublishLocalizedEntry($entry, [
        new VariantRelease(VariantKey::shared(), new AggregateVersion(2), new RevisionNumber(2)),
        new VariantRelease(VariantKey::of(new Locale('da')), new AggregateVersion(3), new RevisionNumber(3)),
    ], $placement, new AggregateVersion(1), new Locale('da'));
    $da = new VariantRef($entry, $publish->localeVariant());

    expect($publish->localeVariant()->value)->toBe('da')
        ->and($publish->expectedVersions()->of($da))->toEqual(ReadVersion::at($da, new AggregateVersion(3)))
        ->and($publish->expectedVersions()->of($placement))->toEqual(ReadVersion::at($placement, new AggregateVersion(1)));
});
