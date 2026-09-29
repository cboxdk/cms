<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Results\ReadContent;
use Examples\Unit\Pipeline\NoteCards;

// A result that implements ReadsContent hands its notes to the pipeline and takes them back with
// only their fields changed; each note gives the content keys e-{entry} and n-{node}.

it('gives its notes and holds the notes it is given back, with their content keys', function (): void {
    $note = new ReadContent(
        EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000001'),
        NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000002'),
        TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000003'),
        new FieldValues(new FieldMap(
            new NamedValue(new FieldHandle('title'), new TextValue('Groceries')),
            new NamedValue(new FieldHandle('diagnosis'), new TextValue('Private')),
        )),
    );
    $stripped = $note->withFields(new FieldValues(new FieldMap(new NamedValue(new FieldHandle('title'), new TextValue('Groceries')))));

    $cards = new NoteCards([$note])->withContents([$stripped]);

    expect($cards->contents())->toBe([$stripped])
        ->and($cards->notes[0]->fields->own->handles())->toEqual([new FieldHandle('title')])
        ->and(array_map(static fn (DependencyKey $key): string => $key->toString(), $note->contentKeys()))->toBe([
            'e-01936f5e-8a2b-7c3d-9e4f-000000000001',
            'n-01936f5e-8a2b-7c3d-9e4f-000000000002',
        ]);
});
