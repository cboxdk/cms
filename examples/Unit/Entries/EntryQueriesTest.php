<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Codecs\Boundary\Generated\EntryDetailCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\EntryListCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\GetEntryCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ListEntriesCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryDetail;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryList;
use Cbox\Cms\Core\Entries\Domain\Queries\GetEntry;
use Cbox\Cms\Core\Entries\Domain\Queries\ListEntries;

// The editor opens an entry with entry.get and the content list reads a page with entry.list. A
// caller sends each as JSON, read by the generated codec of its schema, and the surface writes the
// result with the codec of the result's schema, at the reader's classification access.

const EXAMPLE_DETAIL = '{"heads":[{"changed":{"actor":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a45","at":"2026-03-10T13:00:00.000000Z","name":"Ole Frost"},"fields":{"title":"Harbour"},"latest":2,"released":2,"revision":1,"schema_version":1,"stale":false,"state":"released","translated_from":null,"variant":"shared","version":3}],'
    .'"home":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a42","id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a41","lifecycle":"active",'
    .'"placements":[{"id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a44","locales":[{"canonical":true,"locale":"da","slug":"havnen","visibility":"live",'
    .'"window":{"from":"2026-03-10T12:00:00.000000Z","until":null}}],"node":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a42","version":1}],'
    .'"type":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a43","version":2}';

const EXAMPLE_PAGE = '{"entries":[{"changed_at":"2026-03-10T13:00:00.000000Z","fields":{"title":"Harbour"},"home":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a42","id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a41",'
    .'"lifecycle":"active","state":"released","type":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a43","version":2}],"next":null}';

it('reads entry.get from JSON and writes its result with every head, its fields and its placements', function (): void {
    $query = new GetEntryCodecV1()->decode('{"entry":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a41"}', ClassificationAccess::Public);
    $detail = new EntryDetailCodecV1()->decode(EXAMPLE_DETAIL, ClassificationAccess::Internal);

    expect($query)->toBeInstanceOf(GetEntry::class)
        ->and($detail)->toBeInstanceOf(EntryDetail::class)
        ->and($detail->heads[0]->state)->toBe(ReleaseState::Released)
        ->and($detail->heads[0]->latest->next()->value)->toBe(3)
        ->and($detail->heads[0]->changed?->name?->value)->toBe('Ole Frost')
        ->and($detail->placements[0]->locales[0]->slug->value)->toBe('havnen')
        ->and(new EntryDetailCodecV1()->encode($detail, ClassificationAccess::Internal))->toBe(EXAMPLE_DETAIL)
        ->and(static fn (): GetEntry => new GetEntryCodecV1()->decode('{}', ClassificationAccess::Public))->toThrow(DecodingFailed::class);
});

it('reads entry.list with its filters and writes a page of system columns and titles, newest first', function (): void {
    $codec = new ListEntriesCodecV1;
    $query = $codec->decode('{"node":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a42","state":"released","limit":20}', ClassificationAccess::Public);
    $page = new EntryListCodecV1()->decode(EXAMPLE_PAGE, ClassificationAccess::Public);

    expect($query)->toBeInstanceOf(ListEntries::class)
        ->and([$query->type, $query->node?->toString(), $query->state, $query->after, $query->limit])->toEqual([null, '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a42', ReleaseState::Released, null, 20])
        ->and($codec->encode(new ListEntries(node: NodeId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a42'), after: EntryId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a41')), ClassificationAccess::Public))
        ->toBe('{"after":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a41","limit":50,"node":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a42","state":null,"type":null}')
        ->and($page)->toBeInstanceOf(EntryList::class)
        ->and($page->entries[0]->changedAt->format('H:i'))->toBe('13:00')
        ->and(array_map(static fn (FieldHandle $handle): string => $handle->value, $page->entries[0]->fields->own->handles()))->toBe(['title'])
        ->and(new EntryListCodecV1()->encode($page, ClassificationAccess::Public))->toBe(EXAMPLE_PAGE);
});
