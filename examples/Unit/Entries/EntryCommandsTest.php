<?php

declare(strict_types=1);

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
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry;
use Cbox\Cms\Core\Entries\Domain\Events\EntryCreated;
use Cbox\Cms\Core\Entries\Domain\Events\VariantRevised;

// A surface, a job or a seed writes content with the kernel's two entry commands. The caller makes
// the entry's id, gives the type by its id and the home node the entry lives below, and sends every
// field of the revision; a revise names the version of the shared variant it read.

function exampleTitle(string $title): FieldValues
{
    return new FieldValues(new FieldMap(new NamedValue(new FieldHandle('headline'), new TextValue($title))));
}

it('creates an entry that must not exist yet', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000101');

    $create = new CreateEntry(
        $entry,
        TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000102'),
        NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000103'),
        exampleTitle('Harbour opens'),
    );

    expect($create->expectedVersions()->reads)->toEqual([ReadVersion::absent($entry)])
        ->and(EntryCreated::type()->name)->toBe('entry.created');
});

it('revises the shared variant at the version the caller read', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000101');

    $revise = new ReviseEntry($entry, new AggregateVersion(3), exampleTitle('Harbour opens on Friday'));

    expect($revise->variant())->toEqual(new VariantRef($entry, VariantKey::shared()))
        ->and($revise->expectedVersions()->of($revise->variant()))->toEqual(ReadVersion::at($revise->variant(), new AggregateVersion(3)))
        ->and(VariantRevised::type()->name)->toBe('variant.revised');
});
