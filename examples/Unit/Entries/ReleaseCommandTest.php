<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant;
use Cbox\Cms\Core\Entries\Domain\Events\VariantReleased;
use Cbox\Cms\Core\Entries\Domain\Events\VariantReleasedV1;

// A surface, a job or the scheduler releases a revision with variant.release: the entry, the number
// of the revision to release and the version of the shared variant the caller read. The kernel
// tells subscribers with variant.released, which carries revision numbers and never a field's value.

it('releases a revision of the shared variant at the version the caller read', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000201');

    $release = new ReleaseVariant($entry, new RevisionNumber(4), new AggregateVersion(6));

    expect($release->variant())->toEqual(new VariantRef($entry, VariantKey::shared()))
        ->and($release->expectedVersions()->of($release->variant()))->toEqual(ReadVersion::at($release->variant(), new AggregateVersion(6)))
        ->and(ErrorCode::TypeNotReleasable->value)->toBe('type_not_releasable');
});

it('tells which revision was released, which published revision readers see and which they saw before', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000201');
    $variant = new VariantRef($entry, VariantKey::shared());

    $event = new VariantReleased(7, new VariantReleasedV1($entry, $variant, 4, 5, 2));
    $data = $event->payload()->data();

    expect(VariantReleased::type()->name)->toBe('variant.released')
        ->and($event->aggregate()->version)->toBe(7)
        ->and([$data->get('revision')->asInteger(), $data->get('published')->asInteger(), $data->get('previous')->asInteger()])->toBe([4, 5, 2]);
});
