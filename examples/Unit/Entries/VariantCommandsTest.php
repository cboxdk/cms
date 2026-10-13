<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateLocalizedEntry;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseEntryVariant;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseVariants;
use Cbox\Cms\Core\Entries\Domain\Dto\LocaleFields;
use Cbox\Cms\Core\Entries\Domain\Dto\VariantRevision;

// Version 2 of the entry commands writes a type whose languages are variants of the entry (PRD
// 10.1, 10.3): a create gives the shared variant's fields and each language's, a revise gives
// every variant it changes at the version the caller read, so one save changes the Danish text
// and a shared field in one changeset, and a release names the variant it releases.

function exampleField(string $handle, string $value): FieldValues
{
    return new FieldValues(new FieldMap(new NamedValue(new FieldHandle($handle), new TextValue($value))));
}

it('creates an entry with its shared variant and a Danish variant, all expected absent', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000111');

    $create = new CreateLocalizedEntry(
        $entry,
        TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000102'),
        NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000103'),
        exampleField('channel', 'web'),
        [new LocaleFields(new Locale('da'), exampleField('headline', 'Havnen åbner'))],
    );

    expect(array_map(static fn (VariantRef $variant): string => $variant->variant->value, $create->variants()))->toBe(['shared', 'da'])
        ->and($create->expectedVersions()->of(new VariantRef($entry, VariantKey::of(new Locale('da')))))->toEqual(ReadVersion::absent(new VariantRef($entry, VariantKey::of(new Locale('da')))));
});

it('revises the Danish variant and the shared variant in one changeset, each at its own version', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000111');
    $da = new VariantRef($entry, VariantKey::of(new Locale('da')));

    $revise = new ReviseVariants($entry, [
        new VariantRevision(VariantKey::of(new Locale('da')), new AggregateVersion(3), exampleField('headline', 'Havnen åbner fredag')),
        new VariantRevision(VariantKey::shared(), new AggregateVersion(1), exampleField('channel', 'print')),
    ]);

    expect($revise->expectedVersions()->of($da))->toEqual(ReadVersion::at($da, new AggregateVersion(3)))
        ->and($revise->expectedVersions()->of(new VariantRef($entry, VariantKey::shared())))->toEqual(ReadVersion::at(new VariantRef($entry, VariantKey::shared()), new AggregateVersion(1)));
});

it('releases a revision of the Danish variant alone', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000111');

    $release = new ReleaseEntryVariant($entry, VariantKey::of(new Locale('da')), new RevisionNumber(4), new AggregateVersion(4));

    expect($release->ref())->toEqual(new VariantRef($entry, VariantKey::of(new Locale('da'))))
        ->and($release->expectedVersions()->of($release->ref()))->toEqual(ReadVersion::at($release->ref(), new AggregateVersion(4)));
});
