<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads;

use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Core\Reads\Domain\Dto\AuditedRead;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeCardType;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;

/*
 * The fields of a read as the principal may see them (PRD 6.2, 12.2): each classification access
 * keeps the fields at or below it, an undeclared field or extender is left out, an entry of a type
 * the installation does not have keeps nothing, and the sensitive fields left are the ones the read
 * audit names (PRD 12.12).
 */

function readableFields(): ReadableFields
{
    return new ReadableFields(new FakeTypeCatalog(ProbeCardType::definition()));
}

/**
 * @return list<string>
 */
function addresses(ReadContent $content): array
{
    $addresses = array_map(static fn (FieldHandle $handle): string => $handle->value, $content->fields->own->handles());

    foreach ($content->fields->extensions as $extension) {
        foreach ($extension->fields->handles() as $handle) {
            $addresses[] = 'ext.'.$extension->namespace->value.'.'.$handle->value;
        }
    }

    return $addresses;
}

it('keeps the fields at or below each classification access and leaves out what the type does not declare', function (ClassificationAccess $access, array $kept): void {
    expect(addresses(readableFields()->strip(QueryWorld::card(QueryWorld::ENTRIES[0]), $access)))->toBe($kept);
})->with([
    'public' => [ClassificationAccess::Public, ['label', 'ext.probe.tag']],
    'internal' => [ClassificationAccess::Internal, ['label', 'note', 'ext.probe.tag']],
    'confidential' => [ClassificationAccess::Confidential, ['label', 'memo', 'note', 'ext.probe.tag']],
    'personal' => [ClassificationAccess::Personal, ['label', 'memo', 'note', 'ext.probe.tag']],
    'sensitive' => [ClassificationAccess::Sensitive, ['diagnosis', 'label', 'memo', 'note', 'ext.probe.code', 'ext.probe.tag']],
]);

it('keeps the values of the fields it keeps and the entry, node and type', function (): void {
    $card = QueryWorld::card(QueryWorld::ENTRIES[0]);
    $stripped = readableFields()->strip($card, ClassificationAccess::Public);

    expect($stripped->fields->own->get(new FieldHandle('label')))->toEqual(new TextValue('label of '.QueryWorld::ENTRIES[0]))
        ->and($stripped->fields->extension(new FieldNamespace(ProbeCardType::EXTENDER))?->get(new FieldHandle('tag')))->toEqual(new TextValue('tag'))
        ->and($stripped->entry)->toBe($card->entry)
        ->and($stripped->node)->toBe($card->node)
        ->and($stripped->type)->toBe($card->type);
});

it('leaves out an extender whose fields are all left out', function (): void {
    $card = new ReadContent(QueryWorld::card(QueryWorld::ENTRIES[0])->entry, QueryWorld::card(QueryWorld::ENTRIES[0])->node, TypeId::fromString(ProbeCardType::ID), new FieldValues(
        new FieldMap(new NamedValue(new FieldHandle('label'), new TextValue('A'))),
        new ExtensionFields(new FieldNamespace(ProbeCardType::EXTENDER), new FieldMap(new NamedValue(new FieldHandle('code'), new TextValue('B')))),
    ));

    expect(readableFields()->strip($card, ClassificationAccess::Confidential)->fields->extensions)->toBe([]);
});

it('keeps no field of an entry whose type the installation does not have', function (): void {
    $card = QueryWorld::card(QueryWorld::ENTRIES[0]);
    $unknown = new ReadContent($card->entry, $card->node, TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000d9'), $card->fields);

    expect(readableFields()->strip($unknown, ClassificationAccess::Sensitive)->fields->equals(new FieldValues))->toBeTrue()
        ->and(readableFields()->audited($unknown))->toBeNull();
});

it('names the sensitive fields of an entry for the read audit, sorted, and nothing when there is none', function (): void {
    $fields = readableFields();
    $card = QueryWorld::card(QueryWorld::ENTRIES[0]);
    $audited = $fields->audited($fields->strip($card, ClassificationAccess::Sensitive));

    expect($audited)->toBeInstanceOf(AuditedRead::class)
        ->and($audited?->entry)->toBe($card->entry)
        ->and($audited?->fields)->toBe(['diagnosis', 'ext.probe.code'])
        ->and($audited?->classification)->toBe(ClassificationAccess::Sensitive)
        ->and($fields->audited($fields->strip($card, ClassificationAccess::Personal)))->toBeNull();
});
