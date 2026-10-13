<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Codecs\RecordCodecs;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryPreview;
use Cbox\Cms\Core\Entries\Domain\Queries\PreviewEntry;
use Cbox\Cms\Core\Tests\Entries\EntryListingWorld;
use Cbox\Cms\Core\Tests\Entries\EntryReadsActionWorld;
use Cbox\Cms\Core\Tests\Entries\Fakes\FakeEntryPreviews;

/*
 * entry.preview through the query pipeline with fakes (GUARDRAILS 9, PRD 5.4, 8.9, 5.10): every
 * actor may preview the entries its regions reach, and the anonymous principal is unauthorized.
 * The record is the draft, or the revision asked for, written by the workbench's record codecs at
 * the public classification access exactly as the delivery API writes a released row, so an
 * internal reader gets no internal field in it either; the answer carries no content keys, because
 * no cache depends on a preview. An entry out of reach is entry_not_found at `entry`, a revision
 * the variant does not have revision_not_found at `revision`, and one the type cannot read
 * revision_unreadable at `revision`.
 */

/**
 * The record of the content as the delivery API writes it, at the public classification access.
 */
function deliveredRecord(string $entry, string $home, string $title): string
{
    $detail = EntryListingWorld::detail($entry);
    $fields = $title === 'Second, released' ? EntryListingWorld::releasedFields($detail) : $detail->heads[0]->fields;

    return app(RecordCodecs::class)->encode(new ReadContent($detail->id, NodeId::fromString($home), $detail->type, $fields), ClassificationAccess::Public);
}

/**
 * @return list<CatalogError>
 */
function previewErrors(QueryResult $answer): array
{
    return $answer->errors;
}

it('previews the draft of an entry as the delivery API would write it, with the head\'s numbers and state', function (): void {
    $world = new EntryReadsActionWorld;
    $answer = $world->read(new PreviewEntry(EntryId::fromString(EntryListingWorld::SECOND)), $world->reader(ClassificationAccess::Internal));
    $preview = $answer->result;

    expect($preview)->toEqual(new EntryPreview(
        EntryId::fromString(EntryListingWorld::SECOND),
        new TypeName('app:fixture_article'),
        VariantKey::shared(),
        RevisionNumber::first(),
        RevisionNumber::first(),
        new RevisionNumber(2),
        ReleaseState::Released,
        new JsonDocument(deliveredRecord(EntryListingWorld::SECOND, EntryListingWorld::NORTH, 'Second')),
    ))
        ->and($answer->contentKeys)->toBe([]);

    $record = $preview instanceof EntryPreview ? json_decode($preview->data->value, true, flags: JSON_THROW_ON_ERROR) : null;

    expect(is_array($record) ? [$record['fixture_title'] ?? null, array_key_exists('fixture_sources', $record)] : null)->toBe(['Second', false]);
});

it('previews the revision asked for, and refuses one the variant does not have', function (): void {
    $world = new EntryReadsActionWorld;
    $reader = $world->reader(ClassificationAccess::Internal);
    $released = $world->read(new PreviewEntry(EntryId::fromString(EntryListingWorld::SECOND), revision: new RevisionNumber(2)), $reader)->result;
    $missing = $world->read(new PreviewEntry(EntryId::fromString(EntryListingWorld::SECOND), revision: new RevisionNumber(3)), $reader);

    expect($released instanceof EntryPreview ? [$released->revision->value, $released->data->value] : null)
        ->toBe([2, deliveredRecord(EntryListingWorld::SECOND, EntryListingWorld::NORTH, 'Second, released')])
        ->and($missing->isAnswered())->toBeFalse()
        ->and(array_map(static fn (CatalogError $error): array => [$error->code, $error->path?->toString()], previewErrors($missing)))
        ->toBe([[ErrorCode::RevisionNotFound, 'revision']]);
});

it('rejects an entry out of reach or without a head of the variant with entry_not_found, and the anonymous principal with unauthorized', function (): void {
    $world = new EntryReadsActionWorld;
    $reader = $world->reader(ClassificationAccess::Internal);
    $codes = static fn (QueryResult $answer): array => array_map(static fn (CatalogError $error): array => [$error->code, $error->path?->toString()], previewErrors($answer));

    expect($codes($world->read(new PreviewEntry(EntryId::fromString(EntryListingWorld::UNKNOWN)), $reader)))->toBe([[ErrorCode::EntryNotFound, 'entry']])
        ->and($codes($world->read(new PreviewEntry(EntryId::fromString(EntryListingWorld::SECOND), VariantKey::fromString('da')), $reader)))->toBe([[ErrorCode::EntryNotFound, 'entry']])
        ->and($codes($world->read(new PreviewEntry(EntryId::fromString(EntryListingWorld::SECOND)), null)))->toBe([[ErrorCode::Unauthorized, null]]);
});

it('rejects a revision the type cannot read with revision_unreadable and reads once per preview', function (): void {
    $world = new EntryReadsActionWorld;
    $detail = EntryListingWorld::detail(EntryListingWorld::FIRST);
    $previews = new FakeEntryPreviews(EntryListingWorld::ANCESTRY, [NodeId::fromString(EntryListingWorld::NORTH)])
        ->add($detail->id, VariantKey::shared(), $detail->type, $detail->home, new RevisionNumber(2), null, ReleaseState::Unreleased, [1 => null, 2 => $detail->heads[0]->fields]);
    $reader = $world->reader(ClassificationAccess::Internal);
    $old = $world->read(new PreviewEntry($detail->id, revision: RevisionNumber::first()), $reader, $previews);
    $draft = $world->read(new PreviewEntry($detail->id), $reader, $previews)->result;

    expect(array_map(static fn (CatalogError $error): array => [$error->code, $error->path?->toString()], previewErrors($old)))->toBe([[ErrorCode::RevisionUnreadable, 'revision']])
        ->and($draft instanceof EntryPreview ? [$draft->revision->value, $draft->draft->value, $draft->released, $draft->state] : null)->toBe([2, 2, null, ReleaseState::Unreleased])
        ->and($previews->reads)->toBe(2);
});
