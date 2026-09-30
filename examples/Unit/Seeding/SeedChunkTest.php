<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Core\Seeding\Domain\Commands\SeedEntries;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeededEntry;

// cms:seed-scale sends one seed.entries per chunk: each entry with its id, type, home node, the
// fields of its first revision, and whether that revision is released in the same changeset.

it('holds the entries of one chunk, each once', function (): void {
    $home = NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000009a1');
    $type = TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000009d1');
    $fields = new FieldValues(new FieldMap(new NamedValue(new FieldHandle('headline'), new TextValue('Harbour at dawn'))));

    $first = new SeededEntry(EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000009e1'), $type, $home, $fields, release: true);
    $second = new SeededEntry(EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000009e2'), $type, $home, $fields, release: false);
    $chunk = new SeedEntries($first, $second);

    expect($chunk->entries)->toHaveCount(2)
        ->and($chunk->entries[0]->release)->toBeTrue()
        ->and(fn (): SeedEntries => new SeedEntries($first, $first))->toThrow(InvalidArgumentException::class, 'given twice');
});
