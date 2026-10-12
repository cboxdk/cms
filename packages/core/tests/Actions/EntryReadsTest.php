<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Contracts\Pipeline\ReadRejected;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Core\Codecs\Boundary\Generated\EntryDetailCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\EntryListCodecV1;
use Cbox\Cms\Core\Entries\Actions\GetEntryAction;
use Cbox\Cms\Core\Entries\Actions\ListEntriesAction;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryDetail;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryList;
use Cbox\Cms\Core\Entries\Domain\Dto\ListedEntry;
use Cbox\Cms\Core\Entries\Domain\Queries\GetEntry;
use Cbox\Cms\Core\Entries\Domain\Queries\ListEntries;
use Cbox\Cms\Core\Tests\Entries\EntryListingWorld;
use Cbox\Cms\Core\Tests\Entries\EntryReadsActionWorld;
use Cbox\Cms\Core\Tests\Entries\Fakes\FakeEntryListing;
use InvalidArgumentException;

/*
 * entry.get and entry.list through the query pipeline with fakes (GUARDRAILS 9, PRD 5.4, 5.7,
 * 5.10, 12.2): every actor may read the entries its regions reach, with no role naming the reads,
 * and the anonymous principal is refused as unauthorized. The fields of each head are stripped to
 * the reader's classification access, so a public reader gets no internal field, and an entry out
 * of reach is rejected with entry_not_found at `entry`. A page lists newest first with its next id
 * and costs the rows it may return.
 */

/**
 * The handles of the owner's fields of the head, sorted.
 *
 * @return list<string>
 */
function headFieldHandles(EntryDetail $detail): array
{
    $handles = array_map(static fn (NamedValue $field): string => $field->handle->value, $detail->heads[0]->fields->own->fields);
    sort($handles);

    return $handles;
}

it('gives an internal reader the entry with its heads, fields and placements, and leaves the internal fields out for a public one', function (): void {
    $world = new EntryReadsActionWorld;
    $internal = $world->read(new GetEntry(EntryId::fromString(EntryListingWorld::SECOND)), $world->reader(ClassificationAccess::Internal));
    $public = $world->read(new GetEntry(EntryId::fromString(EntryListingWorld::SECOND)), $world->reader(ClassificationAccess::Public));
    $detail = $internal->result;
    $stripped = $public->result;

    expect($detail)->toEqual(EntryListingWorld::detail(EntryListingWorld::SECOND))
        ->and($internal->contentKeys)->toHaveCount(2)
        ->and($detail instanceof EntryDetail ? headFieldHandles($detail) : [])->toContain('fixture_sources', 'fixture_title')
        ->and($stripped instanceof EntryDetail ? headFieldHandles($stripped) : [])->toContain('fixture_title')
        ->and($stripped instanceof EntryDetail ? headFieldHandles($stripped) : [])->not->toContain('fixture_sources')
        ->and($stripped instanceof EntryDetail ? $stripped->heads[0]->state : null)->toBe(ReleaseState::Released)
        ->and($stripped instanceof EntryDetail ? $stripped->placements[0]->locales[0]->slug->value : null)->toBe('anden')
        ->and($detail instanceof EntryDetail ? new EntryDetailCodecV1()->encode($detail, $internal->access) : '')
        ->toContain('"state":"released"', '"variant":"shared"', '"slug":"anden"', '"schema_version":2');
});

it('rejects an entry out of the reader\'s reach, and one that does not exist, with entry_not_found at entry', function (): void {
    $world = new EntryReadsActionWorld;
    $reader = $world->reader(ClassificationAccess::Internal);
    $listing = new FakeEntryListing(EntryListingWorld::ANCESTRY, [NodeId::fromString(EntryListingWorld::NORTH)]);

    foreach (EntryListingWorld::details() as $detail) {
        $listing->add($detail, EntryListingWorld::at(EntryListingWorld::CREATED_AT));
    }

    $outOfReach = new GetEntryAction($listing);
    $unknown = $world->read(new GetEntry(EntryId::fromString(EntryListingWorld::UNKNOWN)), $reader);

    expect(array_map(static fn (CatalogError $error): string => $error->code->value.' at '.$error->path?->toString(), $unknown->errors))->toBe(['entry_not_found at entry'])
        ->and($unknown->result)->toBeNull()
        ->and(static fn (): object => $outOfReach->handle(new GetEntry(EntryId::fromString(EntryListingWorld::THIRD))))->toThrow(ReadRejected::class)
        ->and($outOfReach->cost(new GetEntry(EntryId::fromString(EntryListingWorld::THIRD))))->toEqual(new QueryCost(GetEntryAction::COST));
});

it('lists the entries newest first with their system columns, pages after an entry, and costs the rows it may return', function (): void {
    $world = new EntryReadsActionWorld;
    $reader = $world->reader(ClassificationAccess::Internal);
    $page = $world->read(new ListEntries(limit: 2), $reader);
    $next = $world->read(new ListEntries(after: EntryId::fromString(EntryListingWorld::SECOND), limit: 2), $reader);
    $released = $world->read(new ListEntries(state: ReleaseState::Released), $reader);
    $result = $page->result;

    expect($result)->toEqual(new EntryList(array_slice(EntryListingWorld::listed(), 0, 2), EntryId::fromString(EntryListingWorld::SECOND)))
        ->and($next->result)->toEqual(new EntryList(array_slice(EntryListingWorld::listed(), 2), null))
        ->and($released->result instanceof EntryList ? array_map(static fn (ListedEntry $entry): string => $entry->id->toString(), $released->result->entries) : [])->toBe([EntryListingWorld::SECOND])
        ->and(new ListEntriesAction(EntryListingWorld::listing(EntryListingWorld::ADMIN))->cost(new ListEntries(limit: 7)))->toEqual(new QueryCost(7))
        ->and($result instanceof EntryList ? new EntryListCodecV1()->encode($result, $page->access) : '')
        ->toContain('"changed_at":"2026-03-10T13:00:00.000000Z"', '"state":"released"', sprintf('"next":"%s"', EntryListingWorld::SECOND))
        ->and(static fn (): ListEntries => new ListEntries(limit: 101))->toThrow(InvalidArgumentException::class);
});

it('refuses the anonymous principal as unauthorized', function (): void {
    $world = new EntryReadsActionWorld;
    $detail = $world->read(new GetEntry(EntryId::fromString(EntryListingWorld::SECOND)), null);
    $list = $world->read(new ListEntries, null);

    expect(array_map(static fn (CatalogError $error): ErrorCode => $error->code, [...$detail->errors, ...$list->errors]))->toBe([ErrorCode::Unauthorized, ErrorCode::Unauthorized])
        ->and($world->authorizer->asked)->toHaveCount(2);
});
