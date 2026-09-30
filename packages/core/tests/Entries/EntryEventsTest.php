<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Core\Entries\Domain\Events\EntryCreated;
use Cbox\Cms\Core\Entries\Domain\Events\EntryCreatedV1;
use Cbox\Cms\Core\Entries\Domain\Events\VariantRevised;
use Cbox\Cms\Core\Entries\Domain\Events\VariantRevisedV1;

/*
 * The events of the entry commands (PRD 7.2): entry.created about the entry and variant.revised
 * about the variant, each version 1, carrying ids and revision numbers and never a field's value.
 */

const EVENTS_ENTRY = '01936f5e-8a2b-7c3d-9e4f-0000000006e1';

it('tells that an entry was created, with its type and home', function (): void {
    $entry = EntryId::fromString(EVENTS_ENTRY);
    $event = new EntryCreated(1, new EntryCreatedV1($entry, TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000006d1'), NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000006a1')));
    $data = $event->payload()->data();

    expect(EntryCreated::type())->toEqual(new EventType('entry.created', 1))
        ->and([$event->aggregate()->type->value, $event->aggregate()->id->value, $event->aggregate()->version])->toBe(['entry', EVENTS_ENTRY, 1])
        ->and(array_keys($data->fields()))->toBe(['entry', 'home', 'type'])
        ->and([$data->get('entry')->asIdentifier()->value, $data->get('type')->asIdentifier()->value, $data->get('home')->asIdentifier()->value])
        ->toBe([EVENTS_ENTRY, '01936f5e-8a2b-7c3d-9e4f-0000000006d1', '01936f5e-8a2b-7c3d-9e4f-0000000006a1']);
});

it('tells that a variant moved to a revision, and from which', function (): void {
    $entry = EntryId::fromString(EVENTS_ENTRY);
    $variant = new VariantRef($entry, VariantKey::shared());
    $first = new VariantRevised(1, new VariantRevisedV1($entry, $variant, 1, null));
    $next = new VariantRevised(4, new VariantRevisedV1($entry, $variant, 3, 2));

    expect(VariantRevised::type())->toEqual(new EventType('variant.revised', 1))
        ->and([$next->aggregate()->type->value, $next->aggregate()->id->value, $next->aggregate()->version])->toBe(['variant', EVENTS_ENTRY.':shared', 4])
        ->and($first->payload()->data()->get('previous')->isNull())->toBeTrue()
        ->and([$next->payload()->data()->get('revision')->asInteger(), $next->payload()->data()->get('previous')->asInteger()])->toBe([3, 2])
        ->and($next->payload()->data()->get('variant')->asIdentifier()->value)->toBe(EVENTS_ENTRY.':shared')
        ->and($next->payload()->data()->get('entry')->asIdentifier()->value)->toBe(EVENTS_ENTRY);
});
