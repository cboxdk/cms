<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\PublicQuery;
use Examples\Unit\Pipeline\FindNoteTitle;
use Examples\Unit\Pipeline\FindPublishedNote;
use Examples\Unit\Pipeline\MoveNoteAggregates;

// The aggregates name the nodes a command is authorized on, and a public read says so by its type.

it('names the note\'s home and the placement\'s node in its locale, and nothing for a note not found', function (): void {
    $note = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000001');
    $home = NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000a1');
    $placed = NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000a2');

    $found = new MoveNoteAggregates($note, new AggregateVersion(3), $home, $placed, new Locale('da'));
    $missing = new MoveNoteAggregates($note, null, $home, $placed, new Locale('da'));

    expect($found->authorizationTargets())->toEqual([new AuthorizationTarget($home), new AuthorizationTarget($placed, new Locale('da'))])
        ->and($missing->authorizationTargets())->toBe([]);
});

it('marks a read of published content as public, and leaves other reads to the permissions', function (): void {
    $note = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000001');

    expect(new FindPublishedNote($note))->toBeInstanceOf(PublicQuery::class)
        ->and(new FindNoteTitle($note))->not->toBeInstanceOf(PublicQuery::class);
});
